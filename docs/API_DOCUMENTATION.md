# Chat4All - API Básica - Semana 3-4

## 📋 Sobre Esta Implementação

Esta é a **primeira versão funcional** do Chat4All, implementando a API básica conforme especificado no documento "Implementação da API Básica.md".

### ✅ Funcionalidades Implementadas

1. **API REST Básica**
   - ✅ POST /v1/messages - Envio de mensagens de texto
   - ✅ GET /v1/conversations/{id}/messages - Listagem de mensagens
   - ✅ GET /v1/conversations - Listagem de conversas
   - ✅ Autenticação JWT com chave estática

2. **Integração com Kafka**
   - ✅ Tópico `messages` criado e particionado por `conversation_id`
   - ✅ Produtor Kafka no serviço de API
   - ✅ Consumidor (worker) que processa mensagens

3. **Persistência de Mensagens**
   - ✅ Schema PostgreSQL completo
   - ✅ Salvamento de mensagens com estado inicial (SENT)
   - ✅ Metadados: timestamp, remetente, status, etc.

4. **Router Worker**
   - ✅ Consome mensagens do Kafka
   - ✅ Atualiza status para DELIVERED (simulando envio)
   - ✅ Gera logs de auditoria

5. **Docker Compose**
   - ✅ Inicialização automática de todos os serviços
   - ✅ PostgreSQL, Redis, Kafka, Zookeeper
   - ✅ Health checks configurados

## 🚀 Início Rápido

### Pré-requisitos

- Docker 20.10+
- Docker Compose 2.0+
- curl (para testes)
- jq (opcional, para formatação JSON)

### Iniciar o Sistema

```bash
# Dar permissão de execução aos scripts
chmod +x scripts/*.sh

# Iniciar todos os serviços
./scripts/start.sh
```

Aguarde até ver a mensagem:
```
✅ Chat4All iniciado com sucesso!
```

### Testar a API

```bash
# Executar script de testes automatizado
./scripts/test-api.sh
```

### Parar o Sistema

```bash
./scripts/stop.sh
```

## 📚 Documentação da API

### Base URL

```
http://localhost:8080
```

### Autenticação

Todas as rotas (exceto `/health` e `/v1/auth/login`) requerem um token JWT no header:

```
Authorization: Bearer <token>
```

---

## 🔐 Endpoints de Autenticação

### POST /v1/auth/login

Autentica um usuário e retorna um token JWT.

**Request:**

```bash
curl -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "alice@chat4all.com",
    "password": "password123"
  }'
```

**Response (200 OK):**

```json
{
  "success": true,
  "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "expires_in": 3600,
  "user": {
    "user_id": "11111111-1111-1111-1111-111111111111",
    "username": "alice",
    "email": "alice@chat4all.com"
  }
}
```

**Usuários de Teste:**

| Email | Senha | Username |
|-------|-------|----------|
| alice@chat4all.com | password123 | alice |
| bob@chat4all.com | password123 | bob |

---

## 💬 Endpoints de Mensagens

### POST /v1/messages

Envia uma nova mensagem para uma conversa.

**Headers:**
```
Authorization: Bearer <token>
Content-Type: application/json
```

**Request:**

```bash
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer <token>" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Olá! Como você está?",
    "message_type": "text"
  }'
```

**Campos:**

- `conversation_id` (obrigatório): UUID da conversa
- `content` (obrigatório): Texto da mensagem
- `message_type` (opcional): Tipo da mensagem (padrão: "text")

**Response (201 Created):**

```json
{
  "success": true,
  "message": {
    "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "from_user_id": "11111111-1111-1111-1111-111111111111",
    "from_username": "alice",
    "content": "Olá! Como você está?",
    "status": "SENT",
    "created_at": "2025-01-15 10:30:45"
  }
}
```

**Status da Mensagem:**

- `SENT`: Mensagem criada e publicada no Kafka
- `DELIVERED`: Processada pelo worker (simulando entrega)
- `READ`: Lida pelo destinatário (futuro)
- `FAILED`: Erro no processamento

---

### GET /v1/conversations/{id}/messages

Lista mensagens de uma conversa específica.

**Headers:**
```
Authorization: Bearer <token>
```

**Query Parameters:**

- `limit` (opcional): Número de mensagens a retornar (padrão: 50, máximo: 100)
- `offset` (opcional): Offset para paginação (padrão: 0)

**Request:**

```bash
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=10&offset=0" \
  -H "Authorization: Bearer <token>"
```

**Response (200 OK):**

```json
{
  "success": true,
  "conversation_id": "33333333-3333-3333-3333-333333333333",
  "messages": [
    {
      "message_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "conversation_id": "33333333-3333-3333-3333-333333333333",
      "from_user_id": "11111111-1111-1111-1111-111111111111",
      "from_username": "alice",
      "message_type": "text",
      "content": "Olá! Como você está?",
      "status": "DELIVERED",
      "created_at": "2025-01-15 10:30:45",
      "delivered_at": "2025-01-15 10:30:46",
      "read_at": null,
      "reply_to_message_id": null
    }
  ],
  "pagination": {
    "limit": 10,
    "offset": 0,
    "count": 1
  }
}
```

---

### GET /v1/conversations

Lista todas as conversas do usuário autenticado.

**Headers:**
```
Authorization: Bearer <token>
```

**Query Parameters:**

- `limit` (opcional): Número de conversas (padrão: 20, máximo: 50)

**Request:**

```bash
curl -X GET "http://localhost:8080/v1/conversations?limit=20" \
  -H "Authorization: Bearer <token>"
```

**Response (200 OK):**

```json
{
  "success": true,
  "conversations": [
    {
      "conversation_id": "33333333-3333-3333-3333-333333333333",
      "type": "private",
      "created_at": "2025-01-15 09:00:00",
      "updated_at": "2025-01-15 10:30:46",
      "last_message_snippet": "Olá! Como você está?",
      "last_message_at": "2025-01-15 10:30:45",
      "members_count": 2
    }
  ],
  "count": 1
}
```

---

## 🏥 Health Check

### GET /health

Verifica o status do serviço.

**Request:**

```bash
curl http://localhost:8080/health
```

**Response (200 OK):**

```json
{
  "status": "healthy",
  "service": "api-service",
  "timestamp": "2025-01-15 10:30:00"
}
```

---

## 🧪 Exemplo Completo: Troca de Mensagens

```bash
# 1. Login como Alice
ALICE_TOKEN=$(curl -s -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"alice@chat4all.com","password":"password123"}' \
  | jq -r '.token')

echo "Alice Token: $ALICE_TOKEN"

# 2. Login como Bob
BOB_TOKEN=$(curl -s -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"bob@chat4all.com","password":"password123"}' \
  | jq -r '.token')

echo "Bob Token: $BOB_TOKEN"

# 3. Alice envia mensagem
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $ALICE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Oi Bob! Tudo bem?"
  }' | jq .

# 4. Aguardar processamento
sleep 2

# 5. Bob lista mensagens
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages" \
  -H "Authorization: Bearer $BOB_TOKEN" | jq .

# 6. Bob responde
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $BOB_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Oi Alice! Tudo ótimo, e você?"
  }' | jq .

# 7. Aguardar processamento
sleep 2

# 8. Alice verifica novas mensagens
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages" \
  -H "Authorization: Bearer $ALICE_TOKEN" | jq .
```

---

## 🔍 Logs e Debugging

### Ver logs de todos os serviços

```bash
docker-compose logs -f
```

### Ver logs de um serviço específico

```bash
# API Service
docker-compose logs -f api-service

# Router Worker
docker-compose logs -f router-worker

# Kafka
docker-compose logs -f kafka

# PostgreSQL
docker-compose logs -f postgres
```

### Acessar banco de dados

```bash
# Conectar ao PostgreSQL
docker-compose exec postgres psql -U chat4all_user -d chat4all

# Queries úteis:
# Ver todas as mensagens
SELECT * FROM messages ORDER BY created_at DESC;

# Ver logs de auditoria
SELECT * FROM audit_logs ORDER BY created_at DESC;

# Ver conversas
SELECT * FROM conversations;
```

### Acessar Redis

```bash
# Conectar ao Redis
docker-compose exec redis redis-cli

# Ver todas as chaves
KEYS *

# Ver informações
INFO
```

---

## 📊 Arquitetura Simplificada

```
┌─────────────┐
│   Cliente   │
│  (curl/app) │
└──────┬──────┘
       │ HTTP
       ↓
┌─────────────────┐
│  API Service    │  ← Slim Framework + PHP 8.3
│  (port 8080)    │
└────┬────────┬───┘
     │        │
     │        └──────────────┐
     ↓                       ↓
┌──────────┐         ┌──────────────┐
│PostgreSQL│         │    Kafka     │
│(messages)│         │(queue events)│
└──────────┘         └───────┬──────┘
                             │
                             ↓
                     ┌───────────────┐
                     │ Router Worker │
                     │ (consumer)    │
                     └───────┬───────┘
                             │
                             ↓
                     Updates status
                     (SENT → DELIVERED)
```

---

## 🗄️ Estrutura do Banco de Dados

### Tabela: users

| Campo | Tipo | Descrição |
|-------|------|-----------|
| user_id | UUID | ID único do usuário |
| username | VARCHAR(255) | Nome de usuário |
| email | VARCHAR(255) | Email (único) |
| password_hash | VARCHAR(255) | Hash bcrypt da senha |
| status | VARCHAR(20) | active, suspended, deleted |
| created_at | TIMESTAMP | Data de criação |

### Tabela: conversations

| Campo | Tipo | Descrição |
|-------|------|-----------|
| conversation_id | UUID | ID único da conversa |
| type | VARCHAR(20) | private ou group |
| created_by | UUID | Criador da conversa |
| last_message_snippet | TEXT | Snippet da última mensagem |
| last_message_at | TIMESTAMP | Timestamp da última mensagem |
| created_at | TIMESTAMP | Data de criação |

### Tabela: messages

| Campo | Tipo | Descrição |
|-------|------|-----------|
| message_id | UUID | ID único da mensagem |
| conversation_id | UUID | ID da conversa |
| from_user_id | UUID | Remetente |
| content | TEXT | Conteúdo da mensagem |
| message_type | VARCHAR(20) | text, file, image, etc |
| status | VARCHAR(20) | SENT, DELIVERED, READ, FAILED |
| created_at | TIMESTAMP | Data de criação |
| delivered_at | TIMESTAMP | Data de entrega |
| read_at | TIMESTAMP | Data de leitura |

---

## 🚨 Tratamento de Erros

### Códigos de Status HTTP

- `200 OK`: Requisição bem sucedida
- `201 Created`: Recurso criado com sucesso
- `400 Bad Request`: Dados inválidos
- `401 Unauthorized`: Token inválido ou ausente
- `403 Forbidden`: Sem permissão para acessar recurso
- `500 Internal Server Error`: Erro no servidor

### Formato de Erro

```json
{
  "success": false,
  "error": "Mensagem descritiva do erro"
}
```

---

## 🛠️ Desenvolvimento

### Estrutura de Arquivos

```
chat4all/
├── docker-compose.yml          # Orquestração de containers
├── scripts/
│   ├── init-db.sql            # Schema do banco
│   ├── start.sh               # Script de inicialização
│   ├── stop.sh                # Script de parada
│   └── test-api.sh            # Testes automatizados
├── services/
│   └── api-service/
│       ├── public/
│       │   └── index.php      # Entry point
│       ├── src/
│       │   ├── Controller/    # Controllers
│       │   ├── Database/      # Camada de dados
│       │   ├── Middleware/    # Middlewares
│       │   └── Service/       # Serviços (Kafka)
│       ├── composer.json
│       └── Dockerfile
└── workers/
    └── router-worker/
        ├── consumer.php       # Entry point do worker
        ├── src/
        │   ├── Database.php
        │   ├── KafkaConsumer.php
        │   └── MessageProcessor.php
        ├── composer.json
        └── Dockerfile
```

---

## 📈 Próximos Passos

Para as próximas semanas, considere implementar:

1. **WebSocket para Real-time**
   - Notificações instantâneas de novas mensagens
   - Status de digitação
   - Presença online/offline

2. **Suporte a Arquivos**
   - Upload de imagens, vídeos, documentos
   - Integração com MinIO/S3
   - Thumbnails e preview

3. **Connectors para Canais**
   - WhatsApp Business API
   - Telegram Bot API
   - Instagram Messaging

4. **Melhorias de Performance**
   - Cache com Redis
   - Índices otimizados
   - Connection pooling

5. **Observabilidade**
   - Prometheus para métricas
   - Grafana para dashboards
   - Jaeger para tracing distribuído

---

## 📝 Notas Importantes

### Segurança

⚠️ **Esta é uma implementação educacional!**

Para produção, você deve:

- [ ] Usar variáveis de ambiente seguras (não hardcoded)
- [ ] Implementar rate limiting
- [ ] Adicionar validação de entrada robusta
- [ ] Usar HTTPS/TLS
- [ ] Rotacionar chaves JWT regularmente
- [ ] Implementar logging de segurança
- [ ] Adicionar monitoramento de anomalias

### Performance

Esta implementação é simplificada para facilitar o aprendizado. Para produção:

- [ ] Usar connection pooling para PostgreSQL
- [ ] Implementar cache de sessões no Redis
- [ ] Configurar múltiplas partições no Kafka
- [ ] Usar consumer groups para escalar workers
- [ ] Adicionar índices compostos no banco
- [ ] Implementar backpressure no Kafka

---

## 🤝 Suporte

Para dúvidas sobre este projeto:

1. Verifique os logs com `docker-compose logs -f`
2. Revise a documentação da arquitetura no README.md principal
3. Execute os testes com `./scripts/test-api.sh`

---

## 📄 Licença

Este projeto é parte de um trabalho acadêmico para a disciplina de Sistemas Distribuídos.

---

**Desenvolvido com ❤️ para aprendizado de Sistemas Distribuídos**
