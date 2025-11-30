# Data Model: Trabalho Final - Escalabilidade e Relatório

**Feature**: 001-final-scalability-report  
**Date**: 2025-11-29  
**Status**: Complete

## Entity Overview

```
┌─────────────┐     ┌──────────────────┐     ┌─────────────────┐
│    User     │────<│ ConversationMember│>────│  Conversation   │
└─────────────┘     └──────────────────┘     └─────────────────┘
       │                                              │
       │                                              │
       ▼                                              ▼
┌─────────────┐                              ┌─────────────────┐
│    File     │─────────────────────────────>│    Message      │
└─────────────┘                              └─────────────────┘
                                                      │
                                                      ▼
                                             ┌─────────────────┐
                                             │ DeliveryCallback│
                                             └─────────────────┘
```

## Entities

### File (NEW)

Arquivo armazenado no Object Storage MinIO.

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `file_id` | UUID | PK, DEFAULT uuid_generate_v4() | Identificador único |
| `filename` | VARCHAR(512) | NOT NULL | Nome original do arquivo |
| `mime_type` | VARCHAR(128) | NOT NULL | Tipo MIME (image/png, video/mp4, etc) |
| `size` | BIGINT | NOT NULL | Tamanho em bytes |
| `checksum` | VARCHAR(64) | NOT NULL | SHA-256 do arquivo |
| `storage_path` | VARCHAR(1024) | NOT NULL | Caminho no MinIO (bucket/key) |
| `uploader_id` | UUID | FK → users.user_id, NOT NULL | Quem enviou |
| `conversation_id` | UUID | FK → conversations.conversation_id | Conversa associada (opcional) |
| `upload_status` | ENUM | DEFAULT 'pending' | 'pending', 'uploading', 'completed', 'failed' |
| `upload_id` | VARCHAR(256) | NULL | S3 multipart upload ID (for resume) |
| `parts_uploaded` | JSONB | DEFAULT '[]' | Array de parts completadas |
| `created_at` | TIMESTAMP | DEFAULT NOW() | Data de criação |
| `completed_at` | TIMESTAMP | NULL | Data de conclusão do upload |

**Indexes**:
- `idx_files_uploader` ON (uploader_id)
- `idx_files_conversation` ON (conversation_id)
- `idx_files_status` ON (upload_status)
- `idx_files_checksum` ON (checksum)

**Validation Rules**:
- `size` ≤ 2GB (2147483648 bytes)
- `mime_type` must be valid MIME format
- `checksum` must be 64-character hex string (SHA-256)

---

### Message (MODIFIED)

Mensagem no sistema - adição do campo `file_id`.

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| ... | ... | ... | (campos existentes mantidos) |
| `file_id` | UUID | FK → files.file_id, NULL | Arquivo anexado (se type='file') |

**New Index**:
- `idx_messages_file` ON (file_id)

**New Validation**:
- Se `message_type` = 'file', então `file_id` DEVE ser NOT NULL
- Se `message_type` != 'file', então `file_id` DEVE ser NULL

---

### DeliveryCallback (NEW)

Registro de callbacks de entrega/leitura recebidos dos connectors.

| Field | Type | Constraints | Description |
|-------|------|-------------|-------------|
| `callback_id` | UUID | PK, DEFAULT uuid_generate_v4() | Identificador único |
| `message_id` | UUID | FK → messages.message_id, NOT NULL | Mensagem relacionada |
| `platform` | VARCHAR(32) | NOT NULL | 'whatsapp', 'instagram' |
| `status` | VARCHAR(20) | NOT NULL | 'DELIVERED', 'READ' |
| `external_id` | VARCHAR(256) | NULL | ID externo simulado |
| `received_at` | TIMESTAMP | DEFAULT NOW() | Quando callback foi recebido |
| `processed_at` | TIMESTAMP | NULL | Quando foi processado |
| `payload` | JSONB | NULL | Payload completo do callback |

**Indexes**:
- `idx_callbacks_message` ON (message_id)
- `idx_callbacks_platform` ON (platform, received_at DESC)
- `idx_callbacks_status` ON (status)

---

### WebSocketConnection (Runtime - Redis)

Conexões WebSocket ativas (armazenadas em Redis, não PostgreSQL).

| Field | Type | Description |
|-------|------|-------------|
| `connection_id` | STRING | ID único da conexão WebSocket |
| `user_id` | UUID | Usuário conectado |
| `connected_at` | TIMESTAMP | Quando conectou |
| `last_ping` | TIMESTAMP | Último ping/pong |
| `subscribed_conversations` | SET | IDs de conversas assinadas |

**Redis Keys**:
- `ws:connections` - Hash de todas conexões ativas
- `ws:user:{user_id}` - Set de connection_ids do usuário
- `ws:conversation:{conv_id}` - Set de connection_ids assinados

---

## State Transitions

### File Upload Status

```
                    ┌──────────────────┐
                    │     pending      │
                    └────────┬─────────┘
                             │ initiate upload
                             ▼
                    ┌──────────────────┐
           ┌───────>│    uploading     │<──────┐
           │        └────────┬─────────┘       │
           │                 │                 │
     resume│                 │ all parts      │ more parts
           │                 │ uploaded       │
           │                 ▼                 │
           │        ┌──────────────────┐       │
           └────────│    uploading     ├───────┘
                    └────────┬─────────┘
                             │ finalize
                             ▼
              ┌──────────────────────────┐
              │        completed         │
              └──────────────────────────┘
                             
           On Error:
              ┌──────────────────────────┐
              │         failed           │
              └──────────────────────────┘
```

### Message Status

```
              ┌──────────────────────────┐
              │         SENT             │  (created by API)
              └────────────┬─────────────┘
                           │ router-worker processes
                           │ connector receives
                           ▼
              ┌──────────────────────────┐
              │       DELIVERED          │  (callback received)
              └────────────┬─────────────┘
                           │ user opens message
                           │ (simulated by connector)
                           ▼
              ┌──────────────────────────┐
              │         READ             │  (callback received)
              └──────────────────────────┘

           On Failure:
              ┌──────────────────────────┐
              │        FAILED            │
              └──────────────────────────┘
```

---

## SQL Migrations

### Migration 001: Create files table

```sql
-- Migration: 001_create_files_table.sql

CREATE TYPE file_upload_status AS ENUM ('pending', 'uploading', 'completed', 'failed');

CREATE TABLE files (
    file_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    filename VARCHAR(512) NOT NULL,
    mime_type VARCHAR(128) NOT NULL,
    size BIGINT NOT NULL CHECK (size > 0 AND size <= 2147483648),
    checksum VARCHAR(64) NOT NULL,
    storage_path VARCHAR(1024) NOT NULL,
    uploader_id UUID NOT NULL REFERENCES users(user_id),
    conversation_id UUID REFERENCES conversations(conversation_id),
    upload_status file_upload_status DEFAULT 'pending',
    upload_id VARCHAR(256),
    parts_uploaded JSONB DEFAULT '[]',
    created_at TIMESTAMP DEFAULT NOW(),
    completed_at TIMESTAMP
);

CREATE INDEX idx_files_uploader ON files(uploader_id);
CREATE INDEX idx_files_conversation ON files(conversation_id);
CREATE INDEX idx_files_status ON files(upload_status);
CREATE INDEX idx_files_checksum ON files(checksum);

COMMENT ON TABLE files IS 'Arquivos armazenados no Object Storage (MinIO)';
COMMENT ON COLUMN files.size IS 'Tamanho em bytes, máximo 2GB';
COMMENT ON COLUMN files.checksum IS 'SHA-256 hash para verificação de integridade';
COMMENT ON COLUMN files.upload_id IS 'S3 multipart upload ID para resumable uploads';
```

### Migration 002: Add file_id to messages

```sql
-- Migration: 002_add_file_id_to_messages.sql
-- (Already applied: ALTER TABLE messages ADD COLUMN file_id UUID REFERENCES files(file_id))

-- Add index if not exists
CREATE INDEX IF NOT EXISTS idx_messages_file ON messages(file_id);

-- Add constraint for message type validation
-- Note: This is enforced at application level for flexibility
COMMENT ON COLUMN messages.file_id IS 'Referência ao arquivo anexado (obrigatório se message_type=file)';
```

### Migration 003: Create delivery_callbacks table

```sql
-- Migration: 003_create_delivery_callbacks_table.sql

CREATE TABLE delivery_callbacks (
    callback_id UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    message_id UUID NOT NULL REFERENCES messages(message_id),
    platform VARCHAR(32) NOT NULL CHECK (platform IN ('whatsapp', 'instagram')),
    status VARCHAR(20) NOT NULL CHECK (status IN ('DELIVERED', 'READ')),
    external_id VARCHAR(256),
    received_at TIMESTAMP DEFAULT NOW(),
    processed_at TIMESTAMP,
    payload JSONB
);

CREATE INDEX idx_callbacks_message ON delivery_callbacks(message_id);
CREATE INDEX idx_callbacks_platform ON delivery_callbacks(platform, received_at DESC);
CREATE INDEX idx_callbacks_status ON delivery_callbacks(status);

COMMENT ON TABLE delivery_callbacks IS 'Callbacks de entrega/leitura dos connectors mock';
COMMENT ON COLUMN delivery_callbacks.external_id IS 'ID simulado da plataforma externa';
```

---

## Kafka Topics

| Topic | Producers | Consumers | Message Schema |
|-------|-----------|-----------|----------------|
| `messages` | api-service | router-worker | MessageCreated |
| `whatsapp.messages` | router-worker | whatsapp-connector | RoutedMessage |
| `instagram.messages` | router-worker | instagram-connector | RoutedMessage |
| `status-updates` | api-service (on callback) | websocket-worker | StatusUpdate |

### Message Schemas

**MessageCreated** (messages topic):
```json
{
  "message_id": "uuid",
  "conversation_id": "uuid",
  "from_user_id": "uuid",
  "message_type": "text|file",
  "content": "string",
  "file_id": "uuid|null",
  "created_at": "ISO8601"
}
```

**RoutedMessage** (platform-specific topics):
```json
{
  "message_id": "uuid",
  "target_platform": "whatsapp|instagram",
  "recipient_identifier": "string",
  "content": "string",
  "file_url": "presigned-url|null",
  "routed_at": "ISO8601"
}
```

**StatusUpdate** (status-updates topic):
```json
{
  "message_id": "uuid",
  "conversation_id": "uuid",
  "user_id": "uuid",
  "old_status": "SENT|DELIVERED",
  "new_status": "DELIVERED|READ",
  "updated_at": "ISO8601",
  "source_platform": "whatsapp|instagram"
}
```

---

## Redis Data Structures

### WebSocket Connections

```redis
# Hash: all active connections
HSET ws:connections {connection_id} {
  "user_id": "uuid",
  "connected_at": "ISO8601",
  "last_ping": "ISO8601"
}

# Set: connections per user (for targeted messages)
SADD ws:user:{user_id} {connection_id}

# Set: connections subscribed to conversation (for broadcasts)
SADD ws:conversation:{conversation_id} {connection_id}

# Pub/Sub channel for status updates
PUBLISH status-updates {StatusUpdate JSON}
```

### Upload Progress Tracking

```redis
# Hash: multipart upload progress
HSET upload:{upload_id} {
  "file_id": "uuid",
  "total_parts": 20,
  "completed_parts": [1, 2, 3, 4],
  "started_at": "ISO8601"
}

# TTL: 24 hours (cleanup incomplete uploads)
EXPIRE upload:{upload_id} 86400
```
