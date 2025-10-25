# Chat4All v2 - Design de Arquitetura, Modelagem de Dados e Esqueleto do Projeto

## 1. Design da Arquitetura

### 1.1 Visão Geral da Arquitetura

A arquitetura proposta segue um padrão de **Microsserviços com Event-Driven Architecture**, combinando os princípios de **CQRS (Command Query Responsibility Segregation)** e **Event Sourcing** parcial para garantir escalabilidade e rastreabilidade.

```
┌──────────────────────────────────────────────────────────────┐
│                    CAMADA DE ENTRADA                         │
├──────────────────────────────────────────────────────────────┤
│  Load Balancer (HAProxy/Nginx)                               │
│         ↓                    ↓                    ↓          │
│   API Gateway 1        API Gateway 2        API Gateway N    │
│  (Kong/Tyk/KrakenD)  (Kong/Tyk/KrakenD)   (Kong/Tyk/KrakenD) │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│                 CAMADA DE SERVIÇOS CORE                      │
├──────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐  │
│  │ Auth Service    │  │ Frontend Service│  │ Presence     │  │
│  │ (gRPC Server)   │  │ (gRPC Server)   │  │ Service      │  │
│  │ RoadRunner/PHP  │  │ RoadRunner/PHP  │  │ (gRPC/PHP)   │  │
│  └─────────────────┘  └─────────────────┘  └──────────────┘  │
│                                                              │
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐  │
│  │ Conversation    │  │ Message         │  │ File         │  │
│  │ Service         │  │ Service         │  │ Service      │  │
│  │ (gRPC Server)   │  │ (gRPC Server)   │  │ (gRPC/PHP)   │  │
│  └─────────────────┘  └─────────────────┘  └──────────────┘  │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│              CAMADA DE COMUNICAÇÃO REAL-TIME                 │
├──────────────────────────────────────────────────────────────┤
│  WebSocket Gateway (Swoole)                                  │
│  - Mantém conexões WebSocket dos clientes                    │
│  - Subscribe no Redis Pub/Sub                                │
│  - Envia mensagens real-time para Angular                    │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│                    CAMADA DE MENSAGERIA                      │
├──────────────────────────────────────────────────────────────┤
│              Apache Kafka (Event Stream)                     │
│  Topics: message.sent, message.delivered, message.read,      │
│          file.uploaded, user.presence, conversation.created  │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│                    CAMADA DE WORKERS                         │
├──────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐  │
│  │ Router Worker   │  │ Delivery Worker │  │ Status       │  │
│  │ (PHP Consumer)  │  │ (PHP Consumer)  │  │ Worker       │  │
│  └─────────────────┘  └─────────────────┘  └──────────────┘  │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│                  CAMADA DE CONNECTORS                        │
├──────────────────────────────────────────────────────────────┤
│  ┌─────────────────┐  ┌─────────────────┐  ┌──────────────┐  │
│  │ WhatsApp        │  │ Instagram       │  │ Telegram     │  │
│  │ Connector       │  │ Connector       │  │ Connector    │  │
│  │ (Plugin/PHP)    │  │ (Plugin/PHP)    │  │ (Plugin/PHP) │  │
│  └─────────────────┘  └─────────────────┘  └──────────────┘  │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│                  CAMADA DE PERSISTÊNCIA                      │
├──────────────────────────────────────────────────────────────┤
│  ┌──────────────────────┐  ┌───────────────────────────────┐ │
│  │ PostgreSQL Cluster   │  │ MongoDB Cluster               │ │
│  │ (Metadata, Users,    │  │ (Messages, Time Series)       │ │
│  │  Conversations)      │  │                               │ │
│  └──────────────────────┘  └───────────────────────────────┘ │
│                                                              │
│  ┌──────────────────────┐  ┌───────────────────────────────┐ │
│  │ Redis Cluster        │  │ MinIO (S3-Compatible)         │ │
│  │ (Cache, Sessions,    │  │ (Object Storage para arquivos)│ │
│  │  Presence, Pub/Sub)  │  │                               │ │
│  └──────────────────────┘  └───────────────────────────────┘ │
│                                                              │
│  ┌────────────────────────────────────────────────────────┐  │
│  │ etcd Cluster (Leader Election, Feature Flags, Config)  │  │
│  └────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────┘
                              ↓
┌──────────────────────────────────────────────────────────────┐
│                  CAMADA DE OBSERVABILIDADE                   │
├──────────────────────────────────────────────────────────────┤
│  Prometheus + Grafana + Jaeger + ELK Stack                   │
└──────────────────────────────────────────────────────────────┘
```

### 1.2 Justificativas das Escolhas Arquiteturais

#### 1.2.1 Por que PHP 8.4 com RoadRunner?

**Vantagens:**

- **Performance Moderna**: PHP 8.4 com JIT compiler oferece performance comparável a linguagens tradicionalmente mais rápidas (até 3x mais rápido que PHP 7.x)
- **RoadRunner Application Server**: Escrito em Go, gerencia workers PHP com true non-blocking I/O, connection pooling e hot-reloading
- **Tipagem Forte**: Suporte robusto a tipos (Property Hooks, readonly classes) reduz bugs em sistemas distribuídos
- **Ecossistema Maduro**: Bibliotecas consolidadas para todas as necessidades (gRPC, Kafka, Redis, MongoDB)
- **Baixa Curva de Aprendizado**: Facilita onboarding de desenvolvedores
- **Custo**: Menor custo de infraestrutura comparado a linguagens que requerem mais recursos

**Por que RoadRunner:**

- Serve gRPC, HTTP e executa consumidores Kafka usando o mesmo binário
- Gerencia lifecycle dos workers PHP automaticamente
- Drasticamente mais performático que PHP-FPM ou loops while(true) em CLI
- Suporte nativo a recarregamento de código sem downtime

#### 1.2.2 Por que gRPC?

**Vantagens:**

- **Performance**: Protocol Buffers (binário) são até 7x menores que JSON e 20x mais rápidos no parsing
- **Tipagem Forte**: Contratos definidos em `.proto` garantem compatibilidade entre serviços
- **Multiplexing HTTP/2**: Múltiplas chamadas simultâneas na mesma conexão
- **Code Generation**: Gera automaticamente clients/servers em múltiplas linguagens

**Importante sobre Frontend:**

- **gRPC-Web não suporta streaming bidirecional** (apenas unário e server streaming)
- Para comunicação real-time com o frontend Angular, utilizamos **WebSocket Gateway dedicado** que consome do Redis Pub/Sub
- gRPC é usado exclusivamente para comunicação entre microsserviços backend

#### 1.2.3 Por que Apache Kafka?

**Vantagens:**

- **Throughput Massivo**: Capaz de processar milhões de mensagens/segundo
- **Durabilidade**: Persistência em disco com replicação
- **Replay**: Capacidade de reprocessar eventos (event sourcing)
- **Particionamento**: Preserva ordem causal por conversation_id
- **Escalabilidade Linear**: Adicionar brokers aumenta capacidade proporcionalmente

#### 1.2.4 Por que API Gateway Dedicado (Kong/Tyk/KrakenD)?

**Vantagens sobre PHP Middleware:**

- **Performance**: Escritos em Go/Lua, otimizados para latência sub-milissegundo
- **Edge Logic Nativa**: Autenticação JWT, rate limiting e gRPC-Web transcoding sem overhead de PHP
- **Escalabilidade**: Remove a sobrecarga de inicializar PHP para cada requisição de API
- **Features Enterprise**: Circuit breaker, service mesh, analytics integrados

#### 1.2.5 Por que PostgreSQL + MongoDB (Polyglot Persistence)?

**PostgreSQL para:**

- **Dados Relacionais**: Users, conversations metadata, channel mappings
- **ACID Transactions**: Garantias fortes onde necessário
- **JSON Support**: JSONB para dados semi-estruturados
- **Desnormalização**: Coluna last_message_snippet na tabela conversations para queries eficientes

**MongoDB para:**

- **Mensagens**: Schema flexível, write-heavy workload
- **Time Series Collections**: Suporte nativo desde MongoDB 5.0+ para conversation_history (substitui bucketing manual)
- **Sharding Otimizado**: Chave composta `{ conversation_id: 1, created_at: 1 }` evita hot shards
- **Aggregation Pipeline**: Queries analíticas eficientes

#### 1.2.6 Por que Redis com TTL?

**Vantagens:**

- **Velocidade**: Operações em memória (sub-milissegundo)
- **Pub/Sub**: Para real-time presence e mensagens (consumido pelo WebSocket Gateway)
- **Presença Simplificada**: Hashes com TTL automático eliminam necessidade de Sorted Sets e jobs de cleanup

**Uso Otimizado:**

```
HSET presence:user:{user_id} status online
EXPIRE presence:user:{user_id} 300  # 5 minutos
```

#### 1.2.7 Por que etcd (uso refinado)?

**Uso Focado (quando usando Kubernetes):**

- **Leader Election**: Para serviços singleton (jobs de cleanup, agregações)
- **Feature Flags**: Controle de features em runtime sem redeploy
- **Rate Limits Globais**: Configuração centralizada dinâmica

**Kubernetes Service Discovery**: Usar o nativo do K8s (CoreDNS) para comunicação entre microsserviços, eliminando redundância

### 1.3 Padrões Arquiteturais Aplicados

#### 1.3.1 API Gateway Pattern

- **Vantagem**: Ponto único de entrada, centraliza cross-cutting concerns
- **Implementação**: Kong/Tyk/KrakenD para auth/rate limiting de alta performance

#### 1.3.2 WebSocket Gateway Pattern

- **Vantagem**: Desacopla comunicação real-time do gRPC
- **Implementação**: Serviço dedicado (Swoole) que:
  - Mantém conexões WebSocket ativas dos clientes
  - Se inscreve em Redis Pub/Sub (presence:updates, user:*:messages)
  - Encaminha mensagens para clientes corretos

#### 1.3.3 Circuit Breaker Pattern

- **Vantagem**: Previne cascata de falhas
- **Implementação**: Biblioteca guzzle/circuit-breaker para connectors externos

#### 1.3.4 Saga Pattern

- **Vantagem**: Transações distribuídas sem 2PC
- **Implementação**: Compensating transactions via Kafka events

#### 1.3.5 CQRS (Command Query Responsibility Segregation)

- **Vantagem**: Otimiza reads e writes separadamente
- **Implementação**: Writes em MongoDB via Kafka, reads de cache Redis

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
    channel_user_id VARCHAR(255) NOT NULL,
    channel_metadata JSONB DEFAULT '{}'::jsonb,
    verified BOOLEAN DEFAULT false,
    created_at TIMESTAMP DEFAULT NOW(),
    
    UNIQUE(user_id, channel_type),
    INDEX idx_user_channels_user (user_id),
    INDEX idx_user_channels_type (channel_type),
    INDEX idx_user_channels_external (channel_type, channel_user_id)
);

-- Conversas (metadata + última mensagem desnormalizada)
CREATE TABLE conversations (
    conversation_id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    type VARCHAR(20) NOT NULL CHECK (type IN ('private', 'group')),
    created_by UUID NOT NULL REFERENCES users(user_id),
    created_at TIMESTAMP DEFAULT NOW(),
    updated_at TIMESTAMP DEFAULT NOW(),
    
    -- Desnormalização para listagem eficiente
    last_message_id UUID,
    last_message_at TIMESTAMP,
    last_message_snippet TEXT,
    
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
    events TEXT[] NOT NULL,
    secret VARCHAR(255) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    created_at TIMESTAMP DEFAULT NOW(),
    
    INDEX idx_webhooks_user (user_id),
    INDEX idx_webhooks_active (is_active)
);

-- Tokens de acesso
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

-- Configuração de canais
CREATE TABLE channel_configs (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    channel_type VARCHAR(50) NOT NULL UNIQUE,
    config JSONB NOT NULL,
    is_enabled BOOLEAN DEFAULT true,
    updated_at TIMESTAMP DEFAULT NOW(),
    
    INDEX idx_channel_configs_type (channel_type)
);
```

### 2.2 MongoDB Collections (Messages & Time-Series Data)

```javascript
// Collection: messages
// Sharding Key: { conversation_id: 1, created_at: 1 } (Compound key para evitar hot shards)
{
    _id: ObjectId(),
    message_id: UUID(),
    conversation_id: UUID(),
    from_user_id: UUID(),
    to_user_ids: [UUID()],
    
    // Payload
    payload: {
        type: "text|file|image|video|audio",
        text: "Olá!",
        file_reference: {
            file_id: UUID(),
            filename: "doc.pdf",
            size_bytes: 1024000,
            mime_type: "application/pdf",
            storage_url: "minio://bucket/path"
        },
        metadata: {}
    },
    
    // Channels de entrega
    target_channels: ["whatsapp", "instagram"],
    
    // Status tracking
    status: "sent",
    status_history: [
        {
            status: "sent",
            timestamp: ISODate("2025-01-15T10:00:00Z"),
            details: {}
        }
    ],
    
    // Delivery tracking per channel
    channel_delivery: {
        whatsapp: {
            status: "delivered",
            delivered_at: ISODate("2025-01-15T10:00:05Z"),
            external_message_id: "wa_msg_123"
        }
    },
    
    // Metadata
    sequence_number: 42,
    reply_to_message_id: UUID(),
    is_edited: false,
    is_deleted: false,
    
    // Timestamps
    created_at: ISODate("2025-01-15T10:00:00Z"),
    updated_at: ISODate("2025-01-15T10:00:05Z"),
    ttl_expire_at: ISODate("2025-04-15T10:00:00Z")
}

// Indexes
db.messages.createIndex({ message_id: 1 }, { unique: true })
db.messages.createIndex({ conversation_id: 1, created_at: -1 })
db.messages.createIndex({ conversation_id: 1, sequence_number: 1 })
db.messages.createIndex({ from_user_id: 1, created_at: -1 })
db.messages.createIndex({ ttl_expire_at: 1 }, { expireAfterSeconds: 0 })

// ===================================================================
// Collection: conversation_history (Time Series Collection - MongoDB 5.0+)
// Substitui bucketing manual por coleção nativa otimizada
// ===================================================================
db.createCollection("conversation_history", {
    timeseries: {
        timeField: "timestamp",
        metaField: "conversation_id",
        granularity: "hours"
    },
    expireAfterSeconds: 7776000  // 90 dias
})

// Documento de exemplo
{
    conversation_id: UUID(),
    timestamp: ISODate("2025-01-15T10:00:00Z"),
    message_id: UUID(),
    message_text: "Última mensagem...",
    from_user_id: UUID(),
    metrics: {
        message_length: 127,
        has_attachment: false
    }
}

// Benefícios:
// - Compressão superior (até 90%)
// - Queries temporais otimizadas
// - Agregações mais rápidas
```

```javascript
// Collection: file_metadata
{
    _id: ObjectId(),
    file_id: UUID(),
    original_filename: "presentation.pptx",
    size_bytes: 1887436800,
    mime_type: "application/vnd.openxmlformats-officedocument.presentationml.presentation",
    
    // Storage details
    storage: {
        provider: "minio",
        bucket: "chat4all-files",
        key: "2025/01/15/uuid-filename.pptx",
        url: "https://minio.chat4all.com/..."
    },
    
    // Chunking info
    chunks: [
        {
            chunk_number: 1,
            size_bytes: 104857600,
            checksum: "sha256:abc123...",
            uploaded_at: ISODate("2025-01-15T10:00:00Z")
        }
    ],
    total_chunks: 18,
    
    // Upload tracking
    upload_status: "completed",
    upload_initiated_by: UUID(),
    upload_initiated_at: ISODate("2025-01-15T09:55:00Z"),
    upload_completed_at: ISODate("2025-01-15T10:15:00Z"),
    
    // Security
    checksum: "sha256:full_file_hash",
    encryption: {
        algorithm: "AES-256",
        key_id: "key_123"
    },
    
    created_at: ISODate("2025-01-15T09:55:00Z"),
    expires_at: ISODate("2025-04-15T10:15:00Z")
}

db.file_metadata.createIndex({ file_id: 1 }, { unique: true })
db.file_metadata.createIndex({ upload_status: 1 })
```

### 2.3 Redis Data Structures (Simplificadas)

```redis
# Session Storage (Hash)
HSET session:{token_hash} user_id {uuid} expires_at {timestamp}
EXPIRE session:{token_hash} 3600

# User Presence (Hash com TTL - Simplificado!)
HSET presence:user:{user_id} status online last_seen {timestamp}
EXPIRE presence:user:{user_id} 300  # 5 minutos
# Heartbeat do cliente renova o EXPIRE a cada 4 minutos
# Verificar online: EXISTS presence:user:{user_id}

# Message Deduplication (Set com TTL)
SADD dedup:messages {message_id}
EXPIRE dedup:messages 300

# Rate Limiting (String com atomic increment)
INCR rate_limit:{user_id}:{minute}
EXPIRE rate_limit:{user_id}:{minute} 60

# Conversation Cache (Hash)
HSET conv:{conversation_id} type {type} members {json_array}
EXPIRE conv:{conversation_id} 1800

# Pub/Sub Channels (para WebSocket Gateway)
PUBLISH presence:updates {json_user_event}
PUBLISH user:{user_id}:messages {json_message_event}
```

### 2.4 etcd Key-Value Schema (Uso Refinado)

```
# Leader Election (uso principal)
/leaders/file_cleanup_job -> {"leader": "worker-node-3", "elected_at": "2025-01-15T10:00:00Z"}

# Feature Flags (configuração dinâmica)
/features/new_ui -> {"enabled": true, "rollout_percentage": 50}

# Rate Limits Globais (ajuste em runtime)
/config/rate_limits/global -> {"messages_per_minute": 1000, "files_per_hour": 100}

# Nota: Service Discovery usa Kubernetes nativo (CoreDNS)
# Exemplo: message-service.chat4all.svc.cluster.local
```

## 3. Esqueleto do Projeto

### 3.1 Estrutura de Diretórios

```
chat4all-v2/
├── api-gateway/                    # API Gateway (Kong/Tyk/KrakenD)
│   ├── config/
│   │   ├── kong.yml
│   │   └── policies/
│   └── Dockerfile
│
├── websocket-gateway/              # Gateway WebSocket dedicado
│   ├── src/
│   │   ├── server.php              # Swoole WebSocket Server
│   │   ├── RedisSubscriber.php
│   │   └── ConnectionManager.php
│   ├── config/
│   └── Dockerfile
│
├── services/                       # Microsserviços gRPC
│   ├── auth-service/
│   │   ├── proto/
│   │   │   └── auth.proto
│   │   ├── src/
│   │   │   ├── Server.php
│   │   │   ├── AuthServiceImpl.php
│   │   │   └── Repository/
│   │   │       └── UserRepository.php
│   │   ├── .rr.yaml                # RoadRunner config
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
│   │   │   │   ├── MessageRepository.php
│   │   │   │   └── ConversationRepository.php
│   │   │   └── EventPublisher/
│   │   │       └── KafkaPublisher.php
│   │   ├── .rr.yaml
│   │   ├── composer.json
│   │   └── Dockerfile
│   │
│   ├── file-service/
│   ├── conversation-service/
│   └── presence-service/
│
├── workers/                        # Kafka Consumers
│   ├── router-worker/
│   │   ├── src/
│   │   │   ├── Consumer.php
│   │   │   ├── MessageRouter.php
│   │   │   └── DeduplicationService.php
│   │   ├── .rr.yaml
│   │   └── Dockerfile
│   │
│   ├── delivery-worker/
│   └── status-worker/
│       └── src/
│           ├── StatusUpdater.php   # Atualiza last_message em PostgreSQL
│           └── WebhookDispatcher.php
│
├── connectors/                     # Channel Adapters (Plugin Architecture)
│   ├── connector-interface/
│   │   └── src/
│   │       └── ConnectorInterface.php
│   │
│   ├── whatsapp-connector/
│   │   └── composer.json           # Dependências próprias
│   │
│   ├── telegram-connector/
│   │   └── composer.json
│   │
│   └── instagram-connector/
│       └── composer.json
│
├── packages/                       # Pacotes versionados (substitui shared/)
│   └── common-contracts/
│       ├── src/
│       │   └── ValueObjects/
│       │       ├── UUID.php
│       │       └── MessageStatus.php
│       └── composer.json           # Versionado (ex: 1.2.0)
│
├── frontend/                       # Interface Web
│   ├── angular-app/                # Angular 17+
│   │   ├── src/
│   │   │   ├── app/
│   │   │   │   ├── auth/
│   │   │   │   ├── chat/
│   │   │   │   └── services/
│   │   │   │       └── websocket.service.ts
│   │   │   └── index.html
│   │   ├── package.json
│   │   └── Dockerfile
│   │
│   └── nginx/
│       └── nginx.conf
│
├── infrastructure/
│   ├── kubernetes/
│   │   ├── namespaces/
│   │   ├── databases/
│   │   ├── messaging/
│   │   ├── services/
│   │   ├── monitoring/
│   │   └── configmaps/
│   │       └── hierarchy-config.yaml  # Hierarquia de configuração
│   │
│   └── docker-compose/
│       ├── docker-compose.yml
│       └── .env.example
│
├── scripts/
│   ├── generate-proto.sh
│   └── init-databases.sh
│
├── docs/
│   ├── architecture/
│   │   ├── system-design.md
│   │   ├── websocket-flow.md
│   │   └── configuration-hierarchy.md
│   └── api/
│
├── monitoring/
│   ├── prometheus/
│   ├── grafana/
│   └── jaeger/
│
└── README.md
```

### 3.2 Tecnologias e Dependências Principais

#### 3.2.1 Composer Dependencies (PHP Services)

```json
// services/message-service/composer.json
{
    "name": "chat4all/message-service",
    "type": "project",
    "require": {
        "php": "^8.4",
        "spiral/roadrunner-grpc": "^3.0",
        "grpc/grpc": "^1.57",
        "google/protobuf": "^3.25",
        "mongodb/mongodb": "^1.17",
        "rdkafka/rdkafka": "^6.0",
        "predis/predis": "^2.2",
        "monolog/monolog": "^3.5",
        "ramsey/uuid": "