#!/bin/bash

# Script de teste da API
# Testa todos os endpoints principais

set -e

API_URL="http://localhost:8080/api"

# Cores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo "🧪 Chat4All v2 - Teste de API"
echo "============================="
echo ""

# 1. Registrar usuário de teste
echo -e "${YELLOW}1. Registrando usuário de teste...${NC}"
REGISTER_RESPONSE=$(curl -s -X POST "$API_URL/auth/register" \
  -H "Content-Type: application/json" \
  -d '{
    "username": "testuser",
    "email": "test@example.com",
    "password": "senha123"
  }')

echo "$REGISTER_RESPONSE" | jq '.'

if echo "$REGISTER_RESPONSE" | jq -e '.success' > /dev/null; then
    echo -e "${GREEN}✓ Usuário registrado${NC}"
else
    echo -e "${YELLOW}⚠ Usuário pode já existir${NC}"
fi
echo ""

# 2. Fazer login
echo -e "${YELLOW}2. Fazendo login...${NC}"
LOGIN_RESPONSE=$(curl -s -X POST "$API_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "test@example.com",
    "password": "senha123"
  }')

echo "$LOGIN_RESPONSE" | jq '.'

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.token')
USER_ID=$(echo "$LOGIN_RESPONSE" | jq -r '.user.user_id')

if [ "$TOKEN" != "null" ] && [ "$TOKEN" != "" ]; then
    echo -e "${GREEN}✓ Login realizado${NC}"
    echo "Token: $TOKEN"
    echo "User ID: $USER_ID"
else
    echo -e "${RED}✗ Falha no login${NC}"
    exit 1
fi
echo ""

# 3. Criar conversa privada (com alice)
echo -e "${YELLOW}3. Criando conversa privada com Alice...${NC}"

# Primeiro, pegar o ID da Alice
ALICE_LOGIN=$(curl -s -X POST "$API_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "alice@chat4all.com",
    "password": "password"
  }')

ALICE_ID=$(echo "$ALICE_LOGIN" | jq -r '.user.user_id')
echo "Alice ID: $ALICE_ID"

CONV_RESPONSE=$(curl -s -X POST "$API_URL/conversations/private" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{
    \"other_user_id\": \"$ALICE_ID\"
  }")

echo "$CONV_RESPONSE" | jq '.'

CONV_ID=$(echo "$CONV_RESPONSE" | jq -r '.conversation.conversation_id')

if [ "$CONV_ID" != "null" ] && [ "$CONV_ID" != "" ]; then
    echo -e "${GREEN}✓ Conversa criada${NC}"
    echo "Conversation ID: $CONV_ID"
else
    echo -e "${RED}✗ Falha ao criar conversa${NC}"
    exit 1
fi
echo ""

# 4. Enviar mensagem
echo -e "${YELLOW}4. Enviando mensagem...${NC}"
MSG_RESPONSE=$(curl -s -X POST "$API_URL/messages/send" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{
    \"conversation_id\": \"$CONV_ID\",
    \"message_type\": \"text\",
    \"content\": \"Olá, esta é uma mensagem de teste! 🚀\"
  }")

echo "$MSG_RESPONSE" | jq '.'

MSG_ID=$(echo "$MSG_RESPONSE" | jq -r '.sent_message.message_id')

if [ "$MSG_ID" != "null" ] && [ "$MSG_ID" != "" ]; then
    echo -e "${GREEN}✓ Mensagem enviada${NC}"
    echo "Message ID: $MSG_ID"
else
    echo -e "${RED}✗ Falha ao enviar mensagem${NC}"
    exit 1
fi
echo ""

# 5. Listar mensagens
echo -e "${YELLOW}5. Listando mensagens...${NC}"
MESSAGES=$(curl -s -X GET "$API_URL/conversations/$CONV_ID/messages?limit=10" \
  -H "Authorization: Bearer $TOKEN")

echo "$MESSAGES" | jq '.'

MSG_COUNT=$(echo "$MESSAGES" | jq '.messages | length')
echo -e "${GREEN}✓ $MSG_COUNT mensagem(ns) encontrada(s)${NC}"
echo ""

# 6. Listar conversas
echo -e "${YELLOW}6. Listando conversas...${NC}"
CONVERSATIONS=$(curl -s -X GET "$API_URL/conversations" \
  -H "Authorization: Bearer $TOKEN")

echo "$CONVERSATIONS" | jq '.'

CONV_COUNT=$(echo "$CONVERSATIONS" | jq '.conversations | length')
echo -e "${GREEN}✓ $CONV_COUNT conversa(s) encontrada(s)${NC}"
echo ""

# 7. Criar grupo
echo -e "${YELLOW}7. Criando grupo de teste...${NC}"

# Pegar ID do Bob
BOB_LOGIN=$(curl -s -X POST "$API_URL/auth/login" \
  -H "Content-Type: application/json" \
  -d '{
    "email": "bob@chat4all.com",
    "password": "password"
  }')

BOB_ID=$(echo "$BOB_LOGIN" | jq -r '.user.user_id')

GROUP_RESPONSE=$(curl -s -X POST "$API_URL/conversations/group" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{
    \"group_name\": \"Grupo de Testes\",
    \"member_user_ids\": [\"$ALICE_ID\", \"$BOB_ID\"]
  }")

echo "$GROUP_RESPONSE" | jq '.'

GROUP_ID=$(echo "$GROUP_RESPONSE" | jq -r '.conversation.conversation_id')

if [ "$GROUP_ID" != "null" ] && [ "$GROUP_ID" != "" ]; then
    echo -e "${GREEN}✓ Grupo criado${NC}"
    echo "Group ID: $GROUP_ID"
else
    echo -e "${RED}✗ Falha ao criar grupo${NC}"
fi
echo ""

# 8. Enviar mensagem no grupo
echo -e "${YELLOW}8. Enviando mensagem no grupo...${NC}"
GROUP_MSG=$(curl -s -X POST "$API_URL/messages/send" \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d "{
    \"conversation_id\": \"$GROUP_ID\",
    \"message_type\": \"text\",
    \"content\": \"Olá grupo! Esta é uma mensagem de teste.\"
  }")

echo "$GROUP_MSG" | jq '.'

if echo "$GROUP_MSG" | jq -e '.success' > /dev/null; then
    echo -e "${GREEN}✓ Mensagem enviada no grupo${NC}"
fi
echo ""

echo -e "${GREEN}========================================${NC}"
echo -e "${GREEN}✅ Todos os testes completados!${NC}"
echo -e "${GREEN}========================================${NC}"
echo ""
echo "IDs importantes para uso manual:"
echo "  User ID: $USER_ID"
echo "  Token: $TOKEN"
echo "  Conversa privada: $CONV_ID"
echo "  Grupo: $GROUP_ID"
echo "  Alice ID: $ALICE_ID"
echo "  Bob ID: $BOB_ID"
echo ""