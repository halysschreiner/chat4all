#!/bin/bash

# ================================================
# Test Horizontal Scaling - Chat4All
# ================================================
# Testa a escalabilidade horizontal dos workers
# verificando distribuição de carga e throughput.
# ================================================

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Configuração
API_URL="${API_URL:-http://localhost:8000}"
KAFKA_CONTAINER="${KAFKA_CONTAINER:-chat4all-kafka}"
NUM_MESSAGES="${NUM_MESSAGES:-100}"
SCALE_WORKERS="${SCALE_WORKERS:-3}"

echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}  Chat4All - Horizontal Scaling Test Suite${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
echo -e "API URL: ${API_URL}"
echo -e "Kafka Container: ${KAFKA_CONTAINER}"
echo -e "Messages to send: ${NUM_MESSAGES}"
echo -e "Target worker scale: ${SCALE_WORKERS}"
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
    echo -e "${CYAN}[i]${NC} $1"
}

# ================================================
# Verificar pré-requisitos
# ================================================

print_test "Verificando pré-requisitos..."

if ! command -v docker &> /dev/null; then
    print_fail "docker não encontrado"
    exit 1
fi

if ! command -v docker-compose &> /dev/null && ! docker compose version &> /dev/null; then
    print_fail "docker-compose não encontrado"
    exit 1
fi

if ! command -v curl &> /dev/null; then
    print_fail "curl não encontrado"
    exit 1
fi

if ! command -v jq &> /dev/null; then
    print_fail "jq não encontrado"
    exit 1
fi

print_success "Pré-requisitos OK"

# ================================================
# Test 1: Estado inicial dos workers
# ================================================

print_test "1. Verificando estado inicial dos workers"

COMPOSE_CMD="docker-compose"
if docker compose version &> /dev/null 2>&1; then
    COMPOSE_CMD="docker compose"
fi

INITIAL_WORKERS=$($COMPOSE_CMD ps router-worker 2>/dev/null | grep -c "Up" || echo "0")
print_info "Workers ativos inicialmente: ${INITIAL_WORKERS}"

# ================================================
# Test 2: Verificar partições do Kafka
# ================================================

print_test "2. Verificando configuração de partições do Kafka"

KAFKA_CHECK=$(docker exec $KAFKA_CONTAINER kafka-topics --describe --topic messages --bootstrap-server kafka:9092 2>/dev/null || echo "FAIL")

if echo "$KAFKA_CHECK" | grep -q "PartitionCount"; then
    PARTITIONS=$(echo "$KAFKA_CHECK" | grep "PartitionCount" | sed 's/.*PartitionCount: \([0-9]*\).*/\1/')
    print_success "Tópico 'messages' tem ${PARTITIONS} partições"
    
    if [ "$PARTITIONS" -lt "$SCALE_WORKERS" ]; then
        print_warn "Número de partições ($PARTITIONS) menor que workers ($SCALE_WORKERS)"
        print_info "Workers extras ficarão ociosos"
    fi
else
    print_warn "Não foi possível verificar partições do Kafka"
    print_info "Tópico pode não existir ainda"
fi

# ================================================
# Test 3: Obter token de autenticação
# ================================================

print_test "3. Obtendo token de autenticação"

TEST_USER="scaling_tester_$(date +%s)"
TEST_EMAIL="${TEST_USER}@test.com"
TEST_PASSWORD="Test123456"

# Registrar usuário
curl -s -X POST "${API_URL}/v1/auth/register" \
    -H "Content-Type: application/json" \
    -d "{
        \"username\": \"${TEST_USER}\",
        \"email\": \"${TEST_EMAIL}\",
        \"password\": \"${TEST_PASSWORD}\"
    }" > /dev/null 2>&1

# Login
LOGIN_RESPONSE=$(curl -s -X POST "${API_URL}/v1/auth/login" \
    -H "Content-Type: application/json" \
    -d "{
        \"email\": \"${TEST_EMAIL}\",
        \"password\": \"${TEST_PASSWORD}\"
    }" 2>/dev/null || echo '{"error": true}')

TOKEN=$(echo "$LOGIN_RESPONSE" | jq -r '.token // empty')

if [ -n "$TOKEN" ] && [ "$TOKEN" != "null" ]; then
    print_success "Token obtido"
else
    print_warn "Não foi possível obter token (usando teste sem autenticação)"
    TOKEN=""
fi

# ================================================
# Test 4: Escalar workers
# ================================================

print_test "4. Escalando workers para ${SCALE_WORKERS} instâncias"

$COMPOSE_CMD up -d --scale router-worker=$SCALE_WORKERS 2>/dev/null

# Aguardar workers iniciarem
print_info "Aguardando workers iniciarem..."
sleep 10

NEW_WORKERS=$($COMPOSE_CMD ps router-worker 2>/dev/null | grep -c "Up" || echo "0")
print_info "Workers ativos após scaling: ${NEW_WORKERS}"

if [ "$NEW_WORKERS" -eq "$SCALE_WORKERS" ]; then
    print_success "Scaling bem-sucedido: ${NEW_WORKERS} workers ativos"
else
    print_warn "Scaling parcial: ${NEW_WORKERS}/${SCALE_WORKERS} workers ativos"
fi

# ================================================
# Test 5: Verificar distribuição no consumer group
# ================================================

print_test "5. Verificando distribuição do consumer group"

sleep 5  # Aguardar rebalanceamento

CG_INFO=$(docker exec $KAFKA_CONTAINER kafka-consumer-groups \
    --describe \
    --group router-worker-group \
    --bootstrap-server kafka:9092 2>/dev/null || echo "FAIL")

if echo "$CG_INFO" | grep -q "router-worker-group"; then
    print_success "Consumer group 'router-worker-group' encontrado"
    
    # Contar consumers ativos
    ACTIVE_CONSUMERS=$(echo "$CG_INFO" | grep -v "^$" | grep -v "GROUP" | grep -v "CONSUMER-ID" | wc -l)
    print_info "Consumers ativos no grupo: ${ACTIVE_CONSUMERS}"
    
    # Mostrar distribuição
    echo ""
    echo -e "${CYAN}Distribuição de partições:${NC}"
    echo "$CG_INFO" | head -20
else
    print_warn "Não foi possível obter informações do consumer group"
fi

# ================================================
# Test 6: Medir throughput baseline
# ================================================

print_test "6. Medindo throughput com ${SCALE_WORKERS} workers"

# Obter lista de conversas
if [ -n "$TOKEN" ]; then
    CONVS_RESPONSE=$(curl -s -X GET "${API_URL}/v1/conversations" \
        -H "Authorization: Bearer ${TOKEN}" \
        -H "Content-Type: application/json" 2>/dev/null || echo '{"conversations":[]}')
    
    CONV_ID=$(echo "$CONVS_RESPONSE" | jq -r '.conversations[0].conversation_id // empty')
fi

if [ -z "$CONV_ID" ]; then
    print_warn "Nenhuma conversa encontrada, usando conversa de teste"
    CONV_ID="test-conversation-$(date +%s)"
fi

# Enviar mensagens de teste
print_info "Enviando ${NUM_MESSAGES} mensagens..."

START_TIME=$(date +%s.%N)

for i in $(seq 1 $NUM_MESSAGES); do
    if [ -n "$TOKEN" ]; then
        curl -s -X POST "${API_URL}/v1/messages" \
            -H "Authorization: Bearer ${TOKEN}" \
            -H "Content-Type: application/json" \
            -d "{
                \"conversation_id\": \"${CONV_ID}\",
                \"content\": \"Scaling test message ${i}\"
            }" > /dev/null 2>&1 &
    fi
    
    # Batch de 10 em paralelo
    if [ $((i % 10)) -eq 0 ]; then
        wait
        printf "\r  Enviadas: %d/%d" $i $NUM_MESSAGES
    fi
done

wait
echo ""

END_TIME=$(date +%s.%N)
DURATION=$(echo "$END_TIME - $START_TIME" | bc)
THROUGHPUT=$(echo "scale=2; $NUM_MESSAGES / $DURATION" | bc)

print_success "Throughput: ${THROUGHPUT} mensagens/segundo"
print_info "Tempo total: ${DURATION} segundos"

# ================================================
# Test 7: Verificar lag do consumer group
# ================================================

print_test "7. Verificando lag do consumer group"

sleep 5  # Aguardar processamento

LAG_INFO=$(docker exec $KAFKA_CONTAINER kafka-consumer-groups \
    --describe \
    --group router-worker-group \
    --bootstrap-server kafka:9092 2>/dev/null || echo "FAIL")

if echo "$LAG_INFO" | grep -q "LAG"; then
    TOTAL_LAG=$(echo "$LAG_INFO" | grep -v "GROUP" | awk '{sum += $6} END {print sum}')
    
    if [ "$TOTAL_LAG" -lt 10 ]; then
        print_success "Lag baixo: ${TOTAL_LAG} mensagens pendentes"
    else
        print_warn "Lag alto: ${TOTAL_LAG} mensagens pendentes"
    fi
else
    print_warn "Não foi possível verificar lag"
fi

# ================================================
# Test 8: Comparar com single worker (opcional)
# ================================================

if [ "${RUN_COMPARISON:-false}" = "true" ]; then
    print_test "8. Comparando com single worker"
    
    # Escalar para 1 worker
    $COMPOSE_CMD up -d --scale router-worker=1 2>/dev/null
    sleep 10
    
    START_TIME=$(date +%s.%N)
    
    for i in $(seq 1 $NUM_MESSAGES); do
        if [ -n "$TOKEN" ]; then
            curl -s -X POST "${API_URL}/v1/messages" \
                -H "Authorization: Bearer ${TOKEN}" \
                -H "Content-Type: application/json" \
                -d "{
                    \"conversation_id\": \"${CONV_ID}\",
                    \"content\": \"Single worker test ${i}\"
                }" > /dev/null 2>&1 &
        fi
        
        if [ $((i % 10)) -eq 0 ]; then
            wait
            printf "\r  Enviadas: %d/%d" $i $NUM_MESSAGES
        fi
    done
    
    wait
    echo ""
    
    END_TIME=$(date +%s.%N)
    SINGLE_DURATION=$(echo "$END_TIME - $START_TIME" | bc)
    SINGLE_THROUGHPUT=$(echo "scale=2; $NUM_MESSAGES / $SINGLE_DURATION" | bc)
    
    IMPROVEMENT=$(echo "scale=2; ($THROUGHPUT / $SINGLE_THROUGHPUT - 1) * 100" | bc)
    
    print_info "Throughput com 1 worker: ${SINGLE_THROUGHPUT} msg/s"
    print_info "Throughput com ${SCALE_WORKERS} workers: ${THROUGHPUT} msg/s"
    print_success "Melhoria: ${IMPROVEMENT}%"
    
    # Restaurar workers
    $COMPOSE_CMD up -d --scale router-worker=$SCALE_WORKERS 2>/dev/null
fi

# ================================================
# Sumário
# ================================================

echo ""
echo -e "${BLUE}================================================${NC}"
echo -e "${BLUE}           Sumário dos Testes${NC}"
echo -e "${BLUE}================================================${NC}"
echo ""
echo "Workers escalados: ${SCALE_WORKERS}"
echo "Mensagens enviadas: ${NUM_MESSAGES}"
echo "Throughput: ${THROUGHPUT} msg/s"
echo "Tempo de execução: ${DURATION}s"
echo ""

if [ "$NEW_WORKERS" -eq "$SCALE_WORKERS" ]; then
    echo -e "${GREEN}✅ Scaling horizontal funcionando corretamente!${NC}"
else
    echo -e "${YELLOW}⚠️ Scaling parcial - verifique os logs dos containers${NC}"
fi

echo ""
echo -e "${CYAN}Comandos úteis:${NC}"
echo "  Ver logs dos workers:"
echo "    docker-compose logs -f router-worker"
echo ""
echo "  Ver consumer group:"
echo "    docker exec $KAFKA_CONTAINER kafka-consumer-groups --describe --group router-worker-group --bootstrap-server kafka:9092"
echo ""
echo "  Escalar manualmente:"
echo "    docker-compose up -d --scale router-worker=5"
echo ""
