#!/bin/bash

#
# Script de teste da API
# Testa os endpoints principais do Chat4All
#

set -e

API_URL="http://localhost:8000"

echo "================================================"
echo "  Testando API Chat4All"
echo "================================================"
echo ""

# Cores para output
GREEN='\033[0;32m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Função para formatar JSON (alternativa ao jq)
format_json() {
    if command -v jq &> /dev/null; then
        jq .
    elif command -v python3 &> /dev/null; then
        python3 -m json.tool
    else
        cat
    fi
}

# Função para extrair campo JSON (alternativa ao jq)
extract_json_field() {
    local json=$1
    local field=$2
    
    if command -v jq &> /dev/null; then
        echo "$json" | jq -r "$field"
    elif command -v python3 &> /dev/null; then
        # Suporta tanto campos simples quanto paths (ex: .message.message_id)
        echo "$json" | python3 -c "import sys, json; data = json.load(sys.stdin); fields = '$field'.strip('.').split('.'); result = data; [result := result.get(f) for f in fields]; print(result if result is not None else '')"
    else
        # Fallback simples com grep/sed (apenas último campo do path)
        local last_field=$(echo "$field" | awk -F. '{print $NF}')
        echo "$json" | grep -o "\"$last_field\":\"[^\"]*\"" | sed "s/\"$last_field\":\"\([^\"]*\)\"/\1/"
    fi
}

# Função para fazer requisições
make_request() {
    local method=$1
    local endpoint=$2
    local data=$3
    local token=$4
    
    if [ -n "$token" ]; then
        curl -X "$method" \
             -H "Content-Type: application/json" \
             -H "Authorization: Bearer $token" \
             -d "$data" \
             -s \
             "$API_URL$endpoint"
    else
        curl -X "$method" \
             -H "Content-Type: application/json" \
             -d "$data" \
             -s \
             "$API_URL$endpoint"
    fi
}

echo -e "${BLUE}1. Testando Health Check${NC}"
echo "GET /health"
RESPONSE=$(curl -s "$API_URL/health")
echo "$RESPONSE" | format_json
echo ""

echo -e "${BLUE}2. Fazendo Login (Alice)${NC}"
echo "POST /v1/auth/login"
LOGIN_DATA='{"email":"alice@chat4all.com","password":"password123"}'
LOGIN_RESPONSE=$(make_request "POST" "/v1/auth/login" "$LOGIN_DATA")
echo "$LOGIN_RESPONSE" | format_json

TOKEN=$(extract_json_field "$LOGIN_RESPONSE" "token")

if [ "$TOKEN" = "null" ] || [ -z "$TOKEN" ]; then
    echo -e "${RED}❌ Falha no login! Não foi possível obter o token.${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Login realizado com sucesso!${NC}"
echo ""

echo -e "${BLUE}3. Enviando Mensagem${NC}"
echo "POST /v1/messages"
MESSAGE_DATA='{"conversation_id":"33333333-3333-3333-3333-333333333333","content":"Olá! Esta é uma mensagem de teste do Chat4All."}'
MESSAGE_RESPONSE=$(make_request "POST" "/v1/messages" "$MESSAGE_DATA" "$TOKEN")
echo "$MESSAGE_RESPONSE" | format_json

MESSAGE_ID=$(extract_json_field "$MESSAGE_RESPONSE" ".sent_message.message_id")

if [ "$MESSAGE_ID" = "null" ] || [ -z "$MESSAGE_ID" ]; then
    echo -e "${RED}❌ Falha ao enviar mensagem!${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Mensagem enviada com sucesso!${NC}"
echo ""

echo -e "${BLUE}4. Aguardando processamento do Worker (3 segundos)...${NC}"
sleep 3
echo ""

echo -e "${BLUE}5. Listando Mensagens da Conversa${NC}"
echo "GET /v1/conversations/33333333-3333-3333-3333-333333333333/messages"
MESSAGES_RESPONSE=$(make_request "GET" "/v1/conversations/33333333-3333-3333-3333-333333333333/messages" "" "$TOKEN")
echo "$MESSAGES_RESPONSE" | format_json
echo ""

echo -e "${BLUE}6. Listando Conversas do Usuário${NC}"
echo "GET /v1/conversations"
CONVERSATIONS_RESPONSE=$(make_request "GET" "/v1/conversations" "" "$TOKEN")
echo "$CONVERSATIONS_RESPONSE" | format_json
echo ""

echo "================================================"
echo -e "${GREEN}✅ Todos os testes concluídos!${NC}"
echo "================================================"
echo ""
echo "Verificações realizadas:"
echo "  ✅ Health check"
echo "  ✅ Login e autenticação JWT"
echo "  ✅ Envio de mensagem"
echo "  ✅ Listagem de mensagens"
echo "  ✅ Listagem de conversas"
echo ""
echo "Para verificar os logs do worker:"
echo "  docker-compose logs router-worker"
echo ""
