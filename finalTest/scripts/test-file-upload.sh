#!/bin/bash
# ================================================
# Script de Teste - Upload de Arquivos
# Chat4All - Sistema de Mensagens Distribuído
# ================================================
# Testa funcionalidade completa de upload multipart
# incluindo inicialização, upload de partes e conclusão.
# ================================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configurações
API_URL="${API_URL:-http://localhost:8080}"
USERNAME="${USERNAME:-alice}"
PASSWORD="${PASSWORD:-password123}"
TEST_FILE_SIZE_MB="${TEST_FILE_SIZE_MB:-10}" # Tamanho do arquivo de teste em MB

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}   Teste de Upload de Arquivos - Chat4All${NC}"
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

# Função para fazer login e obter token JWT
get_token() {
    echo -e "${YELLOW}→ Fazendo login como $USERNAME...${NC}"
    
    RESPONSE=$(curl -s -X POST "$API_URL/v1/auth/login" \
        -H "Content-Type: application/json" \
        -d "{\"username\": \"$USERNAME\", \"password\": \"$PASSWORD\"}")
    
    TOKEN=$(echo "$RESPONSE" | jq -r '.token // empty')
    
    if [ -z "$TOKEN" ]; then
        echo -e "${RED}Falha no login: $RESPONSE${NC}"
        exit 1
    fi
    
    print_result 0 "Login realizado com sucesso"
    echo "$TOKEN"
}

# Criar arquivo de teste
create_test_file() {
    echo -e "${YELLOW}→ Criando arquivo de teste (${TEST_FILE_SIZE_MB}MB)...${NC}"
    
    TEST_FILE="/tmp/chat4all_test_upload_$$.bin"
    dd if=/dev/urandom of="$TEST_FILE" bs=1M count="$TEST_FILE_SIZE_MB" status=progress 2>/dev/null
    
    # Calcular checksum
    CHECKSUM=$(sha256sum "$TEST_FILE" | cut -d' ' -f1)
    FILE_SIZE=$(stat -f%z "$TEST_FILE" 2>/dev/null || stat -c%s "$TEST_FILE")
    
    print_result 0 "Arquivo de teste criado: $TEST_FILE ($FILE_SIZE bytes)"
    echo "$TEST_FILE"
}

# Iniciar upload multipart
initiate_upload() {
    local TOKEN="$1"
    local FILENAME="$2"
    local FILE_SIZE="$3"
    local CONVERSATION_ID="${4:-33333333-3333-3333-3333-333333333333}"
    
    echo -e "${YELLOW}→ Iniciando upload multipart...${NC}"
    
    RESPONSE=$(curl -s -X POST "$API_URL/v1/files/upload/initiate" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Content-Type: application/json" \
        -d "{
            \"conversation_id\": \"$CONVERSATION_ID\",
            \"filename\": \"$FILENAME\",
            \"file_size\": $FILE_SIZE,
            \"content_type\": \"application/octet-stream\"
        }")
    
    echo "$RESPONSE"
}

# Upload de uma parte
upload_part() {
    local TOKEN="$1"
    local UPLOAD_ID="$2"
    local FILE_ID="$3"
    local PART_NUMBER="$4"
    local PART_FILE="$5"
    
    echo -e "${YELLOW}  → Enviando parte $PART_NUMBER...${NC}"
    
    # Converter para base64 para envio JSON
    PART_DATA=$(base64 < "$PART_FILE" | tr -d '\n')
    
    RESPONSE=$(curl -s -X POST "$API_URL/v1/files/upload/part" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Content-Type: application/json" \
        -d "{
            \"upload_id\": \"$UPLOAD_ID\",
            \"file_id\": \"$FILE_ID\",
            \"part_number\": $PART_NUMBER,
            \"data\": \"$PART_DATA\"
        }")
    
    echo "$RESPONSE"
}

# Completar upload
complete_upload() {
    local TOKEN="$1"
    local UPLOAD_ID="$2"
    local FILE_ID="$3"
    
    echo -e "${YELLOW}→ Completando upload...${NC}"
    
    RESPONSE=$(curl -s -X POST "$API_URL/v1/files/upload/complete" \
        -H "Authorization: Bearer $TOKEN" \
        -H "Content-Type: application/json" \
        -d "{
            \"upload_id\": \"$UPLOAD_ID\",
            \"file_id\": \"$FILE_ID\"
        }")
    
    echo "$RESPONSE"
}

# Obter informações do arquivo
get_file_info() {
    local TOKEN="$1"
    local FILE_ID="$2"
    
    echo -e "${YELLOW}→ Obtendo informações do arquivo...${NC}"
    
    RESPONSE=$(curl -s -X GET "$API_URL/v1/files/$FILE_ID" \
        -H "Authorization: Bearer $TOKEN")
    
    echo "$RESPONSE"
}

# Obter URL de download
get_download_url() {
    local TOKEN="$1"
    local FILE_ID="$2"
    
    echo -e "${YELLOW}→ Gerando URL de download...${NC}"
    
    RESPONSE=$(curl -s -X GET "$API_URL/v1/files/$FILE_ID/download-url" \
        -H "Authorization: Bearer $TOKEN")
    
    echo "$RESPONSE"
}

# ================================================
# TESTE PRINCIPAL
# ================================================

echo ""
echo -e "${BLUE}=== Fase 1: Autenticação ===${NC}"

TOKEN=$(get_token)
echo ""

echo -e "${BLUE}=== Fase 2: Preparação ===${NC}"

TEST_FILE=$(create_test_file)
FILE_SIZE=$(stat -f%z "$TEST_FILE" 2>/dev/null || stat -c%s "$TEST_FILE")
FILENAME="test_upload_$(date +%s).bin"

echo ""
echo -e "${BLUE}=== Fase 3: Upload Multipart ===${NC}"

# Iniciar upload
INIT_RESPONSE=$(initiate_upload "$TOKEN" "$FILENAME" "$FILE_SIZE")
echo "Resposta: $INIT_RESPONSE"

UPLOAD_ID=$(echo "$INIT_RESPONSE" | jq -r '.upload_id // empty')
FILE_ID=$(echo "$INIT_RESPONSE" | jq -r '.file_id // empty')
TOTAL_PARTS=$(echo "$INIT_RESPONSE" | jq -r '.total_parts // 1')
PART_SIZE=$(echo "$INIT_RESPONSE" | jq -r '.part_size // 5242880')

if [ -z "$UPLOAD_ID" ] || [ -z "$FILE_ID" ]; then
    print_result 1 "Falha ao iniciar upload"
    exit 1
fi

print_result 0 "Upload iniciado: file_id=$FILE_ID, total_parts=$TOTAL_PARTS"

echo ""
echo -e "${YELLOW}→ Dividindo arquivo em $TOTAL_PARTS partes...${NC}"

# Dividir arquivo e fazer upload das partes
TEMP_DIR="/tmp/chat4all_parts_$$"
mkdir -p "$TEMP_DIR"
split -b "$PART_SIZE" "$TEST_FILE" "$TEMP_DIR/part_"

PART_NUMBER=1
for PART_FILE in "$TEMP_DIR"/part_*; do
    PART_RESPONSE=$(upload_part "$TOKEN" "$UPLOAD_ID" "$FILE_ID" "$PART_NUMBER" "$PART_FILE")
    
    SUCCESS=$(echo "$PART_RESPONSE" | jq -r '.success // false')
    if [ "$SUCCESS" != "true" ]; then
        echo -e "${RED}Erro ao enviar parte $PART_NUMBER: $PART_RESPONSE${NC}"
        rm -rf "$TEMP_DIR" "$TEST_FILE"
        exit 1
    fi
    
    PART_NUMBER=$((PART_NUMBER + 1))
done

print_result 0 "Todas as partes enviadas com sucesso"

# Limpar arquivos temporários das partes
rm -rf "$TEMP_DIR"

echo ""
echo -e "${BLUE}=== Fase 4: Completar Upload ===${NC}"

COMPLETE_RESPONSE=$(complete_upload "$TOKEN" "$UPLOAD_ID" "$FILE_ID")
echo "Resposta: $COMPLETE_RESPONSE"

SUCCESS=$(echo "$COMPLETE_RESPONSE" | jq -r '.success // false')
if [ "$SUCCESS" != "true" ]; then
    print_result 1 "Falha ao completar upload"
    rm -f "$TEST_FILE"
    exit 1
fi

print_result 0 "Upload completado com sucesso"

echo ""
echo -e "${BLUE}=== Fase 5: Verificação ===${NC}"

# Obter informações do arquivo
FILE_INFO=$(get_file_info "$TOKEN" "$FILE_ID")
echo "Informações: $FILE_INFO"

STATUS=$(echo "$FILE_INFO" | jq -r '.status // empty')
if [ "$STATUS" != "completed" ]; then
    print_result 1 "Status do arquivo não é 'completed': $STATUS"
    rm -f "$TEST_FILE"
    exit 1
fi

print_result 0 "Arquivo verificado no servidor (status: completed)"

# Obter URL de download
DOWNLOAD_RESPONSE=$(get_download_url "$TOKEN" "$FILE_ID")
echo "URL Response: $DOWNLOAD_RESPONSE"

DOWNLOAD_URL=$(echo "$DOWNLOAD_RESPONSE" | jq -r '.url // .download_url // empty')
if [ -z "$DOWNLOAD_URL" ]; then
    echo -e "${YELLOW}⚠ URL de download não disponível (pode ser normal em ambiente de teste)${NC}"
else
    print_result 0 "URL de download gerada com sucesso"
fi

echo ""
echo -e "${BLUE}=== Fase 6: Limpeza ===${NC}"

rm -f "$TEST_FILE"
print_result 0 "Arquivos temporários removidos"

echo ""
echo -e "${GREEN}================================================${NC}"
echo -e "${GREEN}   TESTE CONCLUÍDO COM SUCESSO!${NC}"
echo -e "${GREEN}================================================${NC}"
echo ""
echo -e "Resumo:"
echo -e "  - File ID: $FILE_ID"
echo -e "  - Tamanho: $FILE_SIZE bytes"
echo -e "  - Partes: $TOTAL_PARTS"
echo -e "  - Status: completed"
echo ""
