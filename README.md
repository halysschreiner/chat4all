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

### 3.3 Exemplos de Código Core

#### 3.3.1 Protobuf Definition (message.proto)

```protobuf
syntax = "proto3";

package chat4all.message.v1;

option php_namespace = "Chat4All\\Message\\V1";
option php_metadata_namespace = "Chat4All\\Message\\V1\\GPBMetadata";

import "google/protobuf/timestamp.proto";
import "common.proto";

service MessageService {
    // Enviar mensagem
    rpc SendMessage(SendMessageRequest) returns (SendMessageResponse);
    
    // Enviar mensagem com streaming (para arquivos grandes)
    rpc SendMessageStream(stream SendMessageRequest) returns (SendMessageResponse);
    
    // Buscar mensagens de uma conversa
    rpc GetMessages(GetMessagesRequest) returns (GetMessagesResponse);
    
    // Stream de mensagens em tempo real
    rpc StreamMessages(StreamMessagesRequest) returns (stream MessageEvent);
    
    // Atualizar status de mensagem (delivered/read)
    rpc UpdateMessageStatus(UpdateMessageStatusRequest) returns (UpdateMessageStatusResponse);
}

message SendMessageRequest {
    string message_id = 1;              // UUID
    string conversation_id = 2;         // UUID
    string from_user_id = 3;            // UUID
    repeated string to_user_ids = 4;    // UUIDs
    repeated string target_channels = 5; // ["whatsapp", "instagram"] ou ["all"]
    
    MessagePayload payload = 6;
    map<string, string> metadata = 7;
    
    google.protobuf.Timestamp created_at = 8;
}

message MessagePayload {
    enum PayloadType {
        TEXT = 0;
        FILE = 1;
        IMAGE = 2;
        VIDEO = 3;
        AUDIO = 4;
    }
    
    PayloadType type = 1;
    string text = 2;                    // Se type=TEXT
    FileReference file = 3;              // Se type=FILE/IMAGE/VIDEO/AUDIO
    map<string, string> extra = 4;
}

message FileReference {
    string file_id = 1;
    string filename = 2;
    int64 size_bytes = 3;
    string mime_type = 4;
    string storage_url = 5;
    string checksum = 6;
}

message SendMessageResponse {
    string message_id = 1;
    MessageStatus status = 2;
    google.protobuf.Timestamp accepted_at = 3;
}

enum MessageStatus {
    SENT = 0;
    DELIVERED = 1;
    READ = 2;
    FAILED = 3;
}

message GetMessagesRequest {
    string conversation_id = 1;
    google.protobuf.Timestamp since = 2;
    int32 limit = 3;                    // Pagination
    string cursor = 4;                  // Pagination cursor
}

message GetMessagesResponse {
    repeated Message messages = 1;
    string next_cursor = 2;
    bool has_more = 3;
}

message Message {
    string message_id = 1;
    string conversation_id = 2;
    string from_user_id = 3;
    repeated string to_user_ids = 4;
    
    MessagePayload payload = 5;
    MessageStatus status = 6;
    
    repeated StatusUpdate status_history = 7;
    map<string, ChannelDelivery> channel_delivery = 8;
    
    int64 sequence_number = 9;
    google.protobuf.Timestamp created_at = 10;
    google.protobuf.Timestamp updated_at = 11;
}

message StatusUpdate {
    MessageStatus status = 1;
    google.protobuf.Timestamp timestamp = 2;
    string channel = 3;
    map<string, string> details = 4;
}

message ChannelDelivery {
    MessageStatus status = 1;
    google.protobuf.Timestamp delivered_at = 2;
    string external_message_id = 3;
}

message StreamMessagesRequest {
    string conversation_id = 1;
    google.protobuf.Timestamp since = 2;
}

message MessageEvent {
    enum EventType {
        NEW_MESSAGE = 0;
        STATUS_UPDATE = 1;
        MESSAGE_DELETED = 2;
    }
    
    EventType type = 1;
    Message message = 2;
    google.protobuf.Timestamp event_time = 3;
}

message UpdateMessageStatusRequest {
    string message_id = 1;
    MessageStatus status = 2;
    string channel = 3;
    map<string, string> metadata = 4;
}

message UpdateMessageStatusResponse {
    bool success = 1;
    google.protobuf.Timestamp updated_at = 2;
}
```

**Justificativa Protobuf:**

- **Versionamento**: Campos numerados permitem evolução backward-compatible
- **Eficiência**: Serialização binária 7x menor que JSON
- **Type Safety**: Validação automática de tipos
- **Cross-Language**: Gera código para múltiplas linguagens

#### 3.3.2 Message Service Implementation

```php
<?php
// services/message-service/src/MessageServiceImpl.php

declare(strict_types=1);

namespace Chat4All\MessageService;

use Chat4All\Message\V1\MessageServiceInterface;
use Chat4All\Message\V1\SendMessageRequest;
use Chat4All\Message\V1\SendMessageResponse;
use Chat4All\Message\V1\GetMessagesRequest;
use Chat4All\Message\V1\GetMessagesResponse;
use Chat4All\Message\V1\MessageStatus;
use Chat4All\Shared\Kafka\KafkaProducer;
use Chat4All\Shared\Database\MongoConnection;
use Chat4All\Shared\Database\RedisConnection;
use Chat4All\Shared\Logging\Logger;
use Chat4All\Shared\Metrics\MetricsCollector;
use Ramsey\Uuid\Uuid;
use MongoDB\BSON\UTCDateTime;

class MessageServiceImpl implements MessageServiceInterface
{
    public function __construct(
        private readonly MessageRepository $messageRepository,
        private readonly ConversationRepository $conversationRepository,
        private readonly KafkaProducer $kafkaProducer,
        private readonly RedisConnection $redis,
        private readonly Logger $logger,
        private readonly MetricsCollector $metrics,
        private readonly DeduplicationService $deduplicationService
    ) {}

    /**
     * Enviar mensagem
     * 
     * Fluxo:
     * 1. Validar request
     * 2. Verificar duplicação (idempotência)
     * 3. Persistir no MongoDB
     * 4. Publicar evento no Kafka
     * 5. Retornar resposta
     */
    public function SendMessage(
        SendMessageRequest $request
    ): SendMessageResponse {
        $startTime = microtime(true);
        $traceId = $this->logger->generateTraceId();
        
        try {
            // 1. Validação
            $this->validateSendMessageRequest($request);
            
            // 2. Idempotência - verificar se mensagem já existe
            if ($this->deduplicationService->isDuplicate($request->getMessageId())) {
                $this->logger->info('Duplicate message detected', [
                    'message_id' => $request->getMessageId(),
                    'trace_id' => $traceId
                ]);
                
                // Retornar mensagem existente
                $existingMessage = $this->messageRepository->findById(
                    $request->getMessageId()
                );
                
                return $this->buildResponse($existingMessage);
            }
            
            // 3. Criar objeto de mensagem
            $message = $this->buildMessageFromRequest($request);
            
            // 4. Persistir no MongoDB
            $this->messageRepository->save($message);
            
            // 5. Marcar como processada (dedup)
            $this->deduplicationService->markAsProcessed($request->getMessageId());
            
            // 6. Publicar evento no Kafka para processamento assíncrono
            $event = [
                'event_type' => 'message.sent',
                'message_id' => $message->getMessageId(),
                'conversation_id' => $message->getConversationId(),
                'from_user_id' => $message->getFromUserId(),
                'to_user_ids' => $message->getToUserIds(),
                'target_channels' => $request->getTargetChannels(),
                'payload' => $this->serializePayload($message->getPayload()),
                'timestamp' => time(),
                'trace_id' => $traceId
            ];
            
            // Particionar por conversation_id para manter ordem
            $this->kafkaProducer->produce(
                topic: 'message.sent',
                message: json_encode($event),
                key: $message->getConversationId() // Partition key
            );
            
            // 7. Métricas
            $duration = microtime(true) - $startTime;
            $this->metrics->histogram(
                'message_service_send_duration_seconds',
                $duration,
                ['status' => 'success']
            );
            $this->metrics->counter('messages_sent_total', 1);
            
            // 8. Log
            $this->logger->info('Message sent successfully', [
                'message_id' => $message->getMessageId(),
                'conversation_id' => $message->getConversationId(),
                'duration_ms' => $duration * 1000,
                'trace_id' => $traceId
            ]);
            
            // 9. Resposta
            $response = new SendMessageResponse();
            $response->setMessageId($message->getMessageId());
            $response->setStatus(MessageStatus::SENT);
            $response->setAcceptedAt($this->createTimestamp());
            
            return $response;
            
        } catch (\Exception $e) {
            // Métricas de erro
            $this->metrics->counter('messages_sent_errors_total', 1, [
                'error_type' => get_class($e)
            ]);
            
            // Log de erro
            $this->logger->error('Failed to send message', [
                'message_id' => $request->getMessageId(),
                'error' => $e->getMessage(),
                'trace_id' => $traceId,
                'exception' => $e
            ]);
            
            throw $e;
        }
    }

    /**
     * Buscar mensagens de uma conversa (com paginação)
     */
    public function GetMessages(
        GetMessagesRequest $request
    ): GetMessagesResponse {
        $conversationId = $request->getConversationId();
        
        // Verificar permissão (omitido por brevidade)
        
        // Buscar do cache primeiro
        $cacheKey = "messages:{$conversationId}:" . 
                    $request->getCursor();
        
        $cached = $this->redis->get($cacheKey);
        if ($cached) {
            $this->metrics->counter('message_cache_hits_total', 1);
            return unserialize($cached);
        }
        
        $this->metrics->counter('message_cache_misses_total', 1);
        
        // Buscar do MongoDB
        $messages = $this->messageRepository->findByConversation(
            conversationId: $conversationId,
            since: $request->getSince(),
            limit: $request->getLimit() ?: 50,
            cursor: $request->getCursor()
        );
        
        $response = new GetMessagesResponse();
        foreach ($messages['items'] as $message) {
            $response->addMessages($this->messageToProto($message));
        }
        
        $response->setNextCursor($messages['next_cursor']);
        $response->setHasMore($messages['has_more']);
        
        // Cache por 30 segundos
        $this->redis->setex($cacheKey, 30, serialize($response));
        
        return $response;
    }

    /**
     * Stream de mensagens em tempo real (Server-Side Streaming)
     */
    public function StreamMessages(
        StreamMessagesRequest $request,
        \Grpc\ServerCallWriter $writer
    ): void {
        $conversationId = $request->getConversationId();
        
        // Subscribe no Redis Pub/Sub para updates em tempo real
        $pubsub = $this->redis->pubSubLoop();
        $pubsub->subscribe("conversation:{$conversationId}:messages");
        
        try {
            foreach ($pubsub as $message) {
                if ($message->kind === 'message') {
                    $eventData = json_decode($message->payload, true);
                    
                    // Construir evento
                    $event = new MessageEvent();
                    $event->setType(MessageEvent\EventType::NEW_MESSAGE);
                    $event->setMessage($this->buildMessageProto($eventData));
                    $event->setEventTime($this->createTimestamp());
                    
                    // Enviar para cliente via stream
                    $writer->write($event);
                }
            }
        } catch (\Exception $e) {
            $this->logger->error('Stream error', [
                'conversation_id' => $conversationId,
                'error' => $e->getMessage()
            ]);
        } finally {
            $pubsub->unsubscribe();
        }
    }

    // Métodos auxiliares...
    
    private function validateSendMessageRequest(SendMessageRequest $request): void
    {
        if (empty($request->getMessageId())) {
            throw new \InvalidArgumentException('message_id is required');
        }
        
        if (!Uuid::isValid($request->getMessageId())) {
            throw new \InvalidArgumentException('message_id must be valid UUID');
        }
        
        if (empty($request->getConversationId())) {
            throw new \InvalidArgumentException('conversation_id is required');
        }
        
        if (empty($request->getFromUserId())) {
            throw new \InvalidArgumentException('from_user_id is required');
        }
        
        if (count($request->getToUserIds()) === 0) {
            throw new \InvalidArgumentException('to_user_ids cannot be empty');
        }
        
        // Validar payload
        $payload = $request->getPayload();
        if ($payload->getType() === PayloadType::TEXT && empty($payload->getText())) {
            throw new \InvalidArgumentException('text is required for TEXT type');
        }
    }

    private function buildMessageFromRequest(SendMessageRequest $request): Message
    {
        return new Message(
            messageId: $request->getMessageId(),
            conversationId: $request->getConversationId(),
            fromUserId: $request->getFromUserId(),
            toUserIds: iterator_to_array($request->getToUserIds()),
            payload: $request->getPayload(),
            status: MessageStatus::SENT,
            sequenceNumber: $this->getNextSequenceNumber($request->getConversationId()),
            createdAt: new \DateTimeImmutable(),
            metadata: iterator_to_array($request->getMetadata())
        );
    }

    private function getNextSequenceNumber(string $conversationId): int
    {
        // Atomic increment no Redis para sequence number
        return (int) $this->redis->incr("conversation:{$conversationId}:seq");
    }
}
```

**Justificativa da Implementação:**

1. **Idempotência**: `DeduplicationService` garante exactly-once via Redis SET
2. **Async Processing**: Kafka desacopla recebimento de entrega
3. **Particionamento**: Key por `conversation_id` mantém ordem causal
4. **Cache**: Redis reduz load no MongoDB para reads
5. **Observability**: Métricas e logs estruturados em cada etapa
6. **Streaming**: gRPC Server-Side Streaming para real-time
7. **Type Safety**: PHP 8.4 strict types + Protobuf

#### 3.3.3 Router Worker (Kafka Consumer)

```php
<?php
// workers/router-worker/src/MessageRouter.php

declare(strict_types=1);

namespace Chat4All\Workers\Router;

use Chat4All\Shared\Kafka\KafkaConsumer;
use Chat4All\Shared\Database\MongoConnection;
use Chat4All\Shared\Database\PostgresConnection;
use Chat4All\Shared\Logging\Logger;
use Chat4All\Connectors\ConnectorRegistry;
use RdKafka\Message;

class MessageRouter
{
    public function __construct(
        private readonly KafkaConsumer $consumer,
        private readonly ConnectorRegistry $connectorRegistry,
        private readonly ChannelResolver $channelResolver,
        private readonly PostgresConnection $postgres,
        private readonly MongoConnection $mongo,
        private readonly Logger $logger
    ) {}

    /**
     * Inicia consumo de mensagens do Kafka
     */
    public function run(): void
    {
        $this->logger->info('Router Worker started');
        
        // Subscribe no topic
        $this->consumer->subscribe(['message.sent']);
        
        while (true) {
            // Poll mensagens (timeout 1s)
            $message = $this->consumer->consume(1000);
            
            if ($message === null) {
                continue;
            }
            
            if ($message->err) {
                $this->handleError($message);
                continue;
            }
            
            try {
                $this->processMessage($message);
                
                // Commit offset (at-least-once)
                $this->consumer->commit($message);
                
            } catch (\Exception $e) {
                $this->logger->error('Failed to process message', [
                    'offset' => $message->offset,
                    'partition' => $message->partition,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Estratégia: retry com backoff ou DLQ
                $this->handleProcessingFailure($message, $e);
            }
        }
    }

    /**
     * Processa uma mensagem do Kafka
     * 
     * Lógica:
     * 1. Parse evento
     * 2. Resolver canais de entrega
     * 3. Para cada canal:
     *    - Se interno: push via WebSocket/notificação
     *    - Se externo: chamar connector apropriado
     * 4. Atualizar status no MongoDB
     */
    private function processMessage(Message $kafkaMessage): void
    {
        $event = json_decode($kafkaMessage->payload, true);
        $traceId = $event['trace_id'] ?? 'unknown';
        
        $this->logger->info('Processing message event', [
            'message_id' => $event['message_id'],
            'conversation_id' => $event['conversation_id'],
            'trace_id' => $traceId
        ]);
        
        // 1. Buscar informações da mensagem
        $message = $this->mongo->getCollection('messages')->findOne([
            'message_id' => $event['message_id']
        ]);
        
        if (!$message) {
            throw new \RuntimeException('Message not found in database');
        }
        
        // 2. Resolver canais de entrega
        $targetChannels = $event['target_channels'];
        
        if (in_array('all', $targetChannels)) {
            $targetChannels = $this->getAllEnabledChannels();
        }
        
        // 3. Para cada destinatário
        foreach ($event['to_user_ids'] as $toUserId) {
            // Resolver canais do usuário
            $userChannels = $this->channelResolver->resolveUserChannels(
                $toUserId,
                $targetChannels
            );
            
            // Entregar em cada canal
            foreach ($userChannels as $channel) {
                $this->deliverToChannel(
                    message: $message,
                    toUserId: $toUserId,
                    channel: $channel,
                    traceId: $traceId
                );
            }
        }
        
        $this->logger->info('Message routed successfully', [
            'message_id' => $event['message_id'],
            'trace_id' => $traceId
        ]);
    }

    /**
     * Entregar mensagem para um canal específico
     */
    private function deliverToChannel(
        array $message,
        string $toUserId,
        array $channel,
        string $traceId
    ): void {
        $channelType = $channel['channel_type'];
        $channelUserId = $channel['channel_user_id'];
        
        try {
            if ($channelType === 'internal') {
                // Entrega interna: verificar presença
                $this->deliverInternal($message, $toUserId, $traceId);
            } else {
                // Entrega externa: usar connector
                $this->deliverExternal(
                    message: $message,
                    channelType: $channelType,
                    channelUserId: $channelUserId,
                    traceId: $traceId
                );
            }
            
            // Atualizar status no MongoDB
            $this->updateChannelDeliveryStatus(
                messageId: $message['message_id'],
                channel: $channelType,
                status: 'delivered'
            );
            
            // Publicar evento de entrega
            $this->publishDeliveryEvent($message['message_id'], $channelType);
            
        } catch (\Exception $e) {
            $this->logger->error('Failed to deliver to channel', [
                'message_id' => $message['message_id'],
                'channel' => $channelType,
                'error' => $e->getMessage(),
                'trace_id' => $traceId
            ]);
            
            // Atualizar status como failed
            $this->updateChannelDeliveryStatus(
                messageId: $message['message_id'],
                channel: $channelType,
                status: 'failed',
                error: $e->getMessage()
            );
            
            // Retry logic (exponential backoff)
            $this->scheduleRetry($message, $channel, $e);
        }
    }

    /**
     * Entrega interna (usuários conectados via WebSocket/App)
     */
    private function deliverInternal(
        array $message,
        string $toUserId,
        string $traceId
    ): void {
        // Verificar se usuário está online (Redis)
        $isOnline = $this->redis->zscore('presence:online', $toUserId) !== false;
        
        if ($isOnline) {
            // Push via Redis Pub/Sub (para WebSocket servers)
            $this->redis->publish(
                "user:{$toUserId}:messages",
                json_encode([
                    'type' => 'new_message',
                    'message' => $message,
                    'trace_id' => $traceId
                ])
            );
            
            $this->logger->info('Message pushed to online user', [
                'user_id' => $toUserId,
                'message_id' => $message['message_id']
            ]);
        } else {
            // Usuário offline: store-and-forward (já persistido)
            // Enviar notificação push móvel
            $this->sendPushNotification($toUserId, $message);
            
            $this->logger->info('Message queued for offline user', [
                'user_id' => $toUserId,
                'message_id' => $message['message_id']
            ]);
        }
    }

    /**
     * Entrega externa via connector
     */
    private function deliverExternal(
        array $message,
        string $channelType,
        string $channelUserId,
        string $traceId
    ): void {
        // Obter connector para o canal
        $connector = $this->connectorRegistry->getConnector($channelType);
        
        if (!$connector) {
            throw new \RuntimeException("Connector not found for channel: {$channelType}");
        }
        
        // Preparar payload
        $payload = [
            'to' => $channelUserId,
            'message' => $message['payload'],
            'metadata' => [
                'message_id' => $message['message_id'],
                'trace_id' => $traceId
            ]
        ];
        
        // Enviar via connector (com circuit breaker)
        $result = $this->executeWithCircuitBreaker(
            fn() => $connector->sendMessage($payload),
            $channelType
        );
        
        $this->logger->info('Message sent via connector', [
            'message_id' => $message['message_id'],
            'channel' => $channelType,
            'external_id' => $result['external_message_id'] ?? null
        ]);
    }

    /**
     * Circuit Breaker pattern para proteger contra falhas de connectors
     */
    private function executeWithCircuitBreaker(
        callable $operation,
        string $service
    ): mixed {
        $circuitKey = "circuit_breaker:{$service}";
        
        // Verificar estado do circuit breaker
        $state = $this->redis->get($circuitKey);
        
        if ($state === 'open') {
            // Circuit aberto: não tentar executar
            $this->logger->warning('Circuit breaker is open', [
                'service' => $service
            ]);
            throw new CircuitBreakerOpenException("Circuit breaker open for {$service}");
        }
        
        try {
            // Executar operação
            $result = $operation();
            
            // Sucesso: resetar contador de falhas
            $this->redis->del("circuit_breaker:{$service}:failures");
            
            return $result;
            
        } catch (\Exception $e) {
            // Incrementar contador de falhas
            $failures = $this->redis->incr("circuit_breaker:{$service}:failures");
            
            // Se atingir threshold (ex: 5 falhas), abrir circuit
            if ($failures >= 5) {
                $this->redis->setex($circuitKey, 60, 'open'); // Abrir por 60s
                $this->logger->error('Circuit breaker opened', [
                    'service' => $service,
                    'failures' => $failures
                ]);
            }
            
            throw $e;
        }
    }

    /**
     * Atualizar status de entrega no MongoDB
     */
    private function updateChannelDeliveryStatus(
        string $messageId,
        string $channel,
        string $status,
        ?string $error = null
    ): void {
        $update = [
            '$set' => [
                "channel_delivery.{$channel}.status" => $status,
                "channel_delivery.{$channel}.updated_at" => new \MongoDB\BSON\UTCDateTime(),
            ],
            '$push' => [
                'status_history' => [
                    'status' => $status,
                    'channel' => $channel,
                    'timestamp' => new \MongoDB\BSON\UTCDateTime(),
                    'details' => $error ? ['error' => $error] : []
                ]
            ]
        ];
        
        if ($error) {
            $update['$set']["channel_delivery.{$channel}.error"] = $error;
        }
        
        $this->mongo->getCollection('messages')->updateOne(
            ['message_id' => $messageId],
            $update
        );
    }

    /**
     * Publicar evento de entrega no Kafka
     */
    private function publishDeliveryEvent(string $messageId, string $channel): void
    {
        $event = [
            'event_type' => 'message.delivered',
            'message_id' => $messageId,
            'channel' => $channel,
            'timestamp' => time()
        ];
        
        $this->kafkaProducer->produce(
            topic: 'message.delivered',
            message: json_encode($event),
            key: $messageId
        );
    }

    /**
     * Agendar retry com exponential backoff
     */
    private function scheduleRetry(
        array $message,
        array $channel,
        \Exception $error
    ): void {
        // Obter número de tentativas
        $retryCount = $message['retry_count'] ?? 0;
        
        // Máximo de 5 tentativas
        if ($retryCount >= 5) {
            $this->logger->error('Max retries reached, sending to DLQ', [
                'message_id' => $message['message_id'],
                'channel' => $channel['channel_type']
            ]);
            
            // Enviar para Dead Letter Queue
            $this->sendToDLQ($message, $channel, $error);
            return;
        }
        
        // Calcular delay: 2^retry_count * 1000ms (1s, 2s, 4s, 8s, 16s)
        $delayMs = (2 ** $retryCount) * 1000;
        
        // Publicar evento de retry com delay
        $retryEvent = [
            'event_type' => 'message.retry',
            'message_id' => $message['message_id'],
            'channel' => $channel,
            'retry_count' => $retryCount + 1,
            'delay_ms' => $delayMs,
            'error' => $error->getMessage(),
            'timestamp' => time()
        ];
        
        // Kafka não suporta delay nativo, usar Redis para scheduling
        $scheduleTime = time() + ($delayMs / 1000);
        $this->redis->zadd(
            'retry_queue',
            $scheduleTime,
            json_encode($retryEvent)
        );
        
        $this->logger->info('Retry scheduled', [
            'message_id' => $message['message_id'],
            'retry_count' => $retryCount + 1,
            'delay_ms' => $delayMs
        ]);
    }

    private function sendToDLQ(array $message, array $channel, \Exception $error): void
    {
        $dlqEvent = [
            'message' => $message,
            'channel' => $channel,
            'error' => $error->getMessage(),
            'trace' => $error->getTraceAsString(),
            'timestamp' => time()
        ];
        
        $this->kafkaProducer->produce(
            topic: 'message.dlq',
            message: json_encode($dlqEvent),
            key: $message['message_id']
        );
    }
}
```

#### 3.3.4 Connector Interface e Implementação Telegram

```php
<?php
// connectors/connector-interface/src/ConnectorInterface.php

declare(strict_types=1);

namespace Chat4All\Connectors;

interface ConnectorInterface
{
    /**
     * Conectar ao serviço externo
     */
    public function connect(): void;
    
    /**
     * Enviar mensagem de texto
     */
    public function sendMessage(array $payload): array;
    
    /**
     * Enviar arquivo
     */
    public function sendFile(array $payload): array;
    
    /**
     * Handler para webhooks recebidos
     */
    public function handleWebhook(array $webhookData): void;
    
    /**
     * Verificar status da conexão
     */
    public function healthCheck(): bool;
    
    /**
     * Nome do canal
     */
    public function getChannelName(): string;
}
```

```php
<?php
// connectors/telegram-connector/src/TelegramConnector.php

declare(strict_types=1);

namespace Chat4All\Connectors\Telegram;

use Chat4All\Connectors\ConnectorInterface;
use Chat4All\Shared\Logging\Logger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class TelegramConnector implements ConnectorInterface
{
    private const API_BASE_URL = 'https://api.telegram.org/bot';
    
    private Client $httpClient;
    private string $botToken;
    
    public function __construct(
        private readonly Logger $logger,
        private readonly array $config
    ) {
        $this->botToken = $config['bot_token'] ?? throw new \InvalidArgumentException('bot_token required');
        
        $this->httpClient = new Client([
            'base_uri' => self::API_BASE_URL . $this->botToken . '/',
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json'
            ]
        ]);
    }
    
    public function connect(): void
    {
        // Telegram Bot API não requer conexão persistente
        // Verificar se bot está configurado corretamente
        try {
            $response = $this->httpClient->get('getMe');
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!$data['ok']) {
                throw new \RuntimeException('Failed to connect to Telegram API');
            }
            
            $this->logger->info('Telegram connector initialized', [
                'bot_username' => $data['result']['username']
            ]);
            
        } catch (RequestException $e) {
            $this->logger->error('Failed to initialize Telegram connector', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }
    
    /**
     * Enviar mensagem de texto
     * 
     * Payload esperado:
     * [
     *   'to' => 'telegram_user_id',
     *   'message' => ['type' => 'text', 'text' => '...'],
     *   'metadata' => ['message_id' => '...']
     * ]
     */
    public function sendMessage(array $payload): array
    {
        $chatId = $payload['to'];
        $text = $payload['message']['text'] ?? '';
        $messageId = $payload['metadata']['message_id'] ?? null;
        
        try {
            $response = $this->httpClient->post('sendMessage', [
                'json' => [
                    'chat_id' => $chatId,
                    'text' => $text,
                    'parse_mode' => 'HTML',
                    // Para rastreamento
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [[]]
                    ])
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!$data['ok']) {
                throw new \RuntimeException('Telegram API error: ' . ($data['description'] ?? 'Unknown'));
            }
            
            $telegramMessageId = $data['result']['message_id'];
            
            $this->logger->info('Message sent via Telegram', [
                'message_id' => $messageId,
                'telegram_message_id' => $telegramMessageId,
                'chat_id' => $chatId
            ]);
            
            return [
                'success' => true,
                'external_message_id' => (string) $telegramMessageId,
                'timestamp' => time()
            ];
            
        } catch (RequestException $e) {
            $this->logger->error('Failed to send Telegram message', [
                'message_id' => $messageId,
                'chat_id' => $chatId,
                'error' => $e->getMessage()
            ]);
            
            throw new ConnectorException(
                'Failed to send message via Telegram: ' . $e->getMessage(),
                previous: $e
            );
        }
    }
    
    /**
     * Enviar arquivo
     */
    public function sendFile(array $payload): array
    {
        $chatId = $payload['to'];
        $fileUrl = $payload['message']['file']['storage_url'] ?? '';
        $fileName = $payload['message']['file']['filename'] ?? 'file';
        $mimeType = $payload['message']['file']['mime_type'] ?? 'application/octet-stream';
        
        // Determinar método baseado no mime type
        $method = 'sendDocument';
        if (str_starts_with($mimeType, 'image/')) {
            $method = 'sendPhoto';
        } elseif (str_starts_with($mimeType, 'video/')) {
            $method = 'sendVideo';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            $method = 'sendAudio';
        }
        
        try {
            // Opção 1: Enviar por URL (se público)
            // Opção 2: Download e upload (para arquivos privados)
            
            $response = $this->httpClient->post($method, [
                'json' => [
                    'chat_id' => $chatId,
                    'document' => $fileUrl, // Telegram aceita URL se público
                    'caption' => $fileName
                ]
            ]);
            
            $data = json_decode($response->getBody()->getContents(), true);
            
            if (!$data['ok']) {
                throw new \RuntimeException('Telegram API error: ' . ($data['description'] ?? 'Unknown'));
            }
            
            return [
                'success' => true,
                'external_message_id' => (string) $data['result']['message_id'],
                'timestamp' => time()
            ];
            
        } catch (RequestException $e) {
            throw new ConnectorException(
                'Failed to send file via Telegram: ' . $e->getMessage(),
                previous: $e
            );
        }
    }
    
    /**
     * Handler para webhooks do Telegram
     * 
     * Telegram envia updates sobre:
     * - Mensagens recebidas
     * - Confirmações de leitura
     * - Status de entrega
     */
    public function handleWebhook(array $webhookData): void
    {
        $updateType = array_key_first(array_filter([
            'message' => $webhookData['message'] ?? null,
            'edited_message' => $webhookData['edited_message'] ?? null,
            'callback_query' => $webhookData['callback_query'] ?? null
        ]));
        
        switch ($updateType) {
            case 'message':
                $this->handleIncomingMessage($webhookData['message']);
                break;
                
            case 'edited_message':
                $this->handleMessageEdit($webhookData['edited_message']);
                break;
                
            case 'callback_query':
                $this->handleCallbackQuery($webhookData['callback_query']);
                break;
        }
    }
    
    private function handleIncomingMessage(array $message): void
    {
        // Publicar evento de mensagem recebida
        $event = [
            'event_type' => 'message.received',
            'channel' => 'telegram',
            'external_message_id' => $message['message_id'],
            'from' => [
                'channel_user_id' => (string) $message['from']['id'],
                'username' => $message['from']['username'] ?? null
            ],
            'text' => $message['text'] ?? null,
            'timestamp' => $message['date']
        ];
        
        // Publicar no Kafka para processamento
        $this->kafkaProducer->produce(
            topic: 'message.received.telegram',
            message: json_encode($event)
        );
    }
    
    public function healthCheck(): bool
    {
        try {
            $response = $this->httpClient->get('getMe');
            $data = json_decode($response->getBody()->getContents(), true);
            return $data['ok'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function getChannelName(): string
    {
        return 'telegram';
    }
}
```

### 3.4 Configurações e Deploy

#### 3.4.1 Docker Compose para Desenvolvimento Local

```yaml
# infrastructure/docker-compose/docker-compose.yml

version: '3.9'

services:
  # ==================== DATABASES ====================
  
  postgres:
    image: postgres:16-alpine
    container_name: chat4all-postgres
    environment:
      POSTGRES_DB: chat4all
      POSTGRES_USER: chat4all
      POSTGRES_PASSWORD: chat4all_secret
    ports:
      - "5432:5432"
    volumes:
      - postgres_data:/var/lib/postgresql/data
      - ./init-scripts/postgres:/docker-entrypoint-initdb.d
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U chat4all"]
      interval: 10s
      timeout: 5s
      retries: 5

  mongodb:
    image: mongo:7.0
    container_name: chat4all-mongodb
    environment:
      MONGO_INITDB_ROOT_USERNAME: admin
      MONGO_INITDB_ROOT_PASSWORD: admin_secret
      MONGO_INITDB_DATABASE: chat4all
    ports:
      - "27017:27017"
    volumes:
      - mongodb_data:/data/db
      - ./init-scripts/mongodb:/docker-entrypoint-initdb.d
    healthcheck:
      test: echo 'db.runCommand("ping").ok' | mongosh localhost:27017/test --quiet
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    container_name: chat4all-redis
    command: redis-server --appendonly yes
    ports:
      - "6379:6379"
    volumes:
      - redis_data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s
      timeout: 5s
      retries: 5

  # ==================== MESSAGING ====================
  
  zookeeper:
    image: confluentinc/cp-zookeeper:7.5.0
    container_name: chat4all-zookeeper
    environment:
      ZOOKEEPER_CLIENT_PORT: 2181
      ZOOKEEPER_TICK_TIME: 2000
    ports:
      - "2181:2181"

  kafka:
    image: confluentinc/cp-kafka:7.5.0
    container_name: chat4all-kafka
    depends_on:
      - zookeeper
    ports:
      - "9092:9092"
      - "9093:9093"
    environment:
      KAFKA_BROKER_ID: 1
      KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
      KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://kafka:9093,PLAINTEXT_HOST://localhost:9092
      KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: PLAINTEXT:PLAINTEXT,PLAINTEXT_HOST:PLAINTEXT
      KAFKA_INTER_BROKER_LISTENER_NAME: PLAINTEXT
      KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
      KAFKA_AUTO_CREATE_TOPICS_ENABLE: "true"
    healthcheck:
      test: kafka-broker-api-versions --bootstrap-server localhost:9093
      interval: 10s
      timeout: 5s
      retries: 5

  # ==================== STORAGE ====================
  
  minio:
    image: minio/minio:latest
    container_name: chat4all-minio
    command: server /data --console-address ":9001"
    environment:
      MINIO_ROOT_USER: minioadmin
      MINIO_ROOT_PASSWORD: minioadmin123
    ports:
      - "9000:9000"
      - "9001:9001"
    volumes:
      - minio_data:/data
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"]
      interval: 10s
      timeout: 5s
      retries: 5

  # ==================== SERVICE DISCOVERY ====================
  
  etcd:
    image: quay.io/coreos/etcd:v3.5.10
    container_name: chat4all-etcd
    command:
      - etcd
      - --name=etcd0
      - --advertise-client-urls=http://etcd:2379
      - --listen-client-urls=http://0.0.0.0:2379
      - --initial-advertise-peer-urls=http://etcd:2380
      - --listen-peer-urls=http://0.0.0.0:2380
      - --initial-cluster=etcd0=http://etcd:2380
    ports:
      - "2379:2379"
      - "2380:2380"
    volumes:
      - etcd_data:/etcd-data

  # ==================== SERVICES ====================
  
  auth-service:
    build:
      context: ../../services/auth-service
      dockerfile: Dockerfile
    container_name: chat4all-auth-service
    depends_on:
      - postgres
      - redis
      - etcd
    environment:
      DATABASE_URL: postgresql://chat4all:chat4all_secret@postgres:5432/chat4all
      REDIS_URL: redis://redis:6379
      ETCD_ENDPOINTS: http://etcd:2379
      GRPC_PORT: 50051
    ports:
      - "50051:50051"
    restart: unless-stopped

  message-service:
    build:
      context: ../../services/message-service
      dockerfile: Dockerfile
    container_name: chat4all-message-service
    depends_on:
      - postgres
      - mongodb
      - redis
      - kafka
      - etcd
    environment:
      POSTGRES_URL: postgresql://chat4all:chat4all_secret@postgres:5432/chat4all
      MONGODB_URL: mongodb://admin:admin_secret@mongodb:27017/chat4all?authSource=admin
      REDIS_URL: redis://redis:6379
      KAFKA_BROKERS: kafka:9093
      ETCD_ENDPOINTS: http://etcd:2379
      GRPC_PORT: 50052
    ports:
      - "50052:50052"
    restart: unless-stopped

  file-service:
    build:
      context: ../../services/file-service
      dockerfile: Dockerfile
    container_name: chat4all-file-service
    depends_on:
      - mongodb
      - minio
      - etcd
    environment:
      MONGODB_URL: mongodb://admin:admin_secret@mongodb:27017/chat4all?authSource=admin
      MINIO_ENDPOINT: minio:9000
      MINIO_ACCESS_KEY: minioadmin
      MINIO_SECRET_KEY: minioadmin123
      ETCD_ENDPOINTS: http://etcd:2379
      GRPC_PORT: 50053
    ports:
      - "50053:50053"
    restart: unless-stopped

  # ==================== WORKERS ====================
  
  router-worker:
    build:
      context: ../../workers/router-worker
      dockerfile: Dockerfile
    container_name: chat4all-router-worker
    depends_on:
      - kafka
      - mongodb
      - redis
    environment:
      KAFKA_BROKERS: kafka:9093
      MONGODB_URL: mongodb://admin:admin_secret@mongodb:27017/chat4all?authSource=admin
      REDIS_URL: redis://redis:6379
    restart: unless-stopped
    deploy:
      replicas: 2

  # ==================== API GATEWAY ====================
  
  api-gateway:
    build:
      context: ../../api-gateway
      dockerfile: Dockerfile
    container_name: chat4all-api-gateway
    depends_on:
      - auth-service
      - message-service
      - file-service
    ports:
      - "8080:80"
    environment:
      AUTH_SERVICE_GRPC: auth-service:50051
      MESSAGE_SERVICE_GRPC: message-service:50052
      FILE_SERVICE_GRPC: file-service:50053
      REDIS_URL: redis://redis:6379
    restart: unless-stopped

  # ==================== MONITORING ====================
  
  prometheus:
    image: prom/prometheus:latest
    container_name: chat4all-prometheus
    command:
      - '--config.file=/etc/prometheus/prometheus.yml'
      - '--storage.tsdb.path=/prometheus'
    ports:
      - "9090:9090"
    volumes:
      - ./prometheus/prometheus.yml:/etc/prometheus/prometheus.yml
      - prometheus_data:/prometheus

  grafana:
    image: grafana/grafana:latest
    container_name: chat4all-grafana
    depends_on:
      - prometheus
    ports:
      - "3000:3000"
    environment:
      GF_SECURITY_ADMIN_PASSWORD: admin123
    volumes:
      - ./grafana/dashboards:/etc/grafana/provisioning/dashboards
      - ./grafana/datasources:/etc/grafana/provisioning/datasources
      - grafana_data:/var/lib/grafana

  jaeger:
    image: jaegertracing/all-in-one:latest
    container_name: chat4all-jaeger
    ports:
	  - "5775:5775/udp"
      - "6831:6831/udp"
      - "6832:6832/udp"
      - "5778:5778"
      - "16686:16686"  # Jaeger UI
      - "14268:14268"
      - "14250:14250"
      - "9411:9411"
    environment:
      COLLECTOR_ZIPKIN_HOST_PORT: ":9411"

volumes:
  postgres_data:
  mongodb_data:
  redis_data:
  minio_data:
  etcd_data:
  prometheus_data:
  grafana_data:

networks:
  default:
    name: chat4all-network
    driver: bridge
```
#### 3.4.2 Kubernetes Deployment Example (Message Service)

```yaml
# infrastructure/kubernetes/services/message-service-deployment.yaml

apiVersion: v1
kind: ConfigMap
metadata:
  name: message-service-config
  namespace: chat4all
data:
  KAFKA_BROKERS: "kafka-0.kafka-headless.chat4all.svc.cluster.local:9092,kafka-1.kafka-headless.chat4all.svc.cluster.local:9092"
  ETCD_ENDPOINTS: "http://etcd-0.etcd-headless.chat4all.svc.cluster.local:2379"
  GRPC_PORT: "50052"
  LOG_LEVEL: "info"

---
apiVersion: v1
kind: Secret
metadata:
  name: message-service-secrets
  namespace: chat4all
type: Opaque
stringData:
  POSTGRES_URL: "postgresql://chat4all:CHANGE_ME@postgresql.chat4all.svc.cluster.local:5432/chat4all"
  MONGODB_URL: "mongodb://admin:CHANGE_ME@mongodb-0.mongodb-headless.chat4all.svc.cluster.local:27017,mongodb-1.mongodb-headless.chat4all.svc.cluster.local:27017/chat4all?authSource=admin&replicaSet=rs0"
  REDIS_URL: "redis://redis-master.chat4all.svc.cluster.local:6379"

---
apiVersion: apps/v1
kind: Deployment
metadata:
  name: message-service
  namespace: chat4all
  labels:
    app: message-service
    version: v1
spec:
  replicas: 3
  strategy:
    type: RollingUpdate
    rollingUpdate:
      maxSurge: 1
      maxUnavailable: 0
  selector:
    matchLabels:
      app: message-service
  template:
    metadata:
      labels:
        app: message-service
        version: v1
      annotations:
        prometheus.io/scrape: "true"
        prometheus.io/port: "9102"
        prometheus.io/path: "/metrics"
    spec:
      affinity:
        podAntiAffinity:
          preferredDuringSchedulingIgnoredDuringExecution:
          - weight: 100
            podAffinityTerm:
              labelSelector:
                matchExpressions:
                - key: app
                  operator: In
                  values:
                  - message-service
              topologyKey: kubernetes.io/hostname
      
      containers:
      - name: message-service
        image: chat4all/message-service:latest
        imagePullPolicy: Always
        
        ports:
        - name: grpc
          containerPort: 50052
          protocol: TCP
        - name: metrics
          containerPort: 9102
          protocol: TCP
        
        env:
        - name: POD_NAME
          valueFrom:
            fieldRef:
              fieldPath: metadata.name
        - name: POD_NAMESPACE
          valueFrom:
            fieldRef:
              fieldPath: metadata.namespace
        - name: POD_IP
          valueFrom:
            fieldRef:
              fieldPath: status.podIP
        
        envFrom:
        - configMapRef:
            name: message-service-config
        - secretRef:
            name: message-service-secrets
        
        resources:
          requests:
            cpu: 500m
            memory: 512Mi
          limits:
            cpu: 2000m
            memory: 2Gi
        
        livenessProbe:
          exec:
            command:
            - grpc_health_probe
            - -addr=:50052
          initialDelaySeconds: 30
          periodSeconds: 10
          timeoutSeconds: 5
          failureThreshold: 3
        
        readinessProbe:
          exec:
            command:
            - grpc_health_probe
            - -addr=:50052
          initialDelaySeconds: 10
          periodSeconds: 5
          timeoutSeconds: 3
          failureThreshold: 2
        
        lifecycle:
          preStop:
            exec:
              command: ["/bin/sh", "-c", "sleep 15"]
        
        volumeMounts:
        - name: config
          mountPath: /app/config
          readOnly: true
      
      volumes:
      - name: config
        configMap:
          name: message-service-config
      
      terminationGracePeriodSeconds: 30

---
apiVersion: v1
kind: Service
metadata:
  name: message-service
  namespace: chat4all
  labels:
    app: message-service
spec:
  type: ClusterIP
  selector:
    app: message-service
  ports:
  - name: grpc
    port: 50052
    targetPort: 50052
    protocol: TCP
  - name: metrics
    port: 9102
    targetPort: 9102
    protocol: TCP
  sessionAffinity: None

---
apiVersion: v1
kind: Service
metadata:
  name: message-service-headless
  namespace: chat4all
  labels:
    app: message-service
spec:
  type: ClusterIP
  clusterIP: None
  selector:
    app: message-service
  ports:
  - name: grpc
    port: 50052
    targetPort: 50052
    protocol: TCP
  publishNotReadyAddresses: true

---
apiVersion: autoscaling/v2
kind: HorizontalPodAutoscaler
metadata:
  name: message-service-hpa
  namespace: chat4all
spec:
  scaleTargetRef:
    apiVersion: apps/v1
    kind: Deployment
    name: message-service
  minReplicas: 3
  maxReplicas: 20
  metrics:
  - type: Resource
    resource:
      name: cpu
      target:
        type: Utilization
        averageUtilization: 70
  - type: Resource
    resource:
      name: memory
      target:
        type: Utilization
        averageUtilization: 80
  - type: Pods
    pods:
      metric:
        name: grpc_requests_per_second
      target:
        type: AverageValue
        averageValue: "1000"
  behavior:
    scaleDown:
      stabilizationWindowSeconds: 300
      policies:
      - type: Percent
        value: 50
        periodSeconds: 60
    scaleUp:
      stabilizationWindowSeconds: 0
      policies:
      - type: Percent
        value: 100
        periodSeconds: 30
      - type: Pods
        value: 2
        periodSeconds: 30
      selectPolicy: Max

---
apiVersion: policy/v1
kind: PodDisruptionBudget
metadata:
  name: message-service-pdb
  namespace: chat4all
spec:
  minAvailable: 2
  selector:
    matchLabels:
      app: message-service
```

#### 3.4.3 Prometheus Configuration

```yaml
# monitoring/prometheus/prometheus.yml

global:
  scrape_interval: 15s
  evaluation_interval: 15s
  external_labels:
    cluster: 'chat4all-prod'
    environment: 'production'

alerting:
  alertmanagers:
  - static_configs:
    - targets:
      - alertmanager:9093

rule_files:
  - '/etc/prometheus/alerts.yml'

scrape_configs:
  # Scrape Prometheus itself
  - job_name: 'prometheus'
    static_configs:
    - targets: ['localhost:9090']

  # Services (via Kubernetes service discovery)
  - job_name: 'kubernetes-services'
    kubernetes_sd_configs:
    - role: service
      namespaces:
        names:
        - chat4all
    relabel_configs:
    - source_labels: [__meta_kubernetes_service_annotation_prometheus_io_scrape]
      action: keep
      regex: true
    - source_labels: [__meta_kubernetes_service_annotation_prometheus_io_path]
      action: replace
      target_label: __metrics_path__
      regex: (.+)
    - source_labels: [__address__, __meta_kubernetes_service_annotation_prometheus_io_port]
      action: replace
      target_label: __address__
      regex: ([^:]+)(?::\d+)?;(\d+)
      replacement: $1:$2

  # Pods (gRPC services)
  - job_name: 'kubernetes-pods'
    kubernetes_sd_configs:
    - role: pod
      namespaces:
        names:
        - chat4all
    relabel_configs:
    - source_labels: [__meta_kubernetes_pod_annotation_prometheus_io_scrape]
      action: keep
      regex: true
    - source_labels: [__meta_kubernetes_pod_annotation_prometheus_io_port]
      action: replace
      target_label: __address__
      regex: ([^:]+)(?::\d+)?;(\d+)
      replacement: $1:$2
    - source_labels: [__meta_kubernetes_pod_label_app]
      target_label: app
    - source_labels: [__meta_kubernetes_pod_label_version]
      target_label: version

  # Kafka exporters
  - job_name: 'kafka'
    static_configs:
    - targets: ['kafka-exporter:9308']

  # MongoDB exporters
  - job_name: 'mongodb'
    static_configs:
    - targets: ['mongodb-exporter:9216']

  # Redis exporters
  - job_name: 'redis'
    static_configs:
    - targets: ['redis-exporter:9121']

  # Node exporters
  - job_name: 'node'
    static_configs:
    - targets: ['node-exporter:9100']
```

```yaml
# monitoring/prometheus/alerts.yml

groups:
- name: chat4all_alerts
  interval: 30s
  rules:
  
  # Alta latência em mensagens
  - alert: HighMessageLatency
    expr: histogram_quantile(0.99, rate(message_service_send_duration_seconds_bucket[5m])) > 0.5
    for: 5m
    labels:
      severity: warning
      component: message-service
    annotations:
      summary: "Alta latência no envio de mensagens"
      description: "P99 latência é {{ $value }}s (threshold: 0.5s)"

  # Taxa de erro elevada
  - alert: HighErrorRate
    expr: rate(messages_sent_errors_total[5m]) / rate(messages_sent_total[5m]) > 0.05
    for: 5m
    labels:
      severity: critical
      component: message-service
    annotations:
      summary: "Taxa de erro elevada no envio de mensagens"
      description: "{{ $value | humanizePercentage }} das mensagens estão fallhando"

  # Kafka consumer lag
  - alert: KafkaConsumerLag
    expr: kafka_consumer_lag > 10000
    for: 10m
    labels:
      severity: warning
      component: kafka
    annotations:
      summary: "Consumer lag elevado no Kafka"
      description: "Lag de {{ $value }} mensagens no consumer {{ $labels.consumer_group }}"

  # MongoDB replicação atrasada
  - alert: MongoDBReplicationLag
    expr: mongodb_replset_member_replication_lag > 10
    for: 5m
    labels:
      severity: warning
      component: mongodb
    annotations:
      summary: "Replicação MongoDB atrasada"
      description: "Membro {{ $labels.member }} está {{ $value }}s atrás do primary"

  # Pods crashando
  - alert: PodCrashLooping
    expr: rate(kube_pod_container_status_restarts_total[15m]) > 0
    for: 5m
    labels:
      severity: critical
    annotations:
      summary: "Pod em crash loop"
      description: "Pod {{ $labels.pod }} está reiniciando frequentemente"

  # CPU usage alto
  - alert: HighCPUUsage
    expr: rate(container_cpu_usage_seconds_total{namespace="chat4all"}[5m]) > 0.9
    for: 10m
    labels:
      severity: warning
    annotations:
      summary: "Uso alto de CPU"
      description: "Container {{ $labels.container }} usando {{ $value | humanizePercentage }} de CPU"

  # Memory usage alto
  - alert: HighMemoryUsage
    expr: container_memory_usage_bytes{namespace="chat4all"} / container_spec_memory_limit_bytes > 0.9
    for: 5m
    labels:
      severity: warning
    annotations:
      summary: "Uso alto de memória"
      description: "Container {{ $labels.container }} usando {{ $value | humanizePercentage }} de memória"

  # Connector down
  - alert: ConnectorDown
    expr: up{job="connectors"} == 0
    for: 2m
    labels:
      severity: critical
      component: connectors
    annotations:
      summary: "Connector offline"
      description: "Connector {{ $labels.instance }} está down"

  # Circuit breaker aberto
  - alert: CircuitBreakerOpen
    expr: circuit_breaker_state{state="open"} == 1
    for: 5m
    labels:
      severity: warning
    annotations:
      summary: "Circuit breaker aberto"
      description: "Circuit breaker para {{ $labels.service }} está aberto"
```

### 3.5 Testes e Validação

#### 3.5.1 Script de Teste de Carga (k6)

```javascript
// tests/load/k6-scenarios/message-load.js

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

// Métricas customizadas
const errorRate = new Rate('errors');
const messageLatency = new Trend('message_latency');
const messagesPerSecond = new Counter('messages_sent');

// Configuração do teste
export const options = {
  stages: [
    { duration: '2m', target: 100 },   // Ramp-up para 100 VUs
    { duration: '5m', target: 100 },   // Mantém 100 VUs
    { duration: '2m', target: 500 },   // Ramp-up para 500 VUs
    { duration: '5m', target: 500 },   // Mantém 500 VUs
    { duration: '2m', target: 1000 },  // Ramp-up para 1000 VUs
    { duration: '5m', target: 1000 },  // Mantém 1000 VUs
    { duration: '2m', target: 0 },     // Ramp-down
  ],
  thresholds: {
    http_req_duration: ['p(95)<200', 'p(99)<500'], // 95% < 200ms, 99% < 500ms
    errors: ['rate<0.05'],                          // Taxa de erro < 5%
    http_req_failed: ['rate<0.05'],
  },
};

const BASE_URL = __ENV.BASE_URL || 'http://localhost:8080';
const AUTH_TOKEN = __ENV.AUTH_TOKEN;

// Setup: executado uma vez antes do teste
export function setup() {
  // Login e obter token
  const loginRes = http.post(`${BASE_URL}/auth/token`, JSON.stringify({
    client_id: 'test_client',
    client_secret: 'test_secret'
  }), {
    headers: { 'Content-Type': 'application/json' },
  });
  
  const token = JSON.parse(loginRes.body).access_token;
  
  // Criar conversas de teste
  const conversations = [];
  for (let i = 0; i < 100; i++) {
    const convRes = http.post(`${BASE_URL}/v1/conversations`, JSON.stringify({
      type: 'private',
      members: [`user_${i}_a`, `user_${i}_b`],
      metadata: {}
    }), {
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
    });
    
    if (convRes.status === 200) {
      conversations.push(JSON.parse(convRes.body).conversation_id);
    }
  }
  
  return { token, conversations };
}

// Função principal de teste (executada por cada VU)
export default function(data) {
  const { token, conversations } = data;
  
  // Selecionar conversa aleatória
  const conversationId = conversations[Math.floor(Math.random() * conversations.length)];
  
  // Gerar message_id único
  const messageId = `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
  
  // Payload da mensagem
  const payload = {
    message_id: messageId,
    conversation_id: conversationId,
    from: 'load_test_user',
    to: ['recipient_user'],
    channels: ['internal'],
    payload: {
      type: 'text',
      text: `Load test message ${messageId} - ${new Date().toISOString()}`
    },
    metadata: {
      test: 'load_test',
      timestamp: Date.now()
    }
  };
  
  // Enviar mensagem
  const startTime = Date.now();
  const res = http.post(`${BASE_URL}/v1/messages`, JSON.stringify(payload), {
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`
    },
  });
  const duration = Date.now() - startTime;
  
  // Registrar métricas
  messageLatency.add(duration);
  messagesPerSecond.add(1);
  
  // Validar resposta
  const success = check(res, {
    'status is 200': (r) => r.status === 200,
    'has message_id': (r) => JSON.parse(r.body).message_id !== undefined,
    'status is accepted': (r) => JSON.parse(r.body).status === 'accepted',
    'response time < 500ms': () => duration < 500,
  });
  
  if (!success) {
    errorRate.add(1);
    console.error(`Failed request: ${res.status} - ${res.body}`);
  }
  
  // Think time (simular comportamento humano)
  sleep(Math.random() * 2 + 1); // 1-3 segundos
}

// Teardown: executado uma vez após o teste
export function teardown(data) {
  console.log('Test completed. Summary:');
  console.log(`- Total conversations: ${data.conversations.length}`);
  console.log(`- Auth token: ${data.token.substring(0, 20)}...`);
}
```

#### 3.5.2 Teste de Integração (PHPUnit)

```php
<?php
// tests/integration/MessageFlowTest.php

declare(strict_types=1);

namespace Chat4All\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Chat4All\Message\V1\MessageServiceClient;
use Chat4All\Message\V1\SendMessageRequest;
use Chat4All\Message\V1\MessagePayload;
use Chat4All\Message\V1\PayloadType;
use Grpc\ChannelCredentials;
use Ramsey\Uuid\Uuid;

class MessageFlowTest extends TestCase
{
    private MessageServiceClient $client;
    private string $conversationId;
    
    protected function setUp(): void
    {
        // Conectar ao serviço gRPC
        $this->client = new MessageServiceClient(
            'localhost:50052',
            ['credentials' => ChannelCredentials::createInsecure()]
        );
        
        // Criar conversa de teste
        $this->conversationId = $this->createTestConversation();
    }
    
    /**
     * @test
     * Cenário: Enviar mensagem de texto
     * Esperado: Mensagem aceita e persistida
     */
    public function testSendTextMessage(): void
    {
        // Arrange
        $messageId = Uuid::uuid4()->toString();
        
        $payload = new MessagePayload();
        $payload->setType(PayloadType::TEXT);
        $payload->setText('Hello, integration test!');
        
        $request = new SendMessageRequest();
        $request->setMessageId($messageId);
        $request->setConversationId($this->conversationId);
        $request->setFromUserId('test_user_1');
        $request->setToUserIds(['test_user_2']);
        $request->setTargetChannels(['internal']);
        $request->setPayload($payload);
        
        // Act
        [$response, $status] = $this->client->SendMessage($request)->wait();
        
        // Assert
        $this->assertEquals(\Grpc\STATUS_OK, $status->code);
        $this->assertEquals($messageId, $response->getMessageId());
        $this->assertEquals('SENT', $response->getStatus());
        $this->assertNotNull($response->getAcceptedAt());
        
        // Verificar persistência
        sleep(1); // Aguardar processamento assíncrono
        $this->assertMessageExists($messageId);
    }
    
    /**
     * @test
     * Cenário: Enviar mensagem duplicada (idempotência)
     * Esperado: Segunda chamada retorna mesma mensagem sem duplicar
     */
    public function testIdempotentSend(): void
    {
        // Arrange
        $messageId = Uuid::uuid4()->toString();
        $request = $this->buildTestRequest($messageId);
        
        // Act - primeira chamada
        [$response1, $status1] = $this->client->SendMessage($request)->wait();
        $this->assertEquals(\Grpc\STATUS_OK, $status1->code);
        
        // Act - segunda chamada (duplicada)
        [$response2, $status2] = $this->client->SendMessage($request)->wait();
        
        // Assert
        $this->assertEquals(\Grpc\STATUS_OK, $status2->code);
        $this->assertEquals($response1->getMessageId(), $response2->getMessageId());
        
        // Verificar que existe apenas uma mensagem no DB
        $count = $this->countMessagesWithId($messageId);
        $this->assertEquals(1, $count);
    }
    
    /**
     * @test
     * Cenário: Enviar 1000 mensagens concorrentemente
     * Esperado: Todas aceitas, nenhuma perdida
     */
    public function testConcurrentSend(): void
    {
        $messageCount = 1000;
        $messageIds = [];
        $promises = [];
        
        // Arrange - criar múltiplas requests
        for ($i = 0; $i < $messageCount; $i++) {
            $messageId = Uuid::uuid4()->toString();
            $messageIds[] = $messageId;
            
            $request = $this->buildTestRequest($messageId, "Message $i");
            
            // Act - enviar de forma assíncrona
            $promises[] = $this->client->SendMessage($request);
        }
        
        // Aguardar todas as respostas
        $successful = 0;
        foreach ($promises as $promise) {
            [$response, $status] = $promise->wait();
            if ($status->code === \Grpc\STATUS_OK) {
                $successful++;
            }
        }
        
        // Assert
        $this->assertEquals($messageCount, $successful);
        
        // Aguardar processamento
        sleep(5);
        
        // Verificar persistência de todas
        foreach ($messageIds as $messageId) {
            $this->assertMessageExists($messageId);
        }
    }
    
    /**
     * @test
     * Cenário: Enviar mensagem para múltiplos canais
     * Esperado: Mensagem roteada para todos os canais
     */
    public function testMultiChannelDelivery(): void
    {
        // Arrange
        $messageId = Uuid::uuid4()->toString();
        $request = $this->buildTestRequest($messageId);
        $request->setTargetChannels(['internal', 'telegram', 'whatsapp']);
        
        // Act
        [$response, $status] = $this->client->SendMessage($request)->wait();
        $this->assertEquals(\Grpc\STATUS_OK, $status->code);
        
        // Assert - aguardar processamento
        sleep(3);
        
        $message = $this->getMessage($messageId);
        $this->assertArrayHasKey('channel_delivery', $message);
        $this->assertArrayHasKey('internal', $message['channel_delivery']);
        $this->assertArrayHasKey('telegram', $message['channel_delivery']);
        $this->assertArrayHasKey('whatsapp', $message['channel_delivery']);
    }
    
    // Métodos auxiliares
    
    private function buildTestRequest(string $messageId, string $text = 'Test message'): SendMessageRequest
    {
        $payload = new MessagePayload();
        $payload->setType(PayloadType::TEXT);
        $payload->setText($text);
        
        $request = new SendMessageRequest();
        $request->setMessageId($messageId);
        $request->setConversationId($this->conversationId);
        $request->setFromUserId('test_user_1');
        $request->setToUserIds(['test_user_2']);
        $request->setTargetChannels(['internal']);
        $request->setPayload($payload);
        
        return $request;
    }
    
    private function assertMessageExists(string $messageId): void
    {
        $mongo = new \MongoDB\Client('mongodb://admin:admin_secret@localhost:27017');
        $collection = $mongo->chat4all->messages;
        
        $message = $collection->findOne(['message_id' => $messageId]);
        $this->assertNotNull($message, "Message {$messageId} not found in database");
    }
    
    private function getMessage(string $messageId): array
    {
        $mongo = new \MongoDB\Client('mongodb://admin:admin_secret@localhost:27017');
        $collection = $mongo->chat4all->messages;
        
        $message = $collection->findOne(['message_id' => $messageId]);
        return json_decode(json_encode($message), true);
    }
    
    private function countMessagesWithId(string $messageId): int
    {
        $mongo = new \MongoDB\Client('mongodb://admin:admin_secret@localhost:27017');
        $collection = $mongo->chat4all->messages;
        
        return $collection->countDocuments(['message_id' => $messageId]);
    }
    
    private function createTestConversation(): string
    {
        // Criar via API REST ou diretamente no DB
        $conversationId = Uuid::uuid4()->toString();
        
        $postgres = new \PDO('pgsql:host=localhost;dbname=chat4all', 'chat4all', 'chat4all_secret');
        $postgres->exec("
            INSERT INTO conversations (conversation_id, type, created_by)
            VALUES ('$conversationId', 'private', 'test_user_1')
        ");
        
        return $conversationId;
    }
}
```

## 4. Roadmap de Implementação Detalhado

### Semana 1-2: Fundação e Setup

**Objetivos:**

- Setup do ambiente de desenvolvimento
- Esqueleto dos serviços
- Infraestrutura básica local

**Tarefas:**

1. **Dia 1-2: Setup do projeto**
    - Criar estrutura de diretórios
    - Configurar repositório Git
    - Setup Docker Compose para desenvolvimento
    - Configurar CI/CD básico (GitHub Actions)
2. **Dia 3-5: Databases e Messaging**
    - Deploy PostgreSQL + scripts de inicialização
    - Deploy MongoDB + configuração de sharding
    - Deploy Redis cluster
    - Deploy Kafka + criar topics
3. **Dia 6-8: Protobuf e gRPC**
    - Definir todos os `.proto` files
    - Gerar código PHP para todos os serviços
    - Criar esqueleto dos serviços gRPC
    - Implementar health checks
4. **Dia 9-10: Shared libraries**
    - Implementar conexões de banco
    - Implementar Kafka producer/consumer
    - Implementar logging estruturado
    - Implementar métricas básicas
5. **Dia 11-14: Auth Service**
    - Implementar autenticação JWT
    - Implementar registro de usuários
    - Implementar gerenciamento de tokens
    - Testes unitários

**Entregáveis:**

- Infraestrutura rodando localmente
- Auth Service funcional
- Documentação de setup

### Semana 3-4: Core Messaging

**Objetivos:**

- Message Service completo
- Conversation Service
- Router Worker básico

**Tarefas:**

1. **Dia 15-18: Message Service**
    - Implementar SendMessage
    - Implementar GetMessages com paginação
    - Implementar persistência MongoDB
    - Integração com Kafka
2. **Dia 19-21: Conversation Service**
    - CRUD de conversas
    - Gerenciamento de membros
    - Persistência PostgreSQL
    - Testes de integração
3. **Dia 22-25: Router Worker**
    - Consumer do Kafka
    - Lógica de roteamento
    - Resolução de canais
    - Atualização de status
4. **Dia 26-28: Integration Tests**
    - Teste end-to-end de envio de mensagem
    - Teste de idempotência
    - Teste de ordering

**Entregáveis:**

- Mensageria básica funcionando
- Testes de integração passando
- Documentação de API

### Semana 5-6: Files e Connectors

**Objetivos:**

- File Service com uploads grandes
- Connector framework
- Pelo menos 1 connector real (Telegram)

**Tarefas:**

1. **Dia 29-32: File Service**
    - Implementar upload resumable
    - Integração com MinIO
    - Chunking e reassembly
    - Metadados em MongoDB
2. **Dia 33-35: Connector Framework**
    - Interface padronizada
    - Registry de connectors
    - Circuit breaker pattern
    - Mock connector para testes
3. **Dia 36-39: Telegram Connector**
    - Integração com Telegram Bot API
    - Send message/file
    - Webhook handler
    - Testes com bot real
4. **Dia 40-42: Cross-Channel Testing**
    - Teste de envio cross-channel
    - Teste de entrega múltipla
    - Demonstração WhatsApp→Instagram (mock)

**Entregáveis:**

- Upload/download de arquivos até 2GB funcionando
- Telegram connector operacional
- Demo cross-channel

### Semana 7-8: Observabilidade, Performance e Finalização

**Objetivos:**

- Monitoramento completo
- Testes de carga
- Failover e HA
- Relatório técnico

**Tarefas:**

1. **Dia 43-46: Observabilidade**
    
    - Configurar Prometheus + Grafana
    - Criar dashboards principais
    - Implementar tracing com Jaeger
    - Configurar alertas críticos
    - ELK stack para logs centralizados
2. **Dia 47-50: Testes de Performance**
    
    - Implementar testes k6
    - Executar teste de carga (100k msgs/min)
    - Benchmark de latência
    - Teste de arquivos grandes
    - Otimizações baseadas em resultados
3. **Dia 51-53: Alta Disponibilidade**
    
    - Configurar replicação PostgreSQL
    - Configurar MongoDB replica set
    - Configurar Redis sentinel
    - Teste de failover de cada componente
    - Demonstração de auto-recovery
4. **Dia 54-56: Kubernetes e Scaling**
    
    - Criar manifests K8s completos
    - Configurar HPA (Horizontal Pod Autoscaler)
    - Teste de scale-out em runtime
    - Demonstração de adição de nós
5. **Dia 57-60: Documentação e Relatório**
    
    - Finalizar documentação OpenAPI
    - Escrever relatório técnico (15 páginas)
    - Preparar scripts de demo
    - Capturar screenshots/logs
    - Gravar vídeo de demonstração (opcional)

**Entregáveis Finais:**

- Sistema completo em produção
- Dashboards de monitoramento
- Relatório técnico detalhado
- Demonstrações funcionais
- Código-fonte documentado

## 5. Detalhamento de Componentes Críticos

### 5.1 Deduplication Service (Garantia de Idempotência)

```php
<?php
// shared/src/Deduplication/DeduplicationService.php

declare(strict_types=1);

namespace Chat4All\Shared\Deduplication;

use Chat4All\Shared\Database\RedisConnection;
use Chat4All\Shared\Logging\Logger;

/**
 * Serviço de deduplicação de mensagens
 * 
 * Usa Redis SET com TTL para rastrear message_ids processados.
 * Garante exactly-once processing através de idempotência.
 */
class DeduplicationService
{
    private const TTL_SECONDS = 3600; // 1 hora
    private const KEY_PREFIX = 'dedup:msg:';
    
    public function __construct(
        private readonly RedisConnection $redis,
        private readonly Logger $logger
    ) {}
    
    /**
     * Verifica se uma mensagem já foi processada
     */
    public function isDuplicate(string $messageId): bool
    {
        $key = self::KEY_PREFIX . $messageId;
        
        // EXISTS retorna 1 se existe, 0 caso contrário
        $exists = $this->redis->exists($key);
        
        if ($exists) {
            $this->logger->debug('Duplicate message detected', [
                'message_id' => $messageId
            ]);
        }
        
        return (bool) $exists;
    }
    
    /**
     * Marca uma mensagem como processada
     * 
     * Usa SET NX (SET if Not eXists) para garantir atomicidade.
     * Se múltiplos workers tentarem processar simultaneamente,
     * apenas um terá sucesso.
     */
    public function markAsProcessed(string $messageId): bool
    {
        $key = self::KEY_PREFIX . $messageId;
        
        // SET NX EX: set if not exists with expiration
        $result = $this->redis->set(
            $key,
            json_encode([
                'processed_at' => time(),
                'ttl' => self::TTL_SECONDS
            ]),
            ['NX', 'EX' => self::TTL_SECONDS]
        );
        
        if ($result) {
            $this->logger->debug('Message marked as processed', [
                'message_id' => $messageId
            ]);
            return true;
        }
        
        // Falhou - outra thread já processou
        $this->logger->warning('Failed to mark as processed (race condition)', [
            'message_id' => $messageId
        ]);
        return false;
    }
    
    /**
     * Remove marca de deduplicação (para testes ou retry manual)
     */
    public function reset(string $messageId): void
    {
        $key = self::KEY_PREFIX . $messageId;
        $this->redis->del($key);
        
        $this->logger->info('Deduplication reset', [
            'message_id' => $messageId
        ]);
    }
    
    /**
     * Obtém estatísticas de deduplicação
     */
    public function getStats(): array
    {
        // Scan para contar keys (usar cursor para não bloquear)
        $cursor = 0;
        $count = 0;
        
        do {
            [$cursor, $keys] = $this->redis->scan(
                $cursor,
                self::KEY_PREFIX . '*',
                100
            );
            $count += count($keys);
        } while ($cursor != 0);
        
        return [
            'tracked_messages' => $count,
            'ttl_seconds' => self::TTL_SECONDS
        ];
    }
}
```

### 5.2 Channel Resolver (Roteamento de Canais)

```php
<?php
// workers/router-worker/src/ChannelResolver.php

declare(strict_types=1);

namespace Chat4All\Workers\Router;

use Chat4All\Shared\Database\PostgresConnection;
use Chat4All\Shared\Database\RedisConnection;
use Chat4All\Shared\Logging\Logger;

/**
 * Resolve canais de entrega para cada usuário
 * 
 * Mapeia usuários internos para seus canais externos configurados
 * e determina a melhor estratégia de entrega.
 */
class ChannelResolver
{
    private const CACHE_TTL = 300; // 5 minutos
    
    public function __construct(
        private readonly PostgresConnection $postgres,
        private readonly RedisConnection $redis,
        private readonly Logger $logger
    ) {}
    
    /**
     * Resolve canais para um usuário
     * 
     * @param string $userId ID do usuário interno
     * @param array $targetChannels Canais desejados ['whatsapp', 'telegram', 'internal']
     * @return array Lista de canais disponíveis para o usuário
     */
    public function resolveUserChannels(string $userId, array $targetChannels): array
    {
        // Tentar do cache primeiro
        $cacheKey = "user_channels:{$userId}";
        $cached = $this->redis->get($cacheKey);
        
        if ($cached) {
            $allChannels = json_decode($cached, true);
        } else {
            // Buscar do PostgreSQL
            $allChannels = $this->fetchUserChannelsFromDB($userId);
            
            // Cachear
            $this->redis->setex(
                $cacheKey,
                self::CACHE_TTL,
                json_encode($allChannels)
            );
        }
        
        // Filtrar apenas os canais solicitados
        $resolvedChannels = array_filter(
            $allChannels,
            fn($channel) => in_array($channel['channel_type'], $targetChannels)
        );
        
        // Se nenhum canal externo configurado, usar apenas internal
        if (empty($resolvedChannels) && in_array('internal', $targetChannels)) {
            $resolvedChannels[] = [
                'channel_type' => 'internal',
                'channel_user_id' => $userId,
                'verified' => true
            ];
        }
        
        $this->logger->debug('Channels resolved', [
            'user_id' => $userId,
            'requested' => $targetChannels,
            'resolved' => array_column($resolvedChannels, 'channel_type')
        ]);
        
        return $resolvedChannels;
    }
    
    /**
     * Busca canais do usuário do banco de dados
     */
    private function fetchUserChannelsFromDB(string $userId): array
    {
        $stmt = $this->postgres->prepare("
            SELECT 
                channel_type,
                channel_user_id,
                channel_metadata,
                verified
            FROM user_channels
            WHERE user_id = :user_id
              AND verified = true
            ORDER BY channel_type
        ");
        
        $stmt->execute(['user_id' => $userId]);
        
        $channels = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $channels[] = [
                'channel_type' => $row['channel_type'],
                'channel_user_id' => $row['channel_user_id'],
                'metadata' => json_decode($row['channel_metadata'], true),
                'verified' => (bool) $row['verified']
            ];
        }
        
        return $channels;
    }
    
    /**
     * Obtém todos os canais habilitados globalmente
     */
    public function getAllEnabledChannels(): array
    {
        $cacheKey = 'enabled_channels';
        $cached = $this->redis->get($cacheKey);
        
        if ($cached) {
            return json_decode($cached, true);
        }
        
        $stmt = $this->postgres->query("
            SELECT channel_type
            FROM channel_configs
            WHERE is_enabled = true
        ");
        
        $channels = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        
        // Sempre incluir internal
        if (!in_array('internal', $channels)) {
            $channels[] = 'internal';
        }
        
        $this->redis->setex($cacheKey, 600, json_encode($channels));
        
        return $channels;
    }
    
    /**
     * Invalida cache de canais de um usuário
     */
    public function invalidateUserCache(string $userId): void
    {
        $this->redis->del("user_channels:{$userId}");
        
        $this->logger->info('User channels cache invalidated', [
            'user_id' => $userId
        ]);
    }
}
```

### 5.3 File Chunking Manager (Upload Resumable)

```php
<?php
// services/file-service/src/Storage/ChunkManager.php

declare(strict_types=1);

namespace Chat4All\FileService\Storage;

use Chat4All\Shared\Database\MongoConnection;
use Chat4All\Shared\Logging\Logger;
use Chat4All\FileService\Exception\ChunkException;

/**
 * Gerenciador de chunks para uploads resumable
 * 
 * Implementa protocolo similar ao TUS (Resumable Upload Protocol).
 * Permite uploads de arquivos grandes (até 2GB) com capacidade de retry.
 */
class ChunkManager
{
    private const CHUNK_SIZE = 5 * 1024 * 1024; // 5MB por chunk
    
    public function __construct(
        private readonly MongoConnection $mongo,
        private readonly MinioStorage $storage,
        private readonly Logger $logger
    ) {}
    
    /**
     * Iniciar upload de arquivo grande
     * 
     * @param string $fileId UUID do arquivo
     * @param string $filename Nome original
     * @param int $totalSize Tamanho total em bytes
     * @param string $mimeType MIME type
     * @return array Informações do upload
     */
    public function initiateUpload(
        string $fileId,
        string $filename,
        int $totalSize,
        string $mimeType
    ): array {
        // Validar tamanho
        if ($totalSize > 2 * 1024 * 1024 * 1024) { // 2GB
            throw new ChunkException('File size exceeds maximum (2GB)');
        }
        
        // Calcular número de chunks
        $totalChunks = (int) ceil($totalSize / self::CHUNK_SIZE);
        
        // Criar documento no MongoDB
        $document = [
            'file_id' => $fileId,
            'original_filename' => $filename,
            'size_bytes' => $totalSize,
            'mime_type' => $mimeType,
            'total_chunks' => $totalChunks,
            'uploaded_chunks' => [],
            'upload_status' => 'initiated',
            'storage' => [
                'provider' => 'minio',
                'bucket' => 'chat4all-files',
                'key' => $this->generateStorageKey($fileId, $filename)
            ],
            'created_at' => new \MongoDB\BSON\UTCDateTime(),
            'updated_at' => new \MongoDB\BSON\UTCDateTime()
        ];
        
        $this->mongo->getCollection('file_metadata')->insertOne($document);
        
        $this->logger->info('Upload initiated', [
            'file_id' => $fileId,
            'filename' => $filename,
            'size' => $totalSize,
            'total_chunks' => $totalChunks
        ]);
        
        return [
            'file_id' => $fileId,
            'upload_url' => "/v1/files/{$fileId}/chunks",
            'chunk_size' => self::CHUNK_SIZE,
            'total_chunks' => $totalChunks
        ];
    }
    
    /**
     * Upload de um chunk específico
     * 
     * @param string $fileId UUID do arquivo
     * @param int $chunkNumber Número do chunk (0-indexed)
     * @param string $chunkData Dados binários do chunk
     * @return array Status do upload
     */
    public function uploadChunk(
        string $fileId,
        int $chunkNumber,
        string $chunkData
    ): array {
        // Buscar metadata
        $metadata = $this->getFileMetadata($fileId);
        
        if (!$metadata) {
            throw new ChunkException('File not found');
        }
        
        if ($metadata['upload_status'] === 'completed') {
            throw new ChunkException('Upload already completed');
        }
        
        // Validar chunk number
        if ($chunkNumber < 0 || $chunkNumber >= $metadata['total_chunks']) {
            throw new ChunkException('Invalid chunk number');
        }
        
        // Calcular checksum do chunk
        $checksum = hash('sha256', $chunkData);
        
        // Armazenar chunk temporariamente no MinIO
        $chunkKey = "{$metadata['storage']['key']}.chunk.{$chunkNumber}";
        $this->storage->putObject(
            bucket: $metadata['storage']['bucket'],
            key: $chunkKey,
            data: $chunkData
        );
        
        // Atualizar metadata no MongoDB
        $this->mongo->getCollection('file_metadata')->updateOne(
            ['file_id' => $fileId],
            [
                '$push' => [
                    'uploaded_chunks' => [
                        'chunk_number' => $chunkNumber,
                        'size_bytes' => strlen($chunkData),
                        'checksum' => $checksum,
                        'uploaded_at' => new \MongoDB\BSON\UTCDateTime()
                    ]
                ],
                '$set' => [
                    'upload_status' => 'in_progress',
                    'updated_at' => new \MongoDB\BSON\UTCDateTime()
                ]
            ]
        );
        
        $this->logger->info('Chunk uploaded', [
            'file_id' => $fileId,
            'chunk_number' => $chunkNumber,
            'size' => strlen($chunkData),
            'checksum' => $checksum
        ]);
        
        // Verificar se todos os chunks foram enviados
        $uploadedCount = count($metadata['uploaded_chunks']) + 1;
        if ($uploadedCount === $metadata['total_chunks']) {
            return $this->finalizeUpload($fileId);
        }
        
        return [
            'file_id' => $fileId,
            'chunk_number' => $chunkNumber,
            'uploaded_chunks' => $uploadedCount,
            'total_chunks' => $metadata['total_chunks'],
            'complete' => false
        ];
    }
    
    /**
     * Finalizar upload - concatenar chunks
     */
    private function finalizeUpload(string $fileId): array
    {
        $metadata = $this->getFileMetadata($fileId);
        
        $this->logger->info('Finalizing upload', [
            'file_id' => $fileId
        ]);
        
        // Ordenar chunks
        $chunks = $metadata['uploaded_chunks'];
        usort($chunks, fn($a, $b) => $a['chunk_number'] <=> $b['chunk_number']);
        
        // Criar arquivo final concatenando chunks
        $finalKey = $metadata['storage']['key'];
        $bucket = $metadata['storage']['bucket'];
        
        // Usar MinIO Compose Object API (equivalente a AWS S3 multipart complete)
        $sources = [];
        foreach ($chunks as $chunk) {
            $chunkKey = "{$finalKey}.chunk.{$chunk['chunk_number']}";
            $sources[] = [
                'bucket' => $bucket,
                'object' => $chunkKey
            ];
        }
        
        $this->storage->composeObject(
            bucket: $bucket,
            key: $finalKey,
            sources: $sources
        );
        
        // Calcular checksum do arquivo completo
        $finalChecksum = $this->storage->getObjectChecksum($bucket, $finalKey);
        
        // Deletar chunks temporários
        foreach ($chunks as $chunk) {
            $chunkKey = "{$finalKey}.chunk.{$chunk['chunk_number']}";
            $this->storage->deleteObject($bucket, $chunkKey);
        }
        
        // Atualizar metadata
        $this->mongo->getCollection('file_metadata')->updateOne(
            ['file_id' => $fileId],
            [
                '$set' => [
                    'upload_status' => 'completed',
                    'checksum' => $finalChecksum,
                    'storage.url' => $this->storage->getObjectUrl($bucket, $finalKey),
                    'upload_completed_at' => new \MongoDB\BSON\UTCDateTime(),
                    'updated_at' => new \MongoDB\BSON\UTCDateTime()
                ]
            ]
        );
        
        $this->logger->info('Upload completed', [
            'file_id' => $fileId,
            'checksum' => $finalChecksum
        ]);
        
        return [
            'file_id' => $fileId,
            'complete' => true,
            'checksum' => $finalChecksum,
            'url' => $this->storage->getObjectUrl($bucket, $finalKey)
        ];
    }
    
    /**
     * Obter status de upload
     */
    public function getUploadStatus(string $fileId): array
    {
        $metadata = $this->getFileMetadata($fileId);
        
        if (!$metadata) {
            throw new ChunkException('File not found');
        }
        
        $uploadedChunks = $metadata['uploaded_chunks'] ?? [];
        $uploadedNumbers = array_column($uploadedChunks, 'chunk_number');
        
        // Calcular quais chunks faltam
        $missingChunks = [];
        for ($i = 0; $i < $metadata['total_chunks']; $i++) {
            if (!in_array($i, $uploadedNumbers)) {
                $missingChunks[] = $i;
            }
        }
        
        return [
            'file_id' => $fileId,
            'filename' => $metadata['original_filename'],
            'size_bytes' => $metadata['size_bytes'],
            'status' => $metadata['upload_status'],
            'uploaded_chunks' => count($uploadedChunks),
            'total_chunks' => $metadata['total_chunks'],
            'missing_chunks' => $missingChunks,
            'progress_percentage' => round(
                (count($uploadedChunks) / $metadata['total_chunks']) * 100,
                2
            )
        ];
    }
    
    private function getFileMetadata(string $fileId): ?array
    {
        $doc = $this->mongo->getCollection('file_metadata')
            ->findOne(['file_id' => $fileId]);
        
        return $doc ? json_decode(json_encode($doc), true) : null;
    }
    
    private function generateStorageKey(string $fileId, string $filename): string
    {
        $date = date('Y/m/d');
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        return "{$date}/{$fileId}-{$sanitized}";
    }
}
```

### 5.4 Presence Service (Online/Offline Status)

```php
<?php
// services/presence-service/src/PresenceServiceImpl.php

declare(strict_types=1);

namespace Chat4All\PresenceService;

use Chat4All\Presence\V1\PresenceServiceInterface;
use Chat4All\Presence\V1\UpdatePresenceRequest;
use Chat4All\Presence\V1\GetPresenceRequest;
use Chat4All\Presence\V1\PresenceStatus;
use Chat4All\Shared\Database\RedisConnection;
use Chat4All\Shared\Logging\Logger;

/**
 * Serviço de presença (online/offline/away)
 * 
 * Usa Redis Sorted Set com timestamp como score para rastreamento eficiente.
 * Cleanup automático de usuários inativos.
 */
class PresenceServiceImpl implements PresenceServiceInterface
{
    private const ONLINE_THRESHOLD = 300; // 5 minutos
    private const AWAY_THRESHOLD = 900;   // 15 minutos
    
    public function __construct(
        private readonly RedisConnection $redis,
        private readonly Logger $logger
    ) {}
    
    /**
     * Atualizar status de presença de um usuário
     */
    public function UpdatePresence(UpdatePresenceRequest $request): UpdatePresenceResponse
    {
        $userId = $request->getUserId();
        $status = $request->getStatus(); // ONLINE, AWAY, OFFLINE
        $timestamp = time();
        
        switch ($status) {
            case PresenceStatus::ONLINE:
                // Adicionar ao sorted set com timestamp atual
                $this->redis->zadd('presence:online', $timestamp, $userId);
                
                // Armazenar detalhes adicionais
                $this->redis->hset("presence:user:{$userId}", [
                    'status' => 'online',
                    'last_seen' => $timestamp,
                    'device' => $request->getDevice() ?? 'unknown'
                ]);
                $this->redis->expire("presence:user:{$userId}", self::ONLINE_THRESHOLD * 2);
                
                // Publicar evento
                $this->publishPresenceEvent($userId, 'online');
                break;
                
            case PresenceStatus::AWAY:
                $this->redis->hset("presence:user:{$userId}", [
                    'status' => 'away',
                    'last_seen' => $timestamp
                ]);
                $this->publishPresenceEvent($userId, 'away');
                break;
                
            case PresenceStatus::OFFLINE:
                // Remover do sorted set
                $this->redis->zrem('presence:online', $userId);
                
                // Atualizar detalhes
                $this->redis->hset("presence:user:{$userId}", [
                    'status' => 'offline',
                    'last_seen' => $timestamp
                ]);
                $this->redis->expire("presence:user:{$userId}", 86400); // 24h
                
                $this->publishPresenceEvent($userId, 'offline');
                break;
        }
        
        $this->logger->debug('Presence updated', [
            'user_id' => $userId,
            'status' => $status
        ]);
        
        $response = new UpdatePresenceResponse();
        $response->setSuccess(true);
        $response->setTimestamp($this->createTimestamp($timestamp));
        
        return $response;
    }
    
    /**
     * Obter status de presença de usuários
     */
    public function GetPresence(GetPresenceRequest $request): GetPresenceResponse
    {
        $userIds = iterator_to_array($request->getUserIds());
        $presences = [];
        
        foreach ($userIds as $userId) {
            $presence = $this->getUserPresence($userId);
            $presences[$userId] = $presence;
        }
        
        $response = new GetPresenceResponse();
        foreach ($presences as $userId => $presence) {
            $proto = new UserPresence();
            $proto->setUserId($userId);
            $proto->setStatus($presence['status']);
            $proto->setLastSeen($this->createTimestamp($presence['last_seen']));
            
            $response->addPresences($proto);
        }
        
        return $response;
    }
    
    /**
     * Obter presença de um usuário específico
     */
    private function getUserPresence(string $userId): array
    {
        // Buscar do hash
        $data = $this->redis->hgetall("presence:user:{$userId}");
        
        if (empty($data)) {
            return [
                'status' => PresenceStatus::OFFLINE,
                'last_seen' => 0
            ];
        }
        
        $lastSeen = (int) $data['last_seen'];
        $timeSinceLastSeen = time() - $lastSeen;
        
        // Determinar status baseado no tempo
        if ($data['status'] === 'online') {
            if ($timeSinceLastSeen > self::AWAY_THRESHOLD) {
                $status = PresenceStatus::OFFLINE;
            } elseif ($timeSinceLastSeen > self::ONLINE_THRESHOLD) {
                $status = PresenceStatus::AWAY;
            } else {
                $status = PresenceStatus::ONLINE;
            }
        } else {
            $status = $data['status'] === 'away' 
                ? PresenceStatus::AWAY 
                : PresenceStatus::OFFLINE;
        }
        
        return [
            'status' => $status,
            'last_seen' => $lastSeen
        ];
    }
    
    /**
     * Cleanup de usuários inativos (executar periodicamente)
     */
    public function cleanupInactiveUsers(): int
    {
        $threshold = time() - self::AWAY_THRESHOLD;
        
        // Remover usuários inativos do sorted set
        $removed = $this->redis->zremrangebyscore(
            'presence:online',
            '-inf',
            $threshold
        );
        
        if ($removed > 0) {
            $this->logger->info('Inactive users cleaned up', [
                'count' => $removed
            ]);
        }
        
        return $removed;
    }
    
    /**
     * Publicar evento de mudança de presença
     */
    private function publishPresenceEvent(string $userId, string $status): void
    {
        $event = json_encode([
            'user_id' => $userId,
            'status' => $status,
            'timestamp' => time()
        ]);
        
        // Pub/Sub para notificar clientes conectados
        $this->redis->publish('presence:updates', $event);
    }
}
```

## 6. Considerações Finais e Recomendações

### 6.1 Segurança

**Implementações Essenciais:**

1. **Autenticação e Autorização:**
    
    - JWT com RS256 (assinatura assimétrica)
    - Refresh tokens com rotation
    - Rate limiting por usuário/IP
    - OAuth2 para integrações externas
2. **Criptografia:**
    
    - TLS 1.3 para todas as conexões
    - Criptografia end-to-end (opcional, complexo)
    - Arquivos grandes: criptografia em repouso no MinIO
3. **Validação:**
    
    - Input validation em todas as APIs
    - Protobuf schema validation
    - Sanitização de dados antes de persistir
4. **Proteção contra ataques:**
    
    - CORS configurado corretamente
    - CSRF tokens para operações mutáveis
    - XSS prevention no frontend
    - SQL injection prevention (prepared statements)
    - NoSQL injection prevention (parametrização)

### 6.2 Performance e Otimizações

1. **Database:**
    
    - Indexes otimizados (analisar query plans)
    - Connection pooling (mínimo 10, máximo 100)
    - Read replicas para queries pesadas
    - Particionamento de tabelas grandes
2. **Caching:**
    
    - Cache L1 (in-memory na aplicação)
    - Cache L2 (Redis)
    - CDN para assets estáticos
    - HTTP caching headers
3. **Messaging:**
    
    - Batch processing de mensagens
    - Compression no Kafka (gzip ou lz4)
    - Tuning de consumer groups
4. **Network:**
    
    - HTTP/2 para multiplexing
    - gRPC connection pooling
    - Load balancer com health checks

### 6.3 Pontos de Atenção

**Desafios Técnicos:**

1. **Ordering Causal:** Garantir ordem por conversa mesmo com múltiplos workers
    
    - Solução: Partition Kafka por conversation_id + sequence_number
2. **Exactly-Once:** Difícil em sistemas distribuídos
    
    - Solução: At-least-once + idempotência + deduplicação
3. **File Storage:** 2GB é desafiador
    
    - Solução: Chunking + resumable + cleanup de chunks órfãos
4. **Cross-Channel:** APIs externas têm rate limits
    
    - Solução: Queue por canal + backoff exponencial + circuit breaker
5. **Schema Evolution:** Mudanças sem downtime
    
    - Solução: Protobuf field numbers + backward compatibility + blue-green deployment

### 6.4 Melhorias Futuras (Extra Credit)

1. **Message Encryption:** E2E encryption com Signal Protocol
2. **GraphQL API:** Alternativa ao REST para frontend
3. **WebRTC:** Chamadas de voz/vídeo
4. **ML/AI:** Detecção de spam, moderação de conteúdo
5. **Analytics:** Pipeline de dados para insights
6. **Multi-Region:** Replicação geográfica para baixa latência global
7. **Blockchain:** Proof of delivery imutável
