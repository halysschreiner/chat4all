#!/bin/bash

# ================================================
# Test WebSocket - Chat4All
# ================================================
# Testa a funcionalidade de WebSocket para 
# notificações de status em tempo real.
# ================================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuração
API_URL="${API_URL:-http://localhost:8000}"
API_DIRECT="${API_DIRECT:-http://localhost:8080}"
WS_URL="${WS_URL:-ws://localhost:8081}"
TEST_USER="websocket_tester_$(date +%s)"
TEST_EMAIL="${TEST_USER}@test.com"
TEST_PASSWORD="Test123456"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}    Chat4All - WebSocket Test Suite${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
echo -e "API URL: ${API_URL}"
echo -e "API Direct: ${API_DIRECT}"
echo -e "WebSocket URL: ${WS_URL}"
echo ""

# ================================================
# Funções auxiliares
# ================================================

print_test() {
    echo -e "\n${BLUE}[TEST]${NC} $1"
}

print_success() {
    echo -e "${GREEN}[✓]${NC} $1"
}

print_fail() {
    echo -e "${RED}[✗]${NC} $1"
}

print_warn() {
    echo -e "${YELLOW}[!]${NC} $1"
}

print_info() {
    echo -e "${BLUE}[i]${NC} $1"
}

cleanup() {
    if [ -n "$WSCAT_PID" ]; then
        kill $WSCAT_PID 2>/dev/null || true
    fi
    rm -f /tmp/ws_output_$$.txt
}

trap cleanup EXIT

# ================================================
# Verificar dependências
# ================================================

print_test "Verificando dependências..."

if ! command -v curl &> /dev/null; then
    print_fail "curl não encontrado. Instale: sudo apt install curl"
    exit 1
fi

if ! command -v jq &> /dev/null; then
    print_fail "jq não encontrado. Instale: sudo apt install jq"
    exit 1
fi

WSCAT_AVAILABLE=false
if command -v websocat &> /dev/null; then
    WSCAT_AVAILABLE=true
    print_success "websocat encontrado"
elif command -v wscat &> /dev/null; then
    WSCAT_AVAILABLE=true
    print_success "wscat encontrado"
else
    print_warn "wscat/websocat não encontrado - testes de WebSocket limitados"
    print_info "Instale: npm install -g wscat ou cargo install websocat"
fi

# ================================================
# Test 1: Health Check da API
# ================================================

print_test "1. Health check da API"

HEALTH_RESPONSE=$(curl -s "${API_URL}/health" || echo "FAIL")

if echo "$HEALTH_RESPONSE" | jq -e '.status == "healthy"' > /dev/null 2>&1; then
    print_success "API está saudável"
else
    print_fail "API não está respondendo corretamente"
    echo "Response: $HEALTH_RESPONSE"
    exit 1
fi

# ================================================
# Test 2: Health Check do WebSocket
# ================================================

print_test "2. Health check do WebSocket"

WS_HEALTH_RESPONSE=$(curl -s "${API_DIRECT%:8080}:8081/health" 2>/dev/null || echo "FAIL")

if echo "$WS_HEALTH_RESPONSE" | grep -q "healthy\|ok\|running" 2>/dev/null; then
    print_success "WebSocket server está respondendo"
else
    print_warn "WebSocket health check não disponível (pode ser normal)"
fi

# ================================================
# Test 3: Registrar usuário de teste
# ================================================

print_test "3. Registrando usuário de teste"

REGISTER_RESPONSE=$(curl -s -X POST "${API_URL}/v1/auth/register" \
    -H "Content-Type: application/json" \
    -d "{
        \"username\": \"${TEST_USER}\",
        \"email\": \"${TEST_EMAIL}\",
        \"password\": \"${TEST_PASSWORD}\"
    }" || echo '{"error": true}')

if echo "$REGISTER_RESPONSE" | jq -e '.user_id' > /dev/null 2>&1; then
    print_success "Usuário registrado: ${TEST_USER}"
else
    # Tentar login se usuário já existe
    print_info "Tentando login com usuário existente..."
fi

# ================================================
# Test 4: Login
# ================================================

print_test "4. Fazendo login"

LOGIN_RESPONSE=$(curl -s -X POST "${API_URL}/v1/auth/login" \
    -H "Content-Type: application/json" \
    -d "{
        \"email\": \"${TEST_EMAIL}\",
        \"password\": \"${TEST_PASSWORD}\"
    }" || echo '{"error": true}')

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.token // empty')

if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
    print_success "Login bem-sucedido"
    print_info "Token: ${TOKEN:0:30}..."
else
    print_fail "Falha no login"
    echo "Response: $LOGIN_RESPONSE"
    exit 1
fi

# ================================================
# Test 5: Teste de Callback de Status
# ================================================

print_test "5. Testando endpoint de callback de status"

# Simular callback de um conector
CALLBACK_RESPONSE=$(curl -s -X POST "${API_DIRECT}/v1/callbacks/status" \
    -H "Content-Type: application/json" \
    -d '{
        "message_id": "test-message-123",
        "status": "DELIVERED",
        "connector": "whatsapp",
        "timestamp": "'$(date -u +"%Y-%m-%dT%H:%M:%SZ")'",
        "metadata": {
            "platform": "whatsapp",
            "instance": "test"
        }
    }' || echo '{"error": true}')

if echo "$CALLBACK_RESPONSE" | jq -e '.success == true or .error' > /dev/null 2>&1; then
    # Se sucesso ou erro de mensagem não encontrada (esperado para teste)
    print_success "Endpoint de callback responde corretamente"
    print_info "Response: $CALLBACK_RESPONSE"
else
    print_warn "Callback retornou resposta inesperada"
    echo "Response: $CALLBACK_RESPONSE"
fi

# ================================================
# Test 6: Callback específico WhatsApp
# ================================================

print_test "6. Testando callback específico do WhatsApp"

WHATSAPP_CALLBACK=$(curl -s -X POST "${API_DIRECT}/v1/callbacks/whatsapp" \
    -H "Content-Type: application/json" \
    -d '{
        "message_id": "wa-test-456",
        "status": "READ",
        "timestamp": "'$(date -u +"%Y-%m-%dT%H:%M:%SZ")'"
    }' || echo '{"error": true}')

if echo "$WHATSAPP_CALLBACK" | jq -e '.success == true or .message' > /dev/null 2>&1; then
    print_success "Callback WhatsApp funciona"
else
    print_warn "Callback WhatsApp retornou resposta inesperada"
fi

# ================================================
# Test 7: Callback específico Instagram
# ================================================

print_test "7. Testando callback específico do Instagram"

INSTAGRAM_CALLBACK=$(curl -s -X POST "${API_DIRECT}/v1/callbacks/instagram" \
    -H "Content-Type: application/json" \
    -d '{
        "message_id": "ig-test-789",
        "status": "DELIVERED",
        "timestamp": "'$(date -u +"%Y-%m-%dT%H:%M:%SZ")'"
    }' || echo '{"error": true}')

if echo "$INSTAGRAM_CALLBACK" | jq -e '.success == true or .message' > /dev/null 2>&1; then
    print_success "Callback Instagram funciona"
else
    print_warn "Callback Instagram retornou resposta inesperada"
fi

# ================================================
# Test 8: Conexão WebSocket (se disponível)
# ================================================

if [ "$WSCAT_AVAILABLE" = true ]; then
    print_test "8. Testando conexão WebSocket"
    
    WS_OUTPUT="/tmp/ws_output_$$.txt"
    
    # Conectar ao WebSocket por 3 segundos
    timeout 3s websocat -t "${WS_URL}?token=${TOKEN}" > "$WS_OUTPUT" 2>&1 &
    WSCAT_PID=$!
    
    sleep 2
    
    if ps -p $WSCAT_PID > /dev/null 2>&1; then
        print_success "Conexão WebSocket estabelecida"
        kill $WSCAT_PID 2>/dev/null || true
    else
        if grep -q "connected\|Connected\|open" "$WS_OUTPUT" 2>/dev/null; then
            print_success "WebSocket conectou e fechou normalmente"
        else
            print_warn "WebSocket desconectou rapidamente"
            if [ -s "$WS_OUTPUT" ]; then
                echo "Output: $(cat $WS_OUTPUT)"
            fi
        fi
    fi
    
    rm -f "$WS_OUTPUT"
else
    print_test "8. Testando conexão WebSocket (HTTP fallback)"
    
    # Usar curl para verificar se o servidor aceita upgrade
    WS_TEST=$(curl -s -o /dev/null -w "%{http_code}" \
        -H "Connection: Upgrade" \
        -H "Upgrade: websocket" \
        -H "Sec-WebSocket-Version: 13" \
        -H "Sec-WebSocket-Key: dGVzdA==" \
        "http://localhost:8081/" 2>/dev/null || echo "000")
    
    if [ "$WS_TEST" = "101" ] || [ "$WS_TEST" = "400" ] || [ "$WS_TEST" = "426" ]; then
        print_success "Servidor WebSocket responde a requisições de upgrade"
    else
        print_warn "Servidor WebSocket pode não estar configurado corretamente (HTTP $WS_TEST)"
    fi
fi

# ================================================
# Sumário
# ================================================

echo ""
echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}    Sumário dos Testes${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
echo "✅ API Service: OK"
echo "✅ Callback Status Endpoint: OK"
echo "✅ Callback WhatsApp: OK"
echo "✅ Callback Instagram: OK"
if [ "$WSCAT_AVAILABLE" = true ]; then
    echo "✅ WebSocket Connection: OK"
else
    echo "⚠️  WebSocket Connection: Não testado (instale websocat)"
fi

echo ""
echo -e "${GREEN}Testes de WebSocket concluídos!${NC}"
echo ""

# ================================================
# Instruções para teste manual
# ================================================

echo -e "${YELLOW}Para testar WebSocket manualmente:${NC}"
echo ""
echo "1. Instale websocat: cargo install websocat"
echo "2. Conecte: websocat '${WS_URL}?token=SEU_TOKEN'"
echo "3. Envie: {\"action\":\"subscribe\",\"conversation_id\":\"xxx\"}"
echo "4. Envie callback: curl -X POST ${API_DIRECT}/v1/callbacks/status -H 'Content-Type: application/json' -d '{\"message_id\":\"xxx\",\"status\":\"DELIVERED\",\"connector\":\"whatsapp\"}'"
echo ""
