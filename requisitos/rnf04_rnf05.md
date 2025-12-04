# RNF04 e RNF05 - Message Broker (Kafka) e Polyglot Persistence

---

## 1. Resumo dos Requisitos

### RNF04 - Message Broker (Apache Kafka)
> - Utilizar Apache Kafka para comunicação assíncrona entre serviços.
> - Tópicos particionados por `conversation_id` (5 partições).
> - Consumer Groups para balanceamento automático de carga.
> - Garantia "at-least-once delivery".

### RNF05 - Persistência de Dados (Polyglot Persistence)
> - **PostgreSQL**: Banco relacional para dados transacionais (usuários, conversas, mensagens).
> - **Redis**: Cache para sessões JWT e conversas recentes.
> - **MinIO (S3-compatible)**: Object Storage para armazenamento de arquivos até 2GB.

### Importância Teórica

**Kafka** é o backbone de sistemas event-driven modernos:
- Netflix: 700 bilhões de eventos/dia
- LinkedIn: 1 trilhão de mensagens/dia
- Uber: 1 trilhão de mensagens/dia

**Polyglot Persistence** reconhece que *não existe banco de dados universal*:
- Relacional para ACID
- Key-value para velocidade
- Object storage para blobs

---

## 2. Fundamentos Teóricos

### 2.1 Apache Kafka - Arquitetura

```
┌─────────────────────────────────────────────────────────────┐
│                     KAFKA CLUSTER                           │
│                                                             │
│  ┌─────────────────────────────────────────────────┐       │
│  │                    TÓPICO: messages              │       │
│  │  ┌────────────────────────────────────────────┐ │       │
│  │  │ Partition 0: [msg1] [msg4] [msg7] [msg10]  │ │       │
│  │  ├────────────────────────────────────────────┤ │       │
│  │  │ Partition 1: [msg2] [msg5] [msg8]          │ │       │
│  │  ├────────────────────────────────────────────┤ │       │
│  │  │ Partition 2: [msg3] [msg6] [msg9]          │ │       │
│  │  └────────────────────────────────────────────┘ │       │
│  └─────────────────────────────────────────────────┘       │
│                                                             │
│  Producer ──▶ hash(conversation_id) % 5 = partition        │
│                                                             │
│  Consumer Group "router-worker-group":                      │
│    Consumer 1: Partition 0, 1                               │
│    Consumer 2: Partition 2                                  │
│    (Rebalance automático quando consumer entra/sai)         │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

#### Conceitos-Chave do Kafka

| Conceito | Descrição | No Chat4All |
|----------|-----------|-------------|
| **Topic** | Canal de mensagens | `messages`, `whatsapp.messages`, `instagram.messages` |
| **Partition** | Divisão ordenada do tópico | 5 partições por tópico |
| **Producer** | Publica mensagens | `KafkaProducer.php` em api-service |
| **Consumer** | Consome mensagens | `KafkaConsumer.php` em router-worker |
| **Consumer Group** | Grupo de consumers | `router-worker-group` |
| **Offset** | Posição da mensagem | Commitado manualmente |

#### Garantias de Entrega

```
┌─────────────────────────────────────────────────────────────┐
│              GARANTIAS DE ENTREGA NO KAFKA                  │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  AT-MOST-ONCE (autocommit=true, sem retry)                  │
│  ─────────────────────────────────────────                  │
│  Producer envia → Consumer recebe → Commit → Processa       │
│  ⚠️ Se falhar no processamento, mensagem perdida            │
│                                                             │
│  AT-LEAST-ONCE (autocommit=false, commit após processar) ✅ │
│  ────────────────────────────────────────────────────────   │
│  Producer envia → Consumer recebe → Processa → Commit       │
│  ⚠️ Se falhar após processar mas antes de commit:           │
│     Mensagem reprocessada (duplicação possível)             │
│                                                             │
│  EXACTLY-ONCE (transactional API, Kafka Streams)            │
│  ─────────────────────────────────────────────              │
│  Produtor e Consumer coordenados via transação              │
│  ✅ Sem duplicação, mas complexidade maior                  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Polyglot Persistence

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

---

## 3. Implementação no Chat4All

### 3.1 RNF04 - Apache Kafka

#### 3.1.1 Configuração do Cluster (`docker-compose.yml`)

**Linhas 58-72 (Zookeeper)**:
```yaml
zookeeper:
  image: confluentinc/cp-zookeeper:7.5.0
  container_name: chat4all-zookeeper
  environment:
    ZOOKEEPER_CLIENT_PORT: 2181
    ZOOKEEPER_TICK_TIME: 2000
  healthcheck:
    test: ["CMD", "nc", "-z", "localhost", "2181"]
    interval: 10s
```

**Linhas 98-117 (Kafka)**:
```yaml
kafka:
  image: confluentinc/cp-kafka:7.5.0
  container_name: chat4all-kafka
  depends_on:
    zookeeper:
      condition: service_healthy
  environment:
    KAFKA_BROKER_ID: 1
    KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
    KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://localhost:9092,INTERNAL://kafka:9093
    KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: PLAINTEXT:PLAINTEXT,INTERNAL:PLAINTEXT
    KAFKA_INTER_BROKER_LISTENER_NAME: INTERNAL
    KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
    KAFKA_AUTO_CREATE_TOPICS_ENABLE: "true"
```

#### 3.1.2 Inicialização de Tópicos (`scripts/init-kafka-topics.sh`)

```bash
#!/bin/bash
# Criar tópicos com 5 partições

kafka-topics --create \
    --bootstrap-server kafka:9092 \
    --topic messages \
    --partitions 5 \
    --replication-factor 1 \
    --if-not-exists

kafka-topics --create \
    --bootstrap-server kafka:9092 \
    --topic whatsapp.messages \
    --partitions 5 \
    --replication-factor 1 \
    --if-not-exists

kafka-topics --create \
    --bootstrap-server kafka:9092 \
    --topic instagram.messages \
    --partitions 5 \
    --replication-factor 1 \
    --if-not-exists
```

#### 3.1.3 Produtor Kafka (`KafkaProducer.php`)

**Linhas 48-80**:
```php
/**
 * Publicar mensagem no Kafka
 * 
 * @param array $message Dados da mensagem
 * @param string|null $key Chave de particionamento (conversation_id para garantir ordem)
 */
public function publish(array $message, ?string $key = null): void
{
    try {
        $payload = json_encode($message);

        // RD_KAFKA_PARTITION_UA = usar particionamento automático baseado na key
        // hash(key) % num_partitions = partition assignment
        $this->topic->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);

        // Poll para processar callbacks internos
        $this->producer->poll(0);

        $this->logger->info('Message published to Kafka', [
            'message_id' => $message['message_id'] ?? 'unknown',
            'key' => $key  // conversation_id
        ]);

        // Flush síncrono para garantir entrega
        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $this->producer->flush(1000);  // Timeout 1s
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                break;
            }
        }
    } catch (\Exception $e) {
        $this->logger->error('Failed to publish message to Kafka: ' . $e->getMessage());
        throw $e;
    }
}
```

**Particionamento por conversation_id** (`MessageService.php`):
```php
// Linha 89 - Publica com key = conversation_id
$this->kafkaProducer->publish($kafkaMessage, $conversationId);

// Isso garante que mensagens da mesma conversa vão para a mesma partição
// Resultado: ORDEM PRESERVADA por conversa
```

#### 3.1.4 Consumidor Kafka (`KafkaConsumer.php`)

**Linhas 50-75 (Configuração)**:
```php
// Configurações básicas de conexão
$conf->set('metadata.broker.list', $brokers);
$conf->set('group.id', $groupId);  // "router-worker-group"

// TOLERÂNCIA A FALHAS: Commit manual de offsets
// Com auto.commit desabilitado, o offset só é commitado após
// processamento bem-sucedido
$conf->set('enable.auto.commit', 'false');  // 🔑 AT-LEAST-ONCE

// Começar do início se não houver offset armazenado
$conf->set('auto.offset.reset', 'earliest');

// Configurações de sessão para rebalanceamento rápido
$conf->set('session.timeout.ms', '10000');   // 10s para detectar consumer morto
$conf->set('heartbeat.interval.ms', '3000'); // Heartbeat a cada 3s
$conf->set('max.poll.interval.ms', '300000'); // 5min máximo entre polls
```

**Linhas 100-160 (Loop de Consumo)**:
```php
public function consume(): void
{
    // Iniciar consumidor na partição 0 do início
    $this->topic->consumeStart(0, RD_KAFKA_OFFSET_STORED);

    $this->logger->info('Starting message consumption loop');
    $processedCount = 0;

    while (true) {
        // Poll com timeout de 1000ms
        $message = $this->topic->consume(0, 1000);

        if ($message === null) {
            continue;
        }

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                // Mensagem recebida com sucesso
                $payload = json_decode($message->payload, true);
                
                try {
                    // Processar mensagem (rotear para connector)
                    $this->processor->process($payload);
                    $processedCount++;

                    // COMMIT MANUAL após processamento bem-sucedido
                    // Se falhar antes do commit, mensagem será reprocessada
                    $this->topic->offsetStore($message->partition, $message->offset);
                    
                    $this->logger->debug('Message processed and offset stored', [
                        'offset' => $message->offset,
                        'partition' => $message->partition
                    ]);
                } catch (\Exception $e) {
                    // Falha no processamento - NÃO commita offset
                    // Mensagem será reprocessada no próximo poll
                    $this->logger->error('Error processing message: ' . $e->getMessage());
                }
                break;

            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                // Fim da partição - normal, continuar polling
                break;

            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                // Timeout - normal, continuar polling
                break;

            default:
                $this->logger->error('Kafka error: ' . $message->errstr());
                break;
        }
    }
}
```

### 3.2 RNF05 - Polyglot Persistence

#### 3.2.1 PostgreSQL (`Database.php`)

**Configuração de Conexão (Linhas 18-40)**:
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

**Schema (`scripts/init-db.sql`)**:
```sql
-- Extensão para UUIDs
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
```

#### 3.2.2 Redis (`docker-compose.yml`)

**Configuração (Linhas 23-34)**:
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
```

**Uso para Pub/Sub (`RedisSubscriber.php`)**:
```php
// WebSocket worker subscreve a eventos de status
$redis->subscribe(['status-updates'], function($channel, $message) use ($wsHandler) {
    $data = json_decode($message, true);
    $userId = $data['user_id'];
    
    // Notifica conexões WebSocket do usuário
    $wsHandler->notifyUser($userId, $data);
});
```

**Uso para Cache de Sessão**:
```php
// Armazenar token JWT validado
$redis->setex("jwt:$userId", 3600, json_encode($tokenData));

// Buscar antes de validar novamente
$cached = $redis->get("jwt:$userId");
if ($cached) {
    return json_decode($cached, true);
}
```

#### 3.2.3 MinIO (`MinioService.php`)

**Inicialização (Linhas 20-45)**:
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

**Multipart Upload (Linhas 68-120)**:
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
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| **RNF04**: Kafka assíncrono | ✅ | `KafkaProducer.php`, `KafkaConsumer.php` |
| **RNF04**: 5 partições | ✅ | `init-kafka-topics.sh` |
| **RNF04**: Consumer Groups | ✅ | `group.id: router-worker-group` |
| **RNF04**: At-least-once | ✅ | `enable.auto.commit: false` + commit manual |
| **RNF05**: PostgreSQL | ✅ | `Database.php`, `init-db.sql` |
| **RNF05**: Redis | ✅ | Pub/Sub para WebSocket |
| **RNF05**: MinIO | ✅ | `MinioService.php` com multipart |

### 4.2 Pontos Fortes

1. **Particionamento por conversation_id**: Garante ordem de mensagens por conversa
2. **Commit manual**: Evita perda de mensagens em falhas
3. **Flush síncrono no Producer**: Garante que mensagem foi persistida no broker
4. **Multipart upload**: Suporta arquivos até 2GB sem carregar em memória

### 4.3 Limitações Identificadas

#### Limitação 1: Replication Factor = 1

**Problema** (`docker-compose.yml`):
```yaml
KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
```

Se o broker único falhar, todos os dados são perdidos.

**Solução para produção**:
```yaml
# Adicionar mais brokers
kafka-1:
  environment:
    KAFKA_BROKER_ID: 1
    KAFKA_DEFAULT_REPLICATION_FACTOR: 3

kafka-2:
  environment:
    KAFKA_BROKER_ID: 2
    
kafka-3:
  environment:
    KAFKA_BROKER_ID: 3
```

#### Limitação 2: Idempotência Não Garantida

**Problema**: At-least-once pode causar duplicação.

```php
// Se isso falhar após persistir no banco mas antes do commit Kafka:
$this->database->saveMessage($message);  // ✅ Salvo
$this->topic->offsetStore(...);          // ❌ Falhou
// Próximo poll: mensagem reprocessada e salva novamente
```

**Solução**: Upsert com message_id como chave:
```sql
INSERT INTO messages (message_id, content, ...)
VALUES ($1, $2, ...)
ON CONFLICT (message_id) DO NOTHING;  -- Idempotente
```

#### Limitação 3: Redis Sem Persistência Configurada

**Problema**: Dados em memória perdidos em restart.

**Solução**:
```yaml
redis:
  command: redis-server --appendonly yes --appendfsync everysec
```

#### Limitação 4: MinIO Single Node

**Problema**: Sem redundância para arquivos.

**Solução**: MinIO Distributed Mode:
```yaml
minio:
  command: server http://minio{1...4}/data --console-address ":9001"
```

### 4.4 Perguntas Socráticas para Aprofundamento

1. **Sobre Kafka**:
   - "Se uma partição fica indisponível, o que acontece com as mensagens destinadas a ela?"
   - "Consumer Group garante ordem entre consumers? Ou apenas dentro de cada partição?"
   - "O que é o teorema de FLP e como se relaciona com a coordenação do Kafka?"

2. **Sobre Polyglot Persistence**:
   - "Se o Redis cair, o sistema continua funcionando? Com que degradação?"
   - "Como você garantiria consistência entre PostgreSQL e Redis?"
   - "MinIO é eventualmente consistente. O que isso significa para leituras imediatas após upload?"

3. **Sobre CAP Theorem**:
   - "Kafka prioriza C ou A em partição de rede?"
   - "PostgreSQL é CP ou CA? Em qual cenário de falha isso importa?"

---

## 5. Referências Teóricas

- **Kleppmann, M.** - *Designing Data-Intensive Applications* (Capítulos 4, 5, 11)
- **Apache Kafka Documentation** - *Kafka: The Definitive Guide*
- **CAP Theorem** - Brewer, E. (2000)
- **Polyglot Persistence** - Fowler, M. (2011)
- **AWS S3 Multipart Upload** - Documentação oficial
