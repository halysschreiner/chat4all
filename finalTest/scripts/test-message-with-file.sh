#!/bin/bash
# ================================================
# Script de Teste - Mensagens com Arquivo
# Chat4All - Sistema de Mensagens Distribuído
# ================================================
# Testa envio de mensagens com anexos de arquivo.
# ================================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

API_URL="${API_URL:-http://localhost:8080}"
USERNAME="${USERNAME:-alice}"
PASSWORD="${PASSWORD:-password123}"
CONVERSATION_ID="${CONVERSATION_ID:-33333333-3333-3333-3333-333333333333}"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  Teste de Mensagens com Arquivo - Chat4All${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""

# Função para exibir resultados
print_result() {
    if [ $1 -eq 0 ]; then
        echo -e "${GREEN}✓ $2${NC}"
    else
        echo -e "${RED}✗ $2${NC}"
        exit 1
    fi
}

# Login
echo -e "${YELLOW}→ Fazendo login...${NC}"
TOKEN=$(curl -s -X POST "$API_URL/v1/auth/login" \
    -H "Content-Type: application/json" \
    -d "{\"username\": \"$USERNAME\", \"password\": \"$PASSWORD\"}" | jq -r '.token // empty')

if [ -z "$TOKEN" ]; then
    print_result 1 "Falha no login"
    exit 1
fi
print_result 0 "Login realizado"

# Teste 1: Enviar mensagem de texto simples
echo ""
echo -e "${BLUE}=== Teste 1: Mensagem de Texto Simples ===${NC}"

RESPONSE=$(curl -s -X POST "$API_URL/v1/messages" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"conversation_id\": \"$CONVERSATION_ID\",
        \"content\": \"Mensagem de teste - $(date +%H:%M:%S)\",
        \"message_type\": \"text\"
    }")

SUCCESS=$(echo "$RESPONSE" | jq -r '.success // false')
MESSAGE_ID=$(echo "$RESPONSE" | jq -r '.message.message_id // empty')

if [ "$SUCCESS" = "true" ] && [ -n "$MESSAGE_ID" ]; then
    print_result 0 "Mensagem de texto enviada: $MESSAGE_ID"
else
    echo "$RESPONSE"
    print_result 1 "Falha ao enviar mensagem de texto"
fi

# Teste 2: Criar arquivo pequeno e iniciar upload
echo ""
echo -e "${BLUE}=== Teste 2: Upload de Arquivo ===${NC}"

echo -e "${YELLOW}→ Criando arquivo de teste...${NC}"
TEST_FILE="/tmp/test_attachment_$$.txt"
echo "Este é um arquivo de teste para anexo. Timestamp: $(date)" > "$TEST_FILE"
FILE_SIZE=$(stat -f%z "$TEST_FILE" 2>/dev/null || stat -c%s "$TEST_FILE")

echo -e "${YELLOW}→ Iniciando upload...${NC}"
INIT_RESPONSE=$(curl -s -X POST "$API_URL/v1/files/upload/initiate" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"conversation_id\": \"$CONVERSATION_ID\",
        \"filename\": \"documento_teste.txt\",
        \"file_size\": $FILE_SIZE,
        \"content_type\": \"text/plain\"
    }")

UPLOAD_ID=$(echo "$INIT_RESPONSE" | jq -r '.upload_id // empty')
FILE_ID=$(echo "$INIT_RESPONSE" | jq -r '.file_id // empty')

if [ -z "$UPLOAD_ID" ] || [ -z "$FILE_ID" ]; then
    echo "$INIT_RESPONSE"
    print_result 1 "Falha ao iniciar upload"
    rm -f "$TEST_FILE"
    exit 1
fi

print_result 0 "Upload iniciado: file_id=$FILE_ID"

# Upload da parte (arquivo pequeno = 1 parte)
echo -e "${YELLOW}→ Enviando dados do arquivo...${NC}"
PART_DATA=$(base64 < "$TEST_FILE" | tr -d '\n')

PART_RESPONSE=$(curl -s -X POST "$API_URL/v1/files/upload/part" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"upload_id\": \"$UPLOAD_ID\",
        \"file_id\": \"$FILE_ID\",
        \"part_number\": 1,
        \"data\": \"$PART_DATA\"
    }")

PART_SUCCESS=$(echo "$PART_RESPONSE" | jq -r '.success // false')
if [ "$PART_SUCCESS" != "true" ]; then
    echo "$PART_RESPONSE"
    print_result 1 "Falha ao enviar parte"
    rm -f "$TEST_FILE"
    exit 1
fi

print_result 0 "Parte enviada com sucesso"

# Completar upload
echo -e "${YELLOW}→ Completando upload...${NC}"
COMPLETE_RESPONSE=$(curl -s -X POST "$API_URL/v1/files/upload/complete" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"upload_id\": \"$UPLOAD_ID\",
        \"file_id\": \"$FILE_ID\"
    }")

COMPLETE_SUCCESS=$(echo "$COMPLETE_RESPONSE" | jq -r '.success // false')
if [ "$COMPLETE_SUCCESS" != "true" ]; then
    echo "$COMPLETE_RESPONSE"
    print_result 1 "Falha ao completar upload"
    rm -f "$TEST_FILE"
    exit 1
fi

print_result 0 "Upload completado"

# Teste 3: Enviar mensagem com file_id
echo ""
echo -e "${BLUE}=== Teste 3: Mensagem com Anexo ===${NC}"

echo -e "${YELLOW}→ Enviando mensagem com arquivo anexo...${NC}"
MSG_WITH_FILE_RESPONSE=$(curl -s -X POST "$API_URL/v1/messages" \
    -H "Authorization: Bearer $TOKEN" \
    -H "Content-Type: application/json" \
    -d "{
        \"conversation_id\": \"$CONVERSATION_ID\",
        \"content\": \"Segue o documento em anexo\",
        \"message_type\": \"file\",
        \"file_id\": \"$FILE_ID\"
    }")

MSG_SUCCESS=$(echo "$MSG_WITH_FILE_RESPONSE" | jq -r '.success // false')
MSG_ID=$(echo "$MSG_WITH_FILE_RESPONSE" | jq -r '.message.message_id // empty')
MSG_FILE_ID=$(echo "$MSG_WITH_FILE_RESPONSE" | jq -r '.message.file_id // empty')

if [ "$MSG_SUCCESS" = "true" ] && [ -n "$MSG_ID" ]; then
    print_result 0 "Mensagem com arquivo enviada: $MSG_ID"
    
    if [ "$MSG_FILE_ID" = "$FILE_ID" ]; then
        print_result 0 "file_id corretamente vinculado à mensagem"
    else
        print_result 1 "file_id não corresponde (esperado: $FILE_ID, recebido: $MSG_FILE_ID)"
    fi
else
    echo "$MSG_WITH_FILE_RESPONSE"
    print_result 1 "Falha ao enviar mensagem com arquivo"
fi

# Teste 4: Listar mensagens e verificar anexo
echo ""
echo -e "${BLUE}=== Teste 4: Verificar Mensagens ===${NC}"

echo -e "${YELLOW}→ Listando mensagens da conversa...${NC}"
MESSAGES_RESPONSE=$(curl -s -X GET "$API_URL/v1/conversations/$CONVERSATION_ID/messages?limit=5" \
    -H "Authorization: Bearer $TOKEN")

TOTAL_MESSAGES=$(echo "$MESSAGES_RESPONSE" | jq -r '.messages | length')

if [ "$TOTAL_MESSAGES" -gt 0 ]; then
    print_result 0 "Mensagens listadas: $TOTAL_MESSAGES"
    
    # Verificar se a última mensagem tem file_id
    LAST_MSG_FILE_ID=$(echo "$MESSAGES_RESPONSE" | jq -r '.messages[0].file_id // empty')
    if [ -n "$LAST_MSG_FILE_ID" ]; then
        print_result 0 "Última mensagem contém file_id: $LAST_MSG_FILE_ID"
    fi
else
    print_result 1 "Nenhuma mensagem encontrada"
fi

# Limpeza
rm -f "$TEST_FILE"

echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}   TESTE CONCLUÍDO COM SUCESSO!${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo "Resumo:"
echo "  - Mensagens de texto: OK"
echo "  - Upload de arquivo: OK"
echo "  - Mensagem com anexo: OK"
echo "  - File ID vinculado: $FILE_ID"
echo ""
