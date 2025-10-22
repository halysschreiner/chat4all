# Chat4All v2 - Design de Arquitetura, Modelagem de Dados e Esqueleto do Projeto

## 1. Design da Arquitetura

### 1.1 Visão Geral da Arquitetura

A arquitetura proposta segue um padrão de **Microserviços com Event-Driven Architecture**, combinando os princípios de **CQRS (Command Query Responsibility Segregation)** e **Event Sourcing** parcial para garantir escalabilidade e rastreabilidade.

```
┌──────────────────────────────────────────────────────────────────┐
│                        CAMADA DE ENTRADA                         │
├──────────────────────────────────────────────────────────────────┤
│  Load Balancer (HAProxy/Nginx)                                   │
│         ↓                    ↓                    ↓              │
│   API Gateway 1        API Gateway 2        API Gateway N        │
│   (PHP-FPM + Nginx)    (PHP-FPM + Nginx)   (PHP-FPM + Nginx)     │
└──────────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────────┐
│                     CAMADA DE SERVIÇOS CORE                      │
├──────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐   │
│  │ Auth Service    │  │ Frontend Service │  │ Presence       │   │
│  │ (gRPC Server)   │  │ (gRPC Server)    │  │ Service        │   │
│  │ PHP 8.4         │  │ PHP 8.4          │  │ (gRPC/PHP)     │   │
│  └─────────────────┘  └──────────────────┘  └────────────────┘   │
│                                                                  │
│  ┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐   │
│  │ Conversation    │  │ Message          │  │ File           │   │
│  │ Service         │  │ Service          │  │ Service        │   │
│  │ (gRPC Server)   │  │ (gRPC Server)    │  │ (gRPC/PHP)     │   │
│  └─────────────────┘  └──────────────────┘  └────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────────┐
│                    CAMADA DE MENSAGERIA                          │
├──────────────────────────────────────────────────────────────────┤
│              Apache Kafka (Event Stream)                         │
│  Topics: message.sent, message.delivered, message.read,          │
│          file.uploaded, user.presence, conversation.created      │
└──────────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────────┐
│                    CAMADA DE WORKERS                             │
├──────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐   │
│  │ Router Worker   │  │ Delivery Worker  │  │ Status         │   │
│  │ (PHP Consumer)  │  │ (PHP Consumer)   │  │ Worker         │   │
│  └─────────────────┘  └──────────────────┘  └────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────────┐
│                  CAMADA DE CONNECTORS                            │
├──────────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌──────────────────┐  ┌────────────────┐   │
│  │ WhatsApp        │  │ Instagram        │  │ Telegram       │   │
│  │ Connector       │  │ Connector        │  │ Connector      │   │
│  │ (Plugin/PHP)    │  │ (Plugin/PHP)     │  │ (Plugin/PHP)   │   │
│  └─────────────────┘  └──────────────────┘  └────────────────┘   │
└──────────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────────┐
│                  CAMADA DE PERSISTÊNCIA                          │
├──────────────────────────────────────────────────────────────────┤
│  ┌──────────────────────┐  ┌─────────────────────────────────┐   │
│  │ PostgreSQL Cluster   │  │ MongoDB Cluster                 │   │
│  │ (Metadata, Users,    │  │ (Messages, Conversations,       │   │
│  │  Conversations)      │  │  History)                       │   │
│  └──────────────────────┘  └─────────────────────────────────┘   │
│                                                                  │
│  ┌──────────────────────┐  ┌─────────────────────────────────┐   │
│  │ Redis Cluster        │  │ MinIO (S3-Compatible)           │   │
│  │ (Cache, Sessions,    │  │ (Object Storage para arquivos)  │   │
│  │  Presence)           │  │                                 │   │
│  └──────────────────────┘  └─────────────────────────────────┘   │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐    │
│  │ etcd Cluster (Service Discovery, Config, Coordination)   │    │
│  └──────────────────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────────┐
│                  CAMADA DE OBSERVABILIDADE                       │
├──────────────────────────────────────────────────────────────────┤
│  Prometheus + Grafana + Jaeger + ELK Stack                       │
└──────────────────────────────────────────────────────────────────┘
```

### 1.2 Justificativas das Escolhas Arquiteturais

#### 1.2.1 Por que PHP 8.4?

**Vantagens:**

- **Performance Moderna**: PHP 8.4 com JIT compiler oferece performance comparável a linguagens tradicionalmente mais rápidas (até 3x mais rápido que PHP 7.x)
- **Tipagem Forte**: Suporte robusto a tipos (Property Hooks, readonly classes) reduz bugs em sistemas distribuídos
- **Ecossistema Maduro**: Bibliotecas consolidadas para todas as necessidades (gRPC, Kafka, Redis, MongoDB)
- **Baixa Curva de Aprendizado**: Facilita onboarding de desenvolvedores
- **Async/Parallel**: Extensões como Swoole, ReactPHP e Amp permitem operações assíncronas nativas
- **Custo**: Menor custo de infraestrutura comparado a linguagens que requerem mais recursos

**Por que é a melhor escolha:**

- **Escalabilidade**: PHP-FPM com pool de workers escala horizontalmente de forma trivial
- **Microserviços**: Cada serviço pode ser deployado independentemente
- **gRPC Nativo**: Suporte oficial via extensão grpc
- **Maturidade em Web**: Frameworks como Symfony, Laravel oferecem componentes enterprise-grade

#### 1.2.2 Por que gRPC?

**Vantagens:**

- **Performance**: Protocol Buffers (binário) são até 7x menores que JSON e 20x mais rápidos no parsing
- **Tipagem Forte**: Contratos definidos em `.proto` garantem compatibilidade entre serviços
- **Streaming Bidirecional**: Essencial para real-time messaging
- **Multiplexing HTTP/2**: Múltiplas chamadas simultâneas na mesma conexão
- **Code Generation**: Gera automaticamente clients/servers em múltiplas linguagens

**Por que é a melhor escolha:**

- **Baixa Latência**: Crítico para atingir <200ms de latência
- **Interoperabilidade**: Facilita integração futura com serviços em outras linguagens
- **Versioning**: Backward compatibility nativo do Protobuf
- **Load Balancing**: Suporte nativo a múltiplas estratégias

#### 1.2.3 Por que Apache Kafka?

**Vantagens:**

- **Throughput Massivo**: Capaz de processar milhões de mensagens/segundo
- **Durabilidade**: Persistência em disco com replicação
- **Replay**: Capacidade de reprocessar eventos (event sourcing)
- **Particionamento**: Preserva ordem causal por conversation_id
- **Escalabilidade Linear**: Adicionar brokers aumenta capacidade proporcionalmente

**Por que é a melhor escolha:**

- **At-least-once Delivery**: Com idempotência alcança effectively-once
- **Consumer Groups**: Múltiplos workers processam paralelamente
- **Backpressure**: Não perde mensagens em picos de carga
- **Ecosystem**: Kafka Connect facilita integrações futuras

#### 1.2.4 Por que PostgreSQL + MongoDB (Polyglot Persistence)?

**PostgreSQL para:**

- **Dados Relacionais**: Users, conversations metadata, channel mappings
- **ACID Transactions**: Garantias fortes onde necessário
- **JSON Support**: JSONB para dados semi-estruturados
- **Replicação**: Streaming replication para HA

**MongoDB para:**

- **Mensagens**: Schema flexível, write-heavy workload
- **Escalabilidade Horizontal**: Sharding nativo por conversation_id
- **Time-Series**: Otimizado para dados temporais
- **Aggregation Pipeline**: Queries analíticas eficientes

**Por que Polyglot:**

- **Best Tool for the Job**: Cada banco otimizado para seu use case
- **Isolation**: Problemas em um não afetam o outro
- **Performance**: Queries otimizadas por natureza dos dados

#### 1.2.5 Por que Redis?

**Vantagens:**

- **Velocidade**: Operações em memória (sub-milissegundo)
- **Estruturas de Dados Ricas**: Hashes, Sets, Sorted Sets
- **Pub/Sub**: Para real-time presence
- **Persistência Opcional**: RDB/AOF para durabilidade

**Uso no Projeto:**

- **Session Storage**: Tokens JWT com TTL
- **Cache**: Metadados frequentes (user profiles, conversation info)
- **Presence Tracking**: Online/offline status
- **Rate Limiting**: Token bucket algorithm
- **Deduplication**: Set para message_ids recentes

#### 1.2.6 Por que MinIO?

**Vantagens:**

- **S3-Compatible**: API idêntica ao AWS S3
- **Self-Hosted**: Controle total dos dados
- **Performance**: Escrito em Go, otimizado para throughput
- **Erasure Coding**: Proteção contra falhas de disco
- **Multi-Tenancy**: Buckets isolados por ambiente

**Por que é a melhor escolha:**

- **Custo**: Zero custo de vendor lock-in
- **Resumable Uploads**: Suporte nativo a multipart
- **CDN Ready**: Integração fácil com CDNs
- **Scalability**: Distributed mode com múltiplos nós

#### 1.2.7 Por que etcd?

**Vantagens:**

- **Consensus Distribuído**: Raft algorithm para consistência
- **Service Discovery**: Health checks e service registry
- **Configuration Management**: Versionamento de configs
- **Leader Election**: Para serviços singleton

**Uso no Projeto:**

- **Service Registry**: Discovery de serviços gRPC
- **Feature Flags**: Controle de features em runtime
- **Rate Limits Globais**: Configuração centralizada
- **Sharding Configuration**: Mapeamento dinâmico de partições

### 1.3 Padrões Arquiteturais Aplicados

#### 1.3.1 API Gateway Pattern

- **Vantagem**: Ponto único de entrada, centraliza cross-cutting concerns
- **Implementação**: Nginx como reverse proxy + PHP middleware para auth/rate limiting

#### 1.3.2 Circuit Breaker Pattern

- **Vantagem**: Previne cascata de falhas
- **Implementação**: Biblioteca guzzle/circuit-breaker para connectors externos

#### 1.3.3 Saga Pattern

- **Vantagem**: Transações distribuídas sem 2PC
- **Implementação**: Compensating transactions via Kafka events

#### 1.3.4 CQRS (Command Query Responsibility Segregation)

- **Vantagem**: Otimiza reads e writes separadamente
- **Implementação**: Writes em MongoDB via Kafka, reads de cache Redis

#### 1.3.5 Event Sourcing (Parcial)

- **Vantagem**: Auditoria completa, replay de eventos
- **Implementação**: Eventos de mensagem persistidos no Kafka

## 2. Modelagem de Dados

### 2.1 PostgreSQL Schema (Metadata & Relational Data)

```sql
-- Users e Autenticação
CREATE TABLE users (
    user_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    username VARCHAR(255) UNIQUE NOT NULL,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    status VARCHAR(20) DEFAULT 'active' CHECK (status IN ('active', 'suspended', 'deleted')),
    metadata JSONB DEFAULT '{}'::jsonb,
    
    INDEX idx_users_email (email),
    INDEX idx_users_username (username)
);

-- Mapeamento de usuários para canais externos
CREATE TABLE user_channels (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    channel_type VARCHAR(50) NOT NULL CHECK (channel_type IN ('whatsapp', 'instagram', 'telegram', 'messenger', 'internal')),
    channel_user_id VARCHAR(255) NOT NULL, -- ID do usuário na plataforma externa
    channel_metadata JSONB DEFAULT '{}'::jsonb,
    verified BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(user_id, channel_type),
    INDEX idx_user_channels_user (user_id),
    INDEX idx_user_channels_type (channel_type),
    INDEX idx_user_channels_external (channel_type, channel_user_id)
);

-- Conversas (apenas metadata)
CREATE TABLE conversations (
    conversation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    type VARCHAR(20) NOT NULL CHECK (type IN ('private', 'group')),
    created_by UUID NOT NULL REFERENCES users(user_id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    metadata JSONB DEFAULT '{}'::jsonb,
    is_active BOOLEAN DEFAULT true,
    
    INDEX idx_conversations_created_by (created_by),
    INDEX idx_conversations_updated_at (updated_at DESC)
);

-- Membros das conversas
CREATE TABLE conversation_members (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    conversation_id UUID NOT NULL REFERENCES conversations(conversation_id) ON DELETE CASCADE,
    user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    role VARCHAR(20) DEFAULT 'member' CHECK (role IN ('owner', 'admin', 'member')),
    joined_at TIMESTAMP DEFAULT NOW(),
    last_read_at TIMESTAMP,
    last_read_message_id UUID,
    
    UNIQUE(conversation_id, user_id),
    INDEX idx_conversation_members_conv (conversation_id),
    INDEX idx_conversation_members_user (user_id)
);

-- Webhooks para callbacks
CREATE TABLE webhooks (
    webhook_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    url VARCHAR(2048) NOT NULL,
    events TEXT[] NOT NULL, -- Array de eventos: ['message.delivered', 'message.read']
    secret VARCHAR(255) NOT NULL, -- Para HMAC verification
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    
    INDEX idx_webhooks_user (user_id),
    INDEX idx_webhooks_active (is_active)
);

-- Tokens de acesso (JWT metadata - opcional)
CREATE TABLE access_tokens (
    token_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    user_id UUID NOT NULL REFERENCES users(user_id) ON DELETE CASCADE,
    token_hash VARCHAR(255) NOT NULL UNIQUE,
    expires_at TIMESTAMP NOT NULL,
    created_at TIMESTAMP DEFAULT NOW(),
    last_used_at TIMESTAMP,
    
    INDEX idx_access_tokens_user (user_id),
    INDEX idx_access_tokens_expires (expires_at)
);

-- Configuração de canais (para connectors)
CREATE TABLE channel_configs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    channel_type VARCHAR(50) NOT NULL UNIQUE,
    config JSONB NOT NULL, -- API keys, endpoints, etc.
    is_enabled BOOLEAN DEFAULT true,
    updated_at TIMESTAMP DEFAULT NOW(),
    
    INDEX idx_channel_configs_type (channel_type)
);
```

**Justificativa PostgreSQL Schema:**

- **Normalização**: Reduz redundância, garante integridade referencial
- **JSONB**: Flexibilidade para metadata sem sacrificar performance (índices GIN)
- **UUIDs**: Distribuição de IDs sem coordenação central
- **Constraints**: CHECK constraints validam dados no DB layer
- **Indexes**: Otimizam queries frequentes (user lookup, conversation members)

### 2.2 MongoDB Collections (Messages & Time-Series Data)

```javascript
// Collection: messages
// Sharding Key: conversation_id (hash-based)
{
    _id: ObjectId(), // MongoDB native ID
    message_id: UUID(), // UUID universal para idempotência
    conversation_id: UUID(), // Foreign key para PostgreSQL
    from_user_id: UUID(),
    to_user_ids: [UUID()], // Array para suportar grupos
    
    // Payload
    payload: {
        type: "text|file|image|video|audio",
        text: "Olá!", // Se type=text
        file_reference: { // Se type=file
            file_id: UUID(),
            filename: "doc.pdf",
            size_bytes: 1024000,
            mime_type: "application/pdf",
            storage_url: "minio://bucket/path"
        },
        metadata: {} // Dados customizados
    },
    
    // Channels de entrega
    target_channels: ["whatsapp", "instagram"], // ou ["all"]
    
    // Status tracking
    status: "sent", // sent|delivered|read|failed
    status_history: [
        {
            status: "sent",
            timestamp: ISODate("2025-01-15T10:00:00Z"),
            details: {}
        },
        {
            status: "delivered",
            timestamp: ISODate("2025-01-15T10:00:05Z"),
            channel: "whatsapp",
            details: {}
        }
    ],
    
    // Delivery tracking per channel
    channel_delivery: {
        whatsapp: {
            status: "delivered",
            delivered_at: ISODate("2025-01-15T10:00:05Z"),
            external_message_id: "wa_msg_123"
        },
        instagram: {
            status: "sent",
            sent_at: ISODate("2025-01-15T10:00:01Z")
        }
    },
    
    // Metadata
    sequence_number: 42, // Ordem dentro da conversa
    reply_to_message_id: UUID(), // Se for reply
    is_edited: false,
    is_deleted: false,
    
    // Timestamps
    created_at: ISODate("2025-01-15T10:00:00Z"),
    updated_at: ISODate("2025-01-15T10:00:05Z"),
    ttl_expire_at: ISODate("2025-04-15T10:00:00Z") // TTL index
}

// Indexes
db.messages.createIndex({ message_id: 1 }, { unique: true })
db.messages.createIndex({ conversation_id: 1, sequence_number: 1 })
db.messages.createIndex({ conversation_id: 1, created_at: -1 })
db.messages.createIndex({ from_user_id: 1, created_at: -1 })
db.messages.createIndex({ status: 1 })
db.messages.createIndex({ ttl_expire_at: 1 }, { expireAfterSeconds: 0 }) // TTL
```

```javascript
// Collection: conversation_history
// Para queries de histórico otimizadas
{
    _id: ObjectId(),
    conversation_id: UUID(),
    date: ISODate("2025-01-15"), // Bucketing por dia
    message_ids: [UUID()], // Array de message_ids do dia
    message_count: 127,
    last_message_at: ISODate("2025-01-15T23:59:00Z"),
    
    // Cache de última mensagem para listagem de conversas
    last_message: {
        message_id: UUID(),
        text: "Última mensagem...",
        from_user_id: UUID(),
        created_at: ISODate("2025-01-15T23:59:00Z")
    }
}

// Indexes
db.conversation_history.createIndex({ conversation_id: 1, date: -1 })
```

```javascript
// Collection: file_metadata
// Para metadados de arquivos grandes
{
    _id: ObjectId(),
    file_id: UUID(),
    original_filename: "presentation.pptx",
    size_bytes: 1887436800, // ~1.8GB
    mime_type: "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    
    // Storage details
    storage: {
        provider: "minio",
        bucket: "chat4all-files",
        key: "2025/01/15/uuid-filename.pptx",
        region: "us-east-1",
        url: "https://minio.chat4all.com/chat4all-files/..."
    },
    
    // Chunking info (para uploads resumable)
    chunks: [
        {
            chunk_number: 1,
            size_bytes: 104857600, // 100MB
            checksum: "sha256:abc123...",
            uploaded_at: ISODate("2025-01-15T10:00:00Z")
        }
        // ... mais chunks
    ],
    total_chunks: 18,
    
    // Upload tracking
    upload_status: "completed", // initiated|in_progress|completed|failed
    upload_initiated_by: UUID(), // user_id
    upload_initiated_at: ISODate("2025-01-15T09:55:00Z"),
    upload_completed_at: ISODate("2025-01-15T10:15:00Z"),
    
    // Security
    checksum: "sha256:full_file_hash",
    encryption: {
        algorithm: "AES-256",
        key_id: "key_123"
    },
    
    // Access control
    access_policy: "private", // public|private|restricted
    allowed_users: [UUID()],
    
    // Metadata
    created_at: ISODate("2025-01-15T09:55:00Z"),
    updated_at: ISODate("2025-01-15T10:15:00Z"),
    expires_at: ISODate("2025-04-15T10:15:00Z") // Opcional
}

// Indexes
db.file_metadata.createIndex({ file_id: 1 }, { unique: true })
db.file_metadata.createIndex({ upload_status: 1 })
db.file_metadata.createIndex({ upload_initiated_by: 1 })
```

**Justificativa MongoDB Schema:**

- **Document Model**: Mensagens são naturalmente documentos (payload flexível)
- **Embedding**: Status history embarcado evita joins
- **Sharding**: Por conversation_id mantém mensagens da mesma conversa no mesmo shard
- **Indexes**: Otimizados para queries temporais (created_at DESC)
- **TTL**: Expira mensagens antigas automaticamente
- **Bucketing**: conversation_history reduz scans para listagens

### 2.3 Redis Data Structures

```redis
# Session Storage (Hash)
HSET session:{token_hash} user_id {uuid} expires_at {timestamp}
EXPIRE session:{token_hash} 3600

# User Presence (Sorted Set por timestamp)
ZADD presence:online {current_timestamp} {user_id}
# Remover usuários inativos (>5 min)
ZREMRANGEBYSCORE presence:online 0 {timestamp_5min_ago}

# Message Deduplication (Set com TTL)
SADD dedup:messages {message_id}
EXPIRE dedup:messages 300 # 5 minutos

# Rate Limiting (String com atomic increment)
INCR rate_limit:{user_id}:{minute}
EXPIRE rate_limit:{user_id}:{minute} 60

# Conversation Cache (Hash)
HSET conv:{conversation_id} type {type} members {json_array} updated_at {timestamp}
EXPIRE conv:{conversation_id} 1800 # 30 min

# User Channel Mapping (Hash)
HSET user_channels:{user_id} whatsapp {phone} instagram {username}

# Message Status Cache (para quick lookups)
HSET msg_status:{message_id} status {status} updated_at {timestamp}
EXPIRE msg_status:{message_id} 3600

# Pub/Sub Channels
PUBLISH presence:updates {json_user_event}
PUBLISH message:delivered {json_message_event}
```

**Justificativa Redis:**

- **Performance**: Sub-milissegundo latency para presence checks
- **Atomic Operations**: INCR para rate limiting sem race conditions
- **TTL**: Cleanup automático de dados temporários
- **Pub/Sub**: Real-time events sem overhead de Kafka para dados efêmeros

### 2.4 etcd Key-Value Schema

```
# Service Discovery
/services/grpc/auth/node1 -> {"host": "10.0.1.10", "port": 50051, "health": "healthy"}
/services/grpc/auth/node2 -> {"host": "10.0.1.11", "port": 50051, "health": "healthy"}

# Configuration
/config/kafka/brokers -> ["kafka1:9092", "kafka2:9092", "kafka3:9092"]
/config/rate_limits/global -> {"messages_per_minute": 1000, "files_per_hour": 100}

# Feature Flags
/features/new_ui -> {"enabled": true, "rollout_percentage": 50}

# Sharding Configuration
/sharding/conversations/partitions -> 16
/sharding/conversations/map/partition_0 -> ["node1", "node2"]

# Leader Election
/leaders/file_cleanup_job -> {"leader": "worker-node-3", "elected_at": "2025-01-15T10:00:00Z"}
```

**Justificativa etcd:**

- **Consensus**: Garantias de consistência via Raft
- **Watch API**: Serviços recebem config updates em real-time
- **Lease TTL**: Health checks automáticos
- **Atomic CAS**: Compare-and-swap para leader election

## 3. Esqueleto do Projeto

### 3.1 Estrutura de Diretórios

```
chat4all-v2/
├── api-gateway/                    # API Gateway (Nginx + PHP middleware)
│   ├── config/
│   │   ├── nginx.conf
│   │   └── php-fpm.conf
│   ├── public/
│   │   └── index.php             # Entry point
│   ├── src/
│   │   ├── Middleware/
│   │   │   ├── AuthMiddleware.php
│   │   │   ├── RateLimitMiddleware.php
│   │   │   └── CorsMiddleware.php
│   │   └── Router.php
│   └── Dockerfile
│
├── services/                       # Microserviços gRPC
│   ├── auth-service/
│   │   ├── proto/
│   │   │   └── auth.proto
│   │   ├── src/
│   │   │   ├── Server.php
│   │   │   ├── AuthServiceImpl.php
│   │   │   ├── Repository/
│   │   │   │   └── UserRepository.php
│   │   │   └── Security/
│   │   │       ├── JwtManager.php
│   │   │       └── PasswordHasher.php
│   │   ├── tests/
│   │   ├── composer.json
│   │   └── Dockerfile
│   │
│   ├── message-service/
│   │   ├── proto/
│   │   │   └── message.proto
│   │   ├── src/
│   │   │   ├── Server.php
│   │   │   ├── MessageServiceImpl.php
│   │   │   ├── Repository/
│   │   │   │   ├── MessageRepository.php (MongoDB)
│   │   │   │   └── ConversationRepository.php (PostgreSQL)
│   │   │   ├── EventPublisher/
│   │   │   │   └── KafkaPublisher.php
│   │   │   └── ValueObject/
│   │   │       ├── MessageId.php
│   │   │       └── ConversationId.php
│   │   ├── tests/
│   │   ├── composer.json
│   │   └── Dockerfile
│   │
│   ├── file-service/
│   │   ├── proto/
│   │   │   └── file.proto
│   │   ├── src/
│   │   │   ├── Server.php
│   │   │   ├── FileServiceImpl.php
│   │   │   ├── Storage/
│   │   │   │   ├── MinioStorage.php
│   │   │   │   └── ChunkManager.php
│   │   │   └── Repository/
│   │   │       └── FileMetadataRepository.php
│   │   ├── tests/
│   │   ├── composer.json
│   │   └── Dockerfile
│   │
│   ├── conversation-service/
│   │   └── ... (similar structure)
│   │
│   └── presence-service/
│       └── ... (similar structure)
│
├── workers/                        # Kafka Consumers
│   ├── router-worker/
│   │   ├── src/
│   │   │   ├── Consumer.php
│   │   │   ├── MessageRouter.php
│   │   │   ├── ChannelResolver.php
│   │   │   └── DeduplicationService.php
│   │   ├── config/
│   │   │   └── kafka.php
│   │   ├── composer.json
│   │   └── Dockerfile
│   │
│   ├── delivery-worker/
│   │   ├── src/
│   │   │   ├── Consumer.php
│   │   │   ├── DeliveryHandler.php
│   │   │   └── RetryStrategy.php
│   │   ├── composer.json
│   │   └── Dockerfile
│   │
│   └── status-worker/
│       ├── src/
│       │   ├── Consumer.php
│       │   ├── StatusUpdater.php
│       │   └── WebhookDispatcher.php
│       ├── composer.json
│       └── Dockerfile
│
├── connectors/                     # Channel Adapters (Plugin Architecture)
│   ├── connector-interface/
│   │   └── src/
│   │       ├── ConnectorInterface.php
│   │       ├── MessageDTO.php
│   │       └── ConnectorException.php
│   │
│   ├── whatsapp-connector/
│   │   ├── src/
│   │   │   ├── WhatsAppConnector.php
│   │   │   ├── WhatsAppClient.php
│   │   │   └── WebhookHandler.php
│   │   ├── tests/
│   │   ├── composer.json
│   │   └── Dockerfile
│   │
│   ├── telegram-connector/
│   │   ├── src/
│   │   │   ├── TelegramConnector.php
│   │   │   ├── TelegramBotClient.php
│   │   │   └── WebhookHandler.php
│   │   ├── composer.json
│   │   └── Dockerfile
│   │
│   ├── instagram-connector/
│   │   └── ... (similar structure)
│   │
│   └── mock-connector/             # Para testes
│       └── src/
│           └── MockConnector.php
│
├── shared/                         # Código compartilhado
│   ├── proto/                      # Protobuf definitions compartilhados
│   │   ├── common.proto
│   │   ├── events.proto
│   │   └── Makefile               # Para gerar código
│   │
│   ├── src/
│   │   ├── Database/
│   │   │   ├── PostgresConnection.php
│   │   │   ├── MongoConnection.php
│   │   │   └── RedisConnection.php
│   │   ├── Kafka/
│   │   │   ├── KafkaProducer.php
│   │   │   ├── KafkaConsumer.php
│   │   │   └── TopicRegistry.php
│   │   ├── Etcd/
│   │   │   ├── EtcdClient.php
│   │   │   └── ServiceDiscovery.php
│   │   ├── Logging/
│   │   │   ├── Logger.php
│   │   │   └── TraceContext.php
│   │   ├── Metrics/
│   │   │   ├── PrometheusExporter.php
│   │   │   └── MetricsCollector.php
│   │   ├── Security/
│   │   │   ├── Encryption.php
│   │   │   └── SignatureVerifier.php
│   │   └── ValueObjects/
│   │       ├── UUID.php
│   │       ├── Timestamp.php
│   │       └── MessageStatus.php
│   │
│   └── composer.json
│
├── frontend/                       # Interface Web
│   ├── angular/                    # AngularJS application
│   │   ├── src/
│   │   │   ├── app/
│   │   │   │   ├── auth/
│   │   │   │   │   ├── login.component.ts
│   │   │   │   │   └── auth.service.ts
│   │   │   │   ├── chat/
│   │   │   │   │   ├── conversation-list.component.ts
│   │   │   │   │   ├── message-list.component.ts
│   │   │   │   │   └── message-input.component.ts
│   │   │   │   ├── services/
│   │   │   │   │   ├── api.service.ts
│   │   │   │   │   ├── websocket.service.ts
│   │   │   │   │   └── file-upload.service.ts
│   │   │   │   └── app.module.ts
│   │   │   ├── assets/
│   │   │   └── index.html
│   │   ├── package.json
│   │   ├── angular.json
│   │   └── Dockerfile
│   │
│   └── nginx/                      # Nginx para servir frontend
│       ├── nginx.conf
│       └── Dockerfile
│
├── infrastructure/                 # Configuração de infraestrutura
│   ├── kubernetes/
│   │   ├── namespaces/
│   │   │   └── chat4all.yaml
│   │   ├── databases/
│   │   │   ├── postgresql-statefulset.yaml
│   │   │   ├── mongodb-statefulset.yaml
│   │   │   ├── redis-statefulset.yaml
│   │   │   └── etcd-statefulset.yaml
│   │   ├── messaging/
│   │   │   └── kafka-cluster.yaml
│   │   ├── storage/
│   │   │   └── minio-statefulset.yaml
│   │   ├── services/
│   │   │   ├── auth-service-deployment.yaml
│   │   │   ├── message-service-deployment.yaml
│   │   │   ├── file-service-deployment.yaml
│   │   │   └── ...
│   │   ├── workers/
│   │   │   ├── router-worker-deployment.yaml
│   │   │   └── ...
│   │   ├── connectors/
│   │   │   ├── telegram-connector-deployment.yaml
│   │   │   └── ...
│   │   ├── monitoring/
│   │   │   ├── prometheus-deployment.yaml
│   │   │   ├── grafana-deployment.yaml
│   │   │   └── jaeger-deployment.yaml
│   │   ├── ingress/
│   │   │   └── ingress.yaml
│   │   └── configmaps/
│   │       ├── kafka-config.yaml
│   │       └── app-config.yaml
│   │
│   ├── docker-compose/             # Para desenvolvimento local
│   │   ├── docker-compose.yml
│   │   ├── docker-compose.dev.yml
│   │   └── .env.example
│   │
│   └── terraform/                  # IaC para cloud (opcional)
│       ├── aws/
│       └── gcp/
│
├── scripts/                        # Scripts úteis
│   ├── generate-proto.sh           # Gera código a partir de .proto
│   ├── init-databases.sh           # Inicializa schemas
│   ├── seed-data.sh                # Dados de teste
│   ├── run-tests.sh                # Executa todos os testes
│   └── deploy.sh                   # Deploy automatizado
│
├── tests/                          # Testes integrados
│   ├── integration/
│   │   ├── MessageFlowTest.php
│   │   ├── FileUploadTest.php
│   │   └── CrossChannelTest.php
│   ├── load/
│   │   ├── k6-scenarios/
│   │   │   ├── message-load.js
│   │   │   └── file-upload-load.js
│   │   └── gatling/
│   └── e2e/
│       └── cypress/
│
├── docs/                           # Documentação
│   ├── architecture/
│   │   ├── system-design.md
│   │   ├── data-flow.md
│   │   └── diagrams/
│   ├── api/
│   │   ├── openapi.yaml           # Spec OpenAPI 3.0
│   │   └── grpc-docs.md
│   ├── deployment/
│   │   ├── kubernetes-guide.md
│   │   └── scaling-guide.md
│   └── development/
│       ├── setup-guide.md
│       ├── coding-standards.md
│       └── testing-guide.md
│
├── monitoring/                     # Configurações de monitoramento
│   ├── prometheus/
│   │   ├── prometheus.yml
│   │   └── alerts.yml
│   ├── grafana/
│   │   └── dashboards/
│   │       ├── system-overview.json
│   │       ├── message-metrics.json
│   │       └── connector-health.json
│   └── jaeger/
│       └── jaeger-config.yaml
│
├── .github/                        # CI/CD
│   └── workflows/
│       ├── ci.yml
│       ├── deploy-staging.yml
│       └── deploy-production.yml
│
├── composer.json                   # Root composer (para shared)
├── .gitignore
├── .editorconfig
├── README.md
├── CONTRIBUTING.md
└── LICENSE
```

### 3.2 Tecnologias e Dependências Principais

#### 3.2.1 Composer Dependencies (PHP Services)

```json
// services/message-service/composer.json
{
    "name": "chat4all/message-service",
    "description": "Message Service - gRPC Server",
    "type": "project",
    "require": {
        "php": "^8.4",
        "grpc/grpc": "^1.57",
        "google/protobuf": "^3.25",
        "mongodb/mongodb": "^1.17",
        "rdkafka/rdkafka": "^6.0",
        "predis/predis": "^2.2",
        "monolog/monolog": "^3.5",
        "ramsey/uuid": "^4.7",
        "symfony/console": "^7.0",
        "php-etcd/client": "^2.0",
        "open-telemetry/sdk": "^1.0",
        "guzzlehttp/guzzle": "^7.8"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5",
        "phpstan/phpstan": "^1.10",
        "squizlabs/php_codesniffer": "^3.8",
        "mockery/mockery": "^1.6"
    },
    "autoload": {
        "psr-4": {
            "Chat4All\\MessageService\\": "src/"
        }
    }
}
```

**Justificativa das Bibliotecas:**

1. **grpc/grpc**: Extensão oficial PHP para gRPC
    - Performance nativa (C extension)
    - Suporte completo a streaming bidirecional
2. **mongodb/mongodb**: Driver oficial MongoDB
    - Connection pooling automático
    - Query builder fluente
    - Suporte a transações multi-document
3. **rdkafka/rdkafka**: Cliente Kafka de alta performance
    - Baseado em librdkafka (C)
    - 10x mais rápido que clientes PHP puros
    - Suporte a exactly-once semantics
4. **predis/predis**: Cliente Redis puro PHP
    - Não requer extensão C
    - Suporte a clustering e sentinel
    - Pipeline e transações
5. **open-telemetry/sdk**: Observabilidade distribuída
    - Tracing padronizado
    - Integração com Jaeger/Zipkin
    - Métricas e logs correlacionados

#### 3.2.2 Frontend Dependencies (Angular)

```json
// frontend/angular/package.json
{
    "name": "chat4all-frontend",
    "version": "2.0.0",
    "scripts": {
        "start": "ng serve",
        "build": "ng build --prod",
        "test": "ng test"
    },
    "dependencies": {
        "@angular/core": "^17.0.0",
        "@angular/common": "^17.0.0",
        "@angular/router": "^17.0.0",
        "@angular/forms": "^17.0.0",
        "@angular/material": "^17.0.0",
        "rxjs": "^7.8.0",
        "socket.io-client": "^4.6.0",
        "@grpc/grpc-js": "^1.9.0",
        "@grpc/proto-loader": "^0.7.10",
        "ngx-file-drop": "^16.0.0",
        "emoji-mart": "^5.5.0"
    }
}
```

**Nota sobre Frontend:** Embora o enunciado mencione AngularJS, **recomendo fortemente usar Angular moderno (v17+)** ao invés de AngularJS (1.x), pois:

- AngularJS está deprecado desde 2022
- Angular moderno oferece melhor performance (Ivy compiler)
- TypeScript nativo com type safety
- Reactive programming com RxJS
- Component-based architecture alinhada com microserviços

**Alternativas ao Angular:**

1. **React** + TypeScript: Mais leve, maior comunidade
2. **Vue 3**: Curva de aprendizado mais suave
3. **Svelte**: Performance excepcional, bundle menor