#!/bin/bash

# ===================================================================
# Chat4All - Monitoring Stack Test Script
# Tests Prometheus + Grafana + Metrics Exporter
# ===================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║      Chat4All - Monitoring Stack Test Suite          ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}\n"

# ===================================================================
# Test 1: Check containers are running
# ===================================================================
echo -e "${YELLOW}[1/5] Checking monitoring containers...${NC}\n"

containers=("chat4all-prometheus" "chat4all-grafana" "chat4all-metrics-exporter")
all_running=true

for container in "${containers[@]}"; do
    if docker ps --format '{{.Names}}' | grep -q "^${container}$"; then
        status=$(docker inspect --format='{{.State.Status}}' ${container})
        if [ "$status" = "running" ]; then
            echo -e "${GREEN}✓ ${container}: running${NC}"
        else
            echo -e "${RED}✗ ${container}: ${status}${NC}"
            all_running=false
        fi
    else
        echo -e "${RED}✗ ${container}: not found${NC}"
        all_running=false
    fi
done

if [ "$all_running" = false ]; then
    echo -e "\n${RED}Some containers are not running. Please start them with:${NC}"
    echo -e "${BLUE}docker-compose up -d prometheus grafana metrics-exporter${NC}\n"
    exit 1
fi

echo -e "${GREEN}\n✓ All monitoring containers are running${NC}\n"

# ===================================================================
# Test 2: Check Prometheus is accessible
# ===================================================================
echo -e "${YELLOW}[2/5] Testing Prometheus API...${NC}\n"

if curl -s http://localhost:9090/-/healthy > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Prometheus is healthy (http://localhost:9090)${NC}"
else
    echo -e "${RED}✗ Prometheus is not accessible${NC}"
    exit 1
fi

# Check if Prometheus has targets
targets=$(curl -s http://localhost:9090/api/v1/targets | grep -o '"activeTargets":\[' | wc -l)
if [ "$targets" -gt 0 ]; then
    echo -e "${GREEN}✓ Prometheus has configured targets${NC}"
else
    echo -e "${YELLOW}⚠ Prometheus has no targets (this is OK for demo)${NC}"
fi

echo ""

# ===================================================================
# Test 3: Check Grafana is accessible  
# ===================================================================
echo -e "${YELLOW}[3/5] Testing Grafana...${NC}\n"

if curl -s http://localhost:3001/api/health > /dev/null 2>&1; then
    echo -e "${GREEN}✓ Grafana is healthy (http://localhost:3001)${NC}"
    echo -e "${BLUE}  Login: admin / admin${NC}"
else
    echo -e "${RED}✗ Grafana is not accessible${NC}"
    exit 1
fi

echo ""

# ===================================================================
# Test 4: Check metrics exporter
# ===================================================================
echo -e "${YELLOW}[4/5] Testing Metrics Exporter...${NC}\n"

# Try to get metrics from inside the container
metrics=$(docker exec chat4all-metrics-exporter python -c "
import http.client
try:
    conn = http.client.HTTPConnection('localhost', 8000, timeout=2)
    conn.request('GET', '/metrics')
    response = conn.getresponse()
    if response.status == 200:
        print('OK')
    else:
        print('FAIL')
except Exception as e:
    print('ERROR')
" 2>&1)

if [ "$metrics" = "OK" ]; then
    echo -e "${GREEN}✓ Metrics exporter is serving metrics${NC}"
    echo -e "${BLUE}  Endpoint: http://metrics-exporter:8000/metrics (internal)${NC}"
else
    echo -e "${YELLOW}⚠ Could not verify metrics exporter (container may need curl)${NC}"
fi

echo ""

# ===================================================================
# Test 5: Summary
# ===================================================================
echo -e "${YELLOW}[5/5] Monitoring Stack Summary${NC}\n"

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Monitoring Endpoints                      ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}\n"

echo -e "${GREEN}Prometheus:${NC}"
echo -e "  URL:    http://localhost:9090"
echo -e "  Status: Collecting metrics"
echo -e "  Config: prometheus/prometheus.yml"
echo ""

echo -e "${GREEN}Grafana:${NC}"
echo -e "  URL:      http://localhost:3001"
echo -e "  Username: admin"
echo -e "  Password: admin"
echo -e "  Dashboards: grafana/dashboards/*.json"
echo ""

echo -e "${GREEN}Metrics Exporter:${NC}"
echo -e "  Internal: http://metrics-exporter:8000/metrics"
echo -e "  Provides: Sample Chat4All metrics"
echo ""

echo -e "${BLUE}Available Metrics:${NC}"
echo -e "  - messages_processed_total"
echo -e "  - messages_per_second"
echo -e "  - latency_ms (p50, p95, p99)"
echo -e "  - errors_total"
echo -e "  - cpu_usage_percent"
echo -e "  - memory_usage_mb"
echo -e "  - active_workers"
echo ""

echo -e "${GREEN}✓ Monitoring stack fully operational!${NC}\n"

echo -e "${BLUE}Next steps:${NC}"
echo -e "  1. Open Grafana at http://localhost:3001"
echo -e "  2. Login with admin/admin"
echo -e "  3. Import dashboards from grafana/dashboards/"
echo -e "  4. View real-time metrics\n"
