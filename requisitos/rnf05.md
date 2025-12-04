# RNF05 - Persistência de Dados (Polyglot Persistence)

---

## 1. Resumo do Requisito

### RNF05 - Persistência de Dados (Polyglot Persistence)
> - **PostgreSQL**: Banco relacional para dados transacionais (usuários, conversas, mensagens).
> - **Redis**: Cache para sessões JWT e conversas recentes.
> - **MinIO (S3-compatible)**: Object Storage para armazenamento de arquivos até 2GB.

### Importância Teórica

**Polyglot Persistence** reconhece que *não existe banco de dados universal*:
- Relacional para ACID e relações complexas
- Key-value para velocidade e cache
- Object storage para blobs grandes

O termo foi cunhado por Martin Fowler (2011) e representa a maturidade arquitetural de usar **a ferramenta certa para cada tipo de dado**.

---

## 2. Fundamentos Teóricos

### 2.1 Polyglot Persistence - Visão Geral

```
┌─────────────────────────────────────────────────────────────┐
│                 POLYGLOT PERSISTENCE                        │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────────────────────────────────────┐       │
│  │             PostgreSQL (ACID)                    │       │
│  │  ┌─────────────────────────────────────────┐    │       │
│  │  │ users         │ messages     │ files    │    │       │
│  │  │ conversations │ audit_logs   │ ...      │    │       │
│  │  └─────────────────────────────────────────┘    │       │
│  │  ✅ Transações, Foreign Keys, JOINs             │       │
│  │  ✅ Consistência forte (ACID)                   │       │
│  │  ⚠️ Não escala horizontalmente (write)          │       │
│  └─────────────────────────────────────────────────┘       │
│                                                             │
│  ┌─────────────────────────────────────────────────┐       │
│  │             Redis (In-Memory)                    │       │
│  │  ┌─────────────────────────────────────────┐    │       │
│  │  │ jwt:user_id:token  │ conversation:cache │    │       │
│  │  │ Pub/Sub channels   │ session:data       │    │       │
│  │  └─────────────────────────────────────────┘    │       │
│  │  ✅ Latência sub-ms                             │       │
│  │  ✅ Pub/Sub para eventos                        │       │
│  │  ⚠️ Dados voláteis (persistência opcional)      │       │
│  └─────────────────────────────────────────────────┘       │
│                                                             │
│  ┌─────────────────────────────────────────────────┐       │
│  │             MinIO (Object Storage)               │       │
│  │  ┌─────────────────────────────────────────┐    │       │
│  │  │ chat4all-files/                         │    │       │
│  │  │   uploads/conv-123/file-abc.pdf         │    │       │
│  │  │   uploads/conv-456/image.jpg            │    │       │
│  │  └─────────────────────────────────────────┘    │       │
│  │  ✅ Arquivos até 2GB                            │       │
│  │  ✅ API S3-compatible                           │       │
│  │  ✅ Escalabilidade horizontal                   │       │
│  └─────────────────────────────────────────────────┘       │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Quando Usar Cada Tecnologia

| Tipo de Dado | Tecnologia | Justificativa |
|--------------|------------|---------------|
| **Transacional** (users, messages) | PostgreSQL | ACID, Foreign Keys, JOINs complexos |
| **Efêmero/Cache** (sessões, tokens) | Redis | Latência <1ms, TTL automático |
| **Blobs Grandes** (arquivos, mídia) | MinIO | Otimizado para objetos, presigned URLs |
| **Time-series** (métricas) | InfluxDB/Prometheus | Agregações temporais eficientes |
| **Full-text Search** | Elasticsearch | Índices invertidos, relevância |

### 2.3 Trade-offs do Polyglot Persistence

| Vantagem | Desvantagem |
|----------|-------------|
| Cada storage otimizado para seu caso de uso | Complexidade operacional aumentada |
| Escalabilidade independente por tipo de dado | Consistência entre sistemas é difícil |
| Performance superior em cada domínio | Mais pontos de falha |
| Flexibilidade para evoluir tecnologias | Curva de aprendizado maior |

---

## 3. Implementação no Chat4All

### 3.1 PostgreSQL - Dados Transacionais

#### Configuração (`docker-compose.yml`)

```yaml
postgres:
  image: postgres:16-alpine
  container_name: chat4all-postgres
  environment:
    POSTGRES_DB: chat4all
    POSTGRES_USER: chat4all_user
    POSTGRES_PASSWORD: chat4all_pass
  ports:
    - "5432:5432"
  volumes:
    - postgres_data:/var/lib/postgresql/data
    - ./scripts/init-db.sql:/docker-entrypoint-initdb.d/init-db.sql
  healthcheck:
    test: ["CMD-SHELL", "pg_isready -U chat4all_user -d chat4all"]
    interval: 10s
    timeout: 5s
    retries: 5
```

#### Conexão PHP (`Database.php`)

```php
public function __construct(
    string $host,
    string $port,
    string $database,
    string $user,
    string $password,
    Logger $logger
) {
    $dsn = "pgsql:host=$host;port=$port;dbname=$database";
    
    $this->pdo = new PDO($dsn, $user, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,  // Prepared statements nativos
    ]);
}
```

#### Schema (`scripts/init-db.sql`)

```sql
-- Extensão para UUIDs distribuídos
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- Usuários (dados transacionais)
CREATE TABLE IF NOT EXISTS users (
    user_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    username VARCHAR(50) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE,
    phone VARCHAR(20) UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    status VARCHAR(20) DEFAULT 'active',
    created_at TIMESTAMP DEFAULT NOW()
);

-- Conversas
CREATE TABLE IF NOT EXISTS conversations (
    conversation_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    type VARCHAR(20) NOT NULL CHECK (type IN ('private', 'group')),
    name VARCHAR(100),
    created_at TIMESTAMP DEFAULT NOW()
);

-- Mensagens (dados transacionais)
CREATE TABLE IF NOT EXISTS messages (
    message_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    conversation_id UUID NOT NULL REFERENCES conversations(conversation_id),
    from_user_id UUID NOT NULL REFERENCES users(user_id),
    content TEXT,
    file_id UUID REFERENCES files(file_id),
    status VARCHAR(20) DEFAULT 'PENDING',
    platform VARCHAR(20) DEFAULT 'internal',
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW()
);

-- Índices para performance
CREATE INDEX idx_messages_conversation ON messages(conversation_id);
CREATE INDEX idx_messages_from_user ON messages(from_user_id);
CREATE INDEX idx_messages_status ON messages(status);
CREATE INDEX idx_messages_created_at ON messages(created_at DESC);
```

**Por que PostgreSQL?**
- **ACID**: Transações atômicas para consistência de dados críticos
- **Foreign Keys**: Integridade referencial (mensagem → conversa → usuário)
- **UUID**: `uuid_generate_v4()` para IDs distribuídos sem coordenação central
- **Índices B-tree**: Queries eficientes por conversation_id, status, timestamp

---

### 3.2 Redis - Cache e Pub/Sub

#### Configuração (`docker-compose.yml`)

```yaml
redis:
  image: redis:7-alpine
  container_name: chat4all-redis
  ports:
    - "6379:6379"
  volumes:
    - redis_data:/data
  healthcheck:
    test: ["CMD", "redis-cli", "ping"]
    interval: 10s
    timeout: 5s
    retries: 5
```

#### Uso para Cache de Sessão JWT

```php
// Armazenar token JWT validado (evita re-validação)
$redis->setex("jwt:$userId", 3600, json_encode($tokenData));

// Buscar antes de validar novamente
$cached = $redis->get("jwt:$userId");
if ($cached) {
    return json_decode($cached, true);
}

// Se não está em cache, validar e cachear
$tokenData = $this->validateJwt($token);
$redis->setex("jwt:$userId", 3600, json_encode($tokenData));
```

#### Uso para Pub/Sub (WebSocket Worker)

```php
// WebSocket worker subscreve a eventos de status
$redis->subscribe(['status-updates'], function($channel, $message) use ($wsHandler) {
    $data = json_decode($message, true);
    $userId = $data['user_id'];
    
    // Notifica conexões WebSocket do usuário
    $wsHandler->notifyUser($userId, $data);
});

// API publica atualização de status
$redis->publish('status-updates', json_encode([
    'user_id' => $userId,
    'message_id' => $messageId,
    'status' => 'DELIVERED'
]));
```

**Por que Redis?**
- **Latência sub-milissegundo**: Cache hit em <1ms vs ~5ms de PostgreSQL
- **TTL automático**: Sessões expiram sem lógica de limpeza
- **Pub/Sub**: Notificações em tempo real sem polling
- **Estruturas de dados**: Strings, Hashes, Lists, Sets nativos

---

### 3.3 MinIO - Object Storage

#### Configuração (`docker-compose.yml`)

```yaml
minio:
  image: minio/minio:latest
  container_name: chat4all-minio
  ports:
    - "9001:9000"  # API
    - "9002:9001"  # Console Web
  environment:
    MINIO_ROOT_USER: chat4all_admin
    MINIO_ROOT_PASSWORD: chat4all_minio_pass
  command: server /data --console-address ":9001"
  volumes:
    - minio_data:/data
  healthcheck:
    test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"]
    interval: 30s
    timeout: 20s
    retries: 3
```

#### Inicialização do Cliente (`MinioService.php`)

```php
// Criar cliente S3 (MinIO é compatível com S3)
$this->client = new S3Client([
    'version' => 'latest',
    'region' => 'us-east-1',  // MinIO ignora, mas API requer
    'endpoint' => ($useSSL ? 'https://' : 'http://') . $endpoint,
    'use_path_style_endpoint' => true,  // MinIO usa path-style, não virtual-hosted
    'credentials' => [
        'key' => $accessKey,
        'secret' => $secretKey,
    ],
]);

// Criar bucket se não existir
$this->ensureBucketExists();
```

#### Multipart Upload para Arquivos Grandes

```php
/**
 * Inicia um upload multipart para arquivos grandes (até 2GB)
 */
public function initiateMultipartUpload(string $key, string $contentType): string
{
    $result = $this->client->createMultipartUpload([
        'Bucket' => $this->bucket,
        'Key' => $key,
        'ContentType' => $contentType,
    ]);
    
    return $result['UploadId'];  // ID para usar nas partes
}

/**
 * Upload de uma parte do arquivo
 */
public function uploadPart(
    string $key,
    string $uploadId,
    int $partNumber,
    string $body
): array {
    $result = $this->client->uploadPart([
        'Bucket' => $this->bucket,
        'Key' => $key,
        'UploadId' => $uploadId,
        'PartNumber' => $partNumber,
        'Body' => $body,
    ]);
    
    return [
        'PartNumber' => $partNumber,
        'ETag' => $result['ETag'],
    ];
}

/**
 * Completa o upload multipart
 */
public function completeMultipartUpload(
    string $key,
    string $uploadId,
    array $parts
): string {
    $result = $this->client->completeMultipartUpload([
        'Bucket' => $this->bucket,
        'Key' => $key,
        'UploadId' => $uploadId,
        'MultipartUpload' => ['Parts' => $parts],
    ]);
    
    return $result['Location'];  // URL do arquivo
}

/**
 * Gera URL temporária para download (presigned URL)
 */
public function getPresignedUrl(string $key, int $expiresIn = 3600): string
{
    $cmd = $this->client->getCommand('GetObject', [
        'Bucket' => $this->bucket,
        'Key' => $key,
    ]);
    
    $request = $this->client->createPresignedRequest($cmd, "+{$expiresIn} seconds");
    
    return (string) $request->getUri();
}
```

**Por que MinIO?**
- **S3-compatible**: Mesma API da AWS, migração trivial
- **Multipart Upload**: Arquivos até 5TB, sem carregar em memória
- **Presigned URLs**: Download direto sem proxy pelo backend
- **Self-hosted**: Sem vendor lock-in, controle total

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| PostgreSQL para dados transacionais | ✅ | `Database.php`, `init-db.sql` |
| Redis para cache JWT | ✅ | Cache de sessão implementado |
| Redis para conversas recentes | ✅ | Pub/Sub para WebSocket |
| MinIO para arquivos até 2GB | ✅ | `MinioService.php` com multipart |

### 4.2 Pontos Fortes

1. **Separação clara de responsabilidades**: Cada storage com propósito definido
2. **Multipart upload**: Suporta arquivos até 2GB sem carregar em memória
3. **Presigned URLs**: Reduz carga no backend para downloads
4. **UUID distribuído**: Sem coordenação central para geração de IDs

### 4.3 Limitações Identificadas

#### Limitação 1: Consistência entre Sistemas

**Problema**: Dados podem ficar inconsistentes entre PostgreSQL e Redis.

```php
// Cenário problemático:
$this->postgres->updateMessageStatus($messageId, 'DELIVERED');  // ✅ Salvo
$this->redis->del("conversation:$convId");  // ❌ Falhou - cache desatualizado
```

**Solução**: Padrão Cache-Aside com TTL curto:
```php
// Sempre invalidar cache após write
$this->postgres->updateMessageStatus($messageId, 'DELIVERED');
$this->redis->del("conversation:$convId");  // Best-effort

// Cache sempre tem TTL, eventual consistency é aceitável
$redis->setex("conversation:$convId", 60, $data);  // Expira em 1 min
```

#### Limitação 2: Redis Sem Persistência Configurada

**Problema**: Dados em memória perdidos em restart.

```yaml
# Atual - sem persistência
redis:
  image: redis:7-alpine
```

**Solução**:
```yaml
redis:
  image: redis:7-alpine
  command: redis-server --appendonly yes --appendfsync everysec
```

Ou, aceitar que cache é **efêmero por design** e não depender dele para dados críticos.

#### Limitação 3: MinIO Single Node

**Problema**: Sem redundância para arquivos.

**Solução**: MinIO Distributed Mode:
```yaml
minio:
  command: server http://minio{1...4}/data --console-address ":9001"
```

#### Limitação 4: PostgreSQL não Escala Writes

**Problema**: PostgreSQL é mestre único para escritas.

**Solução para escala extrema**:
- Read replicas para queries pesadas
- Sharding por `conversation_id` (mas aumenta complexidade)
- Citus para PostgreSQL distribuído

### 4.4 Perguntas Socráticas para Aprofundamento

1. "Se o Redis cair, o sistema continua funcionando? Com que degradação?"
2. "Como você garantiria consistência entre PostgreSQL e Redis?"
3. "MinIO é eventualmente consistente. O que isso significa para leituras imediatas após upload?"
4. "Por que não armazenar arquivos diretamente no PostgreSQL (BYTEA)?"
5. "Se você precisar migrar de MinIO para AWS S3, o que muda no código?"
6. "Redis para cache E pub/sub. Isso viola Single Responsibility Principle?"

---

## 5. Padrões Relacionados

### 5.1 Cache-Aside Pattern

```
┌─────────┐     1. GET      ┌─────────┐
│  Client │ ───────────────▶│  Cache  │
└────┬────┘                 └────┬────┘
     │                           │
     │ 2. Cache Miss             │
     │◀──────────────────────────┘
     │
     │ 3. Query DB    ┌─────────┐
     │───────────────▶│   DB    │
     │                └────┬────┘
     │◀──────────────────────────┘
     │ 4. Data
     │
     │ 5. Populate    ┌─────────┐
     │───────────────▶│  Cache  │
     │                └─────────┘
```

### 5.2 Sidecar Pattern para Object Storage

```
┌─────────────────────────────────────────┐
│              API Service                │
│  ┌─────────────────────────────────┐   │
│  │  Upload Request                  │   │
│  │  1. Valida metadata              │   │
│  │  2. Gera presigned URL           │──┼──▶ MinIO
│  │  3. Retorna URL ao cliente       │   │
│  └─────────────────────────────────┘   │
└─────────────────────────────────────────┘

┌─────────────────────────────────────────┐
│              Client                     │
│  1. Recebe presigned URL                │
│  2. Upload direto ao MinIO  ────────────┼──▶ MinIO
│  3. Notifica API de conclusão           │
└─────────────────────────────────────────┘
```

---

## 6. Referências Teóricas

- **Fowler, M.** - *Polyglot Persistence* (2011) - martinfowler.com
- **Kleppmann, M.** - *Designing Data-Intensive Applications* (Capítulos 2, 5)
- **PostgreSQL Documentation** - postgresql.org
- **Redis Documentation** - redis.io
- **AWS S3 Multipart Upload** - Documentação oficial
- **CAP Theorem** - Brewer, E. (2000)
- **PACELC Theorem** - Abadi, D. (2012)
