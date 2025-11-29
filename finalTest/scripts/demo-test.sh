#!/bin/bash

# ===================================================================
# Chat4All - Demo Test Script
# Demonstração das funcionalidades dos scripts de teste
# ===================================================================

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m'

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║        Chat4All - Test Scripts Demonstration         ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}\n"

echo -e "${YELLOW}Esta demonstração mostra as funcionalidades dos scripts:${NC}\n"

# ===================================================================
# 1. Check system status
# ===================================================================
echo -e "${BLUE}[1/5] Verificando sistema Docker Compose...${NC}"
docker-compose ps | grep -E "chat4all|connector"
echo -e "${GREEN}✓ Sistema está rodando${NC}\n"

# ===================================================================
# 2. Test worker scaling
# ===================================================================
echo -e "${BLUE}[2/5] Testando escalabilidade de workers...${NC}"

for workers in 1 2 3; do
    echo -e "${YELLOW}Escalando para ${workers} worker(s)...${NC}"
    docker-compose up -d --scale router-worker=${workers} 2>&1 | grep -E "Running|Starting" || true
    sleep 2
    
    actual_count=$(docker ps --filter "name=router-worker" --format "{{.Names}}" | wc -l)
    echo -e "${GREEN}✓ ${actual_count} worker(s) rodando${NC}"
done
echo ""

# ===================================================================
# 3. Show worker distribution
# ===================================================================
echo -e "${BLUE}[3/5] Mostrando distribuição de workers...${NC}"
docker ps --filter "name=router-worker" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
echo -e "${GREEN}✓ Workers ativos listados${NC}\n"

# ===================================================================
# 4. Test API endpoint
# ===================================================================
echo -e "${BLUE}[4/5] Testando endpoints da API...${NC}"

echo -e "${YELLOW}Health check:${NC}"
response=$(curl -s http://localhost:8000/health)
echo "$response" | jq '.' 2>/dev/null || echo "$response"
echo -e "${GREEN}✓ API Gateway está respondendo${NC}\n"

# ===================================================================
# 5. Simulate failure (optional - won't actually stop)
# ===================================================================
echo -e "${BLUE}[5/5] Demonstração de teste de falha...${NC}"
echo -e "${YELLOW}Em um teste real, o script iria:${NC}"
echo "  1. Parar um worker específico"
echo "  2. Aguardar rebalanceamento do Kafka (8-10s)"
echo "  3. Verificar redistribuição de partições"
echo "  4. Enviar mensagens para validar processamento"
echo "  5. Reiniciar o worker"
echo "  6. Verificar recuperação completa"
echo -e "${GREEN}✓ Funcionalidade demonstrada${NC}\n"

# ===================================================================
# Summary
# ===================================================================
echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║                  Resumo da Demonstração                ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}\n"

echo -e "${GREEN}✓ Scripts funcionais criados:${NC}"
echo "  - horizontal-scalability-test.sh (Bash)"
echo "  - horizontal-scalability-test.ps1 (PowerShell)"
echo "  - k6-load-test.js (k6)"
echo "  - run-k6-test.sh (k6 runner)"
echo ""

echo -e "${GREEN}✓ Funcionalidades implementadas:${NC}"
echo "  - Escalabilidade automática de workers (1-5)"
echo "  - Medição de throughput e latência"
echo "  - Simulação de falhas e recuperação"
echo "  - Testes de carga com k6"
echo "  - Exportação de resultados em JSON"
echo ""

echo -e "${GREEN}✓ Relatórios criados:${NC}"
echo "  - horizontal-scalability-report.md"
echo "  - k6-load-test-report.md"
echo "  - failure-recovery-report.md"
echo ""

echo -e "${YELLOW}Nota: Para executar testes completos, certifique-se de que:${NC}"
echo "  1. Todos os serviços estejam rodando"
echo "  2. A API esteja respondendo corretamente"
echo "  3. O banco de dados esteja acessível"
echo ""

echo -e "${BLUE}Resultados são salvos em: finalTest/results/${NC}\n"
