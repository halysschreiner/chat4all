# Exemplos de Uso - Chat4All API

Este documento contém exemplos práticos de uso da API do Chat4All.

## 🔐 1. Autenticação

### Fazer Login

```bash
# Login do usuário Alice
curl -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "email": "alice@chat4all.com",
    "password": "password123"
  }'
```

**Resposta:**
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

### Salvar Token em Variável

```bash
# Facilita o uso em outros comandos
TOKEN=$(curl -s -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"alice@chat4all.com","password":"password123"}' \
  | jq -r '.token')

echo "Token: $TOKEN"
```

---

## 💬 2. Enviando Mensagens

### Mensagem Simples

```bash
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Olá! Como você está?"
  }'
```

### Mensagem Mais Longa

```bash
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Esta é uma mensagem mais longa que demonstra o envio de conteúdo textual através da API do Chat4All. O sistema suporta mensagens de diferentes tamanhos e tipos.",
    "message_type": "text"
  }'
```

---

## 📋 3. Listando Mensagens

### Listar Últimas Mensagens

```bash
# Últimas 10 mensagens da conversa
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=10" \
  -H "Authorization: Bearer $TOKEN"
```

### Listar com Paginação

```bash
# Primeira página (10 mensagens)
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=10&offset=0" \
  -H "Authorization: Bearer $TOKEN"

# Segunda página (próximas 10 mensagens)
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=10&offset=10" \
  -H "Authorization: Bearer $TOKEN"
```

### Listar Todas as Mensagens (até limite)

```bash
# Máximo de 100 mensagens por requisição
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=100" \
  -H "Authorization: Bearer $TOKEN"
```

---

## 📝 4. Listando Conversas

### Listar Conversas do Usuário

```bash
curl -X GET "http://localhost:8080/v1/conversations?limit=20" \
  -H "Authorization: Bearer $TOKEN"
```

**Resposta:**
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

## 🔄 5. Fluxo Completo: Conversa entre Dois Usuários

### Script Completo

```bash
#!/bin/bash

# Cores para output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}=== Conversa entre Alice e Bob ===${NC}\n"

# 1. Login Alice
echo -e "${BLUE}1. Alice fazendo login...${NC}"
ALICE_TOKEN=$(curl -s -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"alice@chat4all.com","password":"password123"}' \
  | jq -r '.token')
echo -e "${GREEN}✓ Alice logada${NC}\n"

# 2. Login Bob
echo -e "${BLUE}2. Bob fazendo login...${NC}"
BOB_TOKEN=$(curl -s -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"bob@chat4all.com","password":"password123"}' \
  | jq -r '.token')
echo -e "${GREEN}✓ Bob logado${NC}\n"

# 3. Alice envia mensagem
echo -e "${BLUE}3. Alice enviando mensagem...${NC}"
curl -s -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $ALICE_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Oi Bob! Tudo bem? Vamos estudar sistemas distribuídos hoje?"
  }' | jq .
echo ""

# 4. Aguardar processamento
echo -e "${BLUE}4. Aguardando processamento do worker...${NC}"
sleep 2
echo ""

# 5. Bob lista mensagens
echo -e "${BLUE}5. Bob verificando mensagens...${NC}"
curl -s -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=5" \
  -H "Authorization: Bearer $BOB_TOKEN" | jq .
echo ""

# 6. Bob responde
echo -e "${BLUE}6. Bob respondendo...${NC}"
curl -s -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $BOB_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Oi Alice! Claro, vamos sim! Já dei uma olhada no material sobre Kafka."
  }' | jq .
echo ""

# 7. Aguardar processamento
echo -e "${BLUE}7. Aguardando processamento...${NC}"
sleep 2
echo ""

# 8. Alice verifica novas mensagens
echo -e "${BLUE}8. Alice verificando novas mensagens...${NC}"
curl -s -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=5" \
  -H "Authorization: Bearer $ALICE_TOKEN" | jq .
echo ""

# 9. Alice verifica suas conversas
echo -e "${BLUE}9. Alice listando suas conversas...${NC}"
curl -s -X GET "http://localhost:8080/v1/conversations" \
  -H "Authorization: Bearer $ALICE_TOKEN" | jq .

echo -e "\n${GREEN}=== Conversa concluída! ===${NC}"
```

Salve este script como `conversation_example.sh` e execute:

```bash
chmod +x conversation_example.sh
./conversation_example.sh
```

---

## 🧪 6. Testando Status das Mensagens

### Verificar Mudança de Status

```bash
# 1. Enviar mensagem
MESSAGE_RESPONSE=$(curl -s -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Teste de status"
  }')

echo "Mensagem enviada:"
echo "$MESSAGE_RESPONSE" | jq .

MESSAGE_ID=$(echo "$MESSAGE_RESPONSE" | jq -r '.message.message_id')
echo "Message ID: $MESSAGE_ID"

# 2. Verificar status imediatamente (deve ser SENT)
echo -e "\n=== Status imediato: ==="
curl -s -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=1" \
  -H "Authorization: Bearer $TOKEN" | jq '.messages[0] | {message_id, status, created_at, delivered_at}'

# 3. Aguardar worker processar
echo -e "\nAguardando worker processar (3 segundos)..."
sleep 3

# 4. Verificar status novamente (deve ser DELIVERED)
echo -e "\n=== Status após processamento: ==="
curl -s -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages?limit=1" \
  -H "Authorization: Bearer $TOKEN" | jq '.messages[0] | {message_id, status, created_at, delivered_at}'
```

---

## 📊 7. Verificando Logs e Auditoria

### Verificar Logs do Worker

```bash
# Ver últimas linhas dos logs do worker
docker-compose logs --tail=50 router-worker

# Seguir logs em tempo real
docker-compose logs -f router-worker
```

### Consultar Logs de Auditoria no Banco

```bash
# Conectar ao PostgreSQL
docker-compose exec postgres psql -U chat4all_user -d chat4all

# Dentro do PostgreSQL:
# Ver últimos eventos
SELECT 
  event_type, 
  entity_type, 
  entity_id, 
  created_at 
FROM audit_logs 
ORDER BY created_at DESC 
LIMIT 10;

# Ver eventos de mensagens enviadas
SELECT * FROM audit_logs 
WHERE event_type = 'message.sent' 
ORDER BY created_at DESC 
LIMIT 5;

# Ver eventos de mensagens entregues
SELECT * FROM audit_logs 
WHERE event_type = 'message.delivered' 
ORDER BY created_at DESC 
LIMIT 5;

# Sair
\q
```

---

## 🔍 8. Debugging e Troubleshooting

### Verificar Saúde dos Serviços

```bash
# Health check da API
curl http://localhost:8080/health | jq .

# Status dos containers
docker-compose ps

# Verificar logs de erro
docker-compose logs api-service | grep -i error
docker-compose logs router-worker | grep -i error
```

### Verificar Kafka

```bash
# Listar tópicos
docker-compose exec kafka kafka-topics \
  --list \
  --bootstrap-server localhost:9093

# Ver mensagens no tópico (CTRL+C para parar)
docker-compose exec kafka kafka-console-consumer \
  --bootstrap-server localhost:9093 \
  --topic messages \
  --from-beginning

# Descrever tópico
docker-compose exec kafka kafka-topics \
  --describe \
  --topic messages \
  --bootstrap-server localhost:9093
```

### Verificar PostgreSQL

```bash
# Conectar ao banco
docker-compose exec postgres psql -U chat4all_user -d chat4all

# Queries úteis:
# Total de mensagens
SELECT COUNT(*) as total_messages FROM messages;

# Mensagens por status
SELECT status, COUNT(*) as count 
FROM messages 
GROUP BY status;

# Últimas mensagens
SELECT 
  m.message_id,
  u.username,
  m.content,
  m.status,
  m.created_at,
  m.delivered_at
FROM messages m
JOIN users u ON m.from_user_id = u.user_id
ORDER BY m.created_at DESC
LIMIT 10;

# Sair
\q
```

### Verificar Redis

```bash
# Conectar ao Redis
docker-compose exec redis redis-cli

# Comandos úteis:
INFO
DBSIZE
KEYS *
# Sair
exit
```

---

## 🚨 9. Tratamento de Erros

### Token Inválido

```bash
# Tentar acessar sem token
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages"
```

**Resposta (401):**
```json
{
  "error": "Unauthorized",
  "message": "Token não fornecido"
}
```

### Token Expirado

```bash
# Usar token expirado
curl -X GET "http://localhost:8080/v1/conversations/33333333-3333-3333-3333-333333333333/messages" \
  -H "Authorization: Bearer token_expirado_ou_invalido"
```

**Resposta (401):**
```json
{
  "error": "Unauthorized",
  "message": "Token inválido ou expirado"
}
```

### Conversa Inválida

```bash
# Tentar acessar conversa que não existe ou sem permissão
curl -X GET "http://localhost:8080/v1/conversations/00000000-0000-0000-0000-000000000000/messages" \
  -H "Authorization: Bearer $TOKEN"
```

**Resposta (403):**
```json
{
  "success": false,
  "error": "Usuário não pertence a esta conversa"
}
```

### Dados Inválidos

```bash
# Enviar mensagem sem conteúdo
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": ""
  }'
```

**Resposta (400):**
```json
{
  "success": false,
  "error": "Conteúdo da mensagem não pode ser vazio"
}
```

---

## 📈 10. Performance e Load Testing

### Enviar Múltiplas Mensagens

```bash
#!/bin/bash

# Script para enviar 50 mensagens
TOKEN=$(curl -s -X POST http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"alice@chat4all.com","password":"password123"}' \
  | jq -r '.token')

for i in {1..50}
do
  echo "Enviando mensagem $i..."
  curl -s -X POST http://localhost:8080/v1/messages \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
      \"conversation_id\": \"33333333-3333-3333-3333-333333333333\",
      \"content\": \"Mensagem de teste número $i\"
    }" > /dev/null
  
  echo "✓ Mensagem $i enviada"
done

echo "Todas as 50 mensagens foram enviadas!"
echo "Aguardando processamento..."
sleep 5

echo "Verificando mensagens no banco..."
docker-compose exec -T postgres psql -U chat4all_user -d chat4all -c "
  SELECT status, COUNT(*) as count 
  FROM messages 
  GROUP BY status;
"
```

---

## 🎯 11. Casos de Uso Reais

### Caso 1: Notificação de Sistema

```bash
# Sistema enviando notificação para usuário
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "[SISTEMA] Seu relatório foi processado com sucesso!"
  }'
```

### Caso 2: Confirmação de Ação

```bash
# Confirmar ação do usuário
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "✓ Pedido #12345 confirmado. Entrega prevista para amanhã."
  }'
```

### Caso 3: Chat de Suporte

```bash
# Cliente solicitando suporte
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Olá! Estou com dúvidas sobre meu pedido. Podem me ajudar?"
  }'
```

---

## 💡 Dicas e Boas Práticas

### Usar jq para Formatar JSON

```bash
# Instalar jq (se necessário)
# Ubuntu/Debian: sudo apt-get install jq
# Mac: brew install jq

# Formatar resposta
curl -s http://localhost:8080/health | jq .

# Extrair campo específico
curl -s http://localhost:8080/health | jq '.status'

# Extrair múltiplos campos
curl -s http://localhost:8080/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"alice@chat4all.com","password":"password123"}' \
  | jq '{token, user: .user.username}'
```

### Salvar Resposta em Arquivo

```bash
# Salvar resposta JSON
curl -s http://localhost:8080/v1/conversations \
  -H "Authorization: Bearer $TOKEN" \
  > conversations.json

# Ver conteúdo
cat conversations.json | jq .
```

### Medir Tempo de Resposta

```bash
# Usar --write-out com curl
curl -X POST http://localhost:8080/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversation_id": "33333333-3333-3333-3333-333333333333",
    "content": "Teste de performance"
  }' \
  -w "\nTempo total: %{time_total}s\n" \
  -o /dev/null
```

---

**Documentação criada para facilitar o uso e testes do Chat4All API** 🚀
