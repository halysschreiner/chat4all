#!/bin/bash

##############################################################################
# Chat4All - Load Test Runner Script
# 
# CONCEITO DE SISTEMAS DISTRIBUÍDOS:
# Este script executa testes de carga automatizados para validar:
# 1. Throughput do sistema sob diferentes cargas
# 2. Latência e tempos de resposta
# 3. Taxa de erros e disponibilidade
# 4. Comportamento do sistema sob stress
#
# Referência: Trabalho Final - Escalabilidade e Relatório (UFG)
##############################################################################

set -e

# Cores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configurações padrão
API_BASE_URL="${API_BASE_URL:-http://localhost:8080}"
WS_URL="${WS_URL:-ws://localhost:8081}"
RESULTS_DIR="${RESULTS_DIR:-./finalTest/results}"
SCRIPTS_DIR="${SCRIPTS_DIR:-./finalTest/scripts}"

# Configurações de teste
TEST_PROFILE="${TEST_PROFILE:-standard}"
K6_VUS="${K6_VUS:-50}"
K6_DURATION="${K6_DURATION:-5m}"

##############################################################################
# Funções Utilitárias
##############################################################################

log_info() {
    echo -e "${BLUE}[INFO]${NC} $1"
}

log_success() {
    echo -e "${GREEN}[SUCCESS]${NC} $1"
}

log_warning() {
    echo -e "${YELLOW}[WARNING]${NC} $1"
}

log_error() {
    echo -e "${RED}[ERROR]${NC} $1"
}

check_dependencies() {
    log_info "Checking dependencies..."
    
    local missing_deps=()
    
    if ! command -v k6 &> /dev/null; then
        missing_deps+=("k6")
    fi
    
    if ! command -v curl &> /dev/null; then
        missing_deps+=("curl")
    fi
    
    if ! command -v jq &> /dev/null; then
        missing_deps+=("jq")
    fi
    
    if [ ${#missing_deps[@]} -ne 0 ]; then
        log_error "Missing dependencies: ${missing_deps[*]}"
        echo ""
        echo "Installation instructions:"
        echo "  k6:   brew install k6  (macOS) or snap install k6 (Linux)"
        echo "  jq:   brew install jq  (macOS) or apt install jq (Linux)"
        echo "  curl: Usually pre-installed on most systems"
        echo ""
        exit 1
    fi
    
    log_success "All dependencies available"
}

wait_for_api() {
    log_info "Waiting for API to be ready at $API_BASE_URL..."
    
    local max_attempts=30
    local attempt=0
    
    while [ $attempt -lt $max_attempts ]; do
        if curl -s "$API_BASE_URL/health" > /dev/null 2>&1; then
            log_success "API is ready"
            return 0
        fi
        attempt=$((attempt + 1))
        echo -n "."
        sleep 1
    done
    
    echo ""
    log_error "API not ready after $max_attempts seconds"
    exit 1
}

create_results_dir() {
    mkdir -p "$RESULTS_DIR"
    log_info "Results will be saved to: $RESULTS_DIR"
}

##############################################################################
# Test Profiles
##############################################################################

run_smoke_test() {
    log_info "Running SMOKE test (quick validation)..."
    
    k6 run \
        --env API_BASE_URL="$API_BASE_URL" \
        --env WS_URL="$WS_URL" \
        --vus 1 \
        --duration 30s \
        --out json="$RESULTS_DIR/smoke_test_$(date +%Y%m%d_%H%M%S).json" \
        "$SCRIPTS_DIR/k6-load-test.js"
}

run_standard_test() {
    log_info "Running STANDARD load test..."
    
    k6 run \
        --env API_BASE_URL="$API_BASE_URL" \
        --env WS_URL="$WS_URL" \
        --out json="$RESULTS_DIR/standard_test_$(date +%Y%m%d_%H%M%S).json" \
        "$SCRIPTS_DIR/k6-load-test.js"
}

run_stress_test() {
    log_info "Running STRESS test (high load)..."
    
    k6 run \
        --env API_BASE_URL="$API_BASE_URL" \
        --env WS_URL="$WS_URL" \
        --vus 200 \
        --duration 10m \
        --out json="$RESULTS_DIR/stress_test_$(date +%Y%m%d_%H%M%S).json" \
        "$SCRIPTS_DIR/k6-load-test.js" \
        --config - <<EOF
{
    "scenarios": {
        "stress": {
            "executor": "ramping-vus",
            "startVUs": 0,
            "stages": [
                { "duration": "2m", "target": 100 },
                { "duration": "3m", "target": 200 },
                { "duration": "2m", "target": 300 },
                { "duration": "2m", "target": 200 },
                { "duration": "1m", "target": 0 }
            ]
        }
    }
}
EOF
}

run_spike_test() {
    log_info "Running SPIKE test (sudden load increase)..."
    
    k6 run \
        --env API_BASE_URL="$API_BASE_URL" \
        --env WS_URL="$WS_URL" \
        --out json="$RESULTS_DIR/spike_test_$(date +%Y%m%d_%H%M%S).json" \
        "$SCRIPTS_DIR/k6-load-test.js" \
        --config - <<EOF
{
    "scenarios": {
        "spike": {
            "executor": "ramping-vus",
            "startVUs": 0,
            "stages": [
                { "duration": "30s", "target": 10 },
                { "duration": "10s", "target": 500 },
                { "duration": "1m", "target": 500 },
                { "duration": "10s", "target": 10 },
                { "duration": "30s", "target": 0 }
            ]
        }
    }
}
EOF
}

run_soak_test() {
    log_info "Running SOAK test (extended duration)..."
    
    k6 run \
        --env API_BASE_URL="$API_BASE_URL" \
        --env WS_URL="$WS_URL" \
        --vus 50 \
        --duration 30m \
        --out json="$RESULTS_DIR/soak_test_$(date +%Y%m%d_%H%M%S).json" \
        "$SCRIPTS_DIR/k6-load-test.js"
}

run_custom_test() {
    log_info "Running CUSTOM test (VUs: $K6_VUS, Duration: $K6_DURATION)..."
    
    k6 run \
        --env API_BASE_URL="$API_BASE_URL" \
        --env WS_URL="$WS_URL" \
        --vus "$K6_VUS" \
        --duration "$K6_DURATION" \
        --out json="$RESULTS_DIR/custom_test_$(date +%Y%m%d_%H%M%S).json" \
        "$SCRIPTS_DIR/k6-load-test.js"
}

##############################################################################
# Analysis Functions
##############################################################################

analyze_results() {
    local result_file="$1"
    
    if [ ! -f "$result_file" ]; then
        log_warning "Result file not found: $result_file"
        return
    fi
    
    log_info "Analyzing results from: $result_file"
    
    echo ""
    echo "============================================================"
    echo "                    RESULT ANALYSIS"
    echo "============================================================"
    echo ""
    
    # Extract key metrics using jq
    if command -v jq &> /dev/null; then
        echo "HTTP Requests:"
        jq -r 'select(.metric == "http_reqs") | "  Total: \(.data.value)"' "$result_file" | head -1
        
        echo ""
        echo "Response Times (ms):"
        jq -r 'select(.metric == "http_req_duration") | select(.type == "Point") | .data.value' "$result_file" | \
            awk '{sum+=$1; count++; if(min==""){min=$1}; if($1<min){min=$1}; if($1>max){max=$1}} END {if(count>0){printf "  Avg: %.2f\n  Min: %.2f\n  Max: %.2f\n", sum/count, min, max}}'
        
        echo ""
        echo "Message Metrics:"
        jq -r 'select(.metric == "messages_sent") | "  Messages Sent: \(.data.value)"' "$result_file" | tail -1
        jq -r 'select(.metric == "messages_success") | "  Messages Success: \(.data.value)"' "$result_file" | tail -1
        jq -r 'select(.metric == "messages_failed") | "  Messages Failed: \(.data.value)"' "$result_file" | tail -1
    fi
    
    echo ""
}

generate_summary_report() {
    local timestamp=$(date +%Y%m%d_%H%M%S)
    local report_file="$RESULTS_DIR/summary_report_$timestamp.md"
    
    log_info "Generating summary report..."
    
    cat > "$report_file" << EOF
# Chat4All Load Test Summary Report

**Generated:** $(date -Iseconds)
**API URL:** $API_BASE_URL
**Test Profile:** $TEST_PROFILE

## Test Results

### Files Generated
EOF

    ls -la "$RESULTS_DIR"/*.json 2>/dev/null | while read line; do
        echo "- \`$(basename $(echo $line | awk '{print $9}'))\`" >> "$report_file"
    done

    cat >> "$report_file" << EOF

## Test Configuration

| Parameter | Value |
|-----------|-------|
| API Base URL | $API_BASE_URL |
| WebSocket URL | $WS_URL |
| Test Profile | $TEST_PROFILE |
| Virtual Users | $K6_VUS |
| Duration | $K6_DURATION |

## Recommendations

Based on the test results, consider:

1. **Scaling**: If response times are high under load, scale horizontally
2. **Caching**: Add caching for frequently accessed data
3. **Database**: Optimize queries and add indexes
4. **Kafka**: Increase partitions for better parallelism

## References

- [Chat4All Scaling Guide](../docs/SCALING.md)
- [Fault Tolerance Documentation](../docs/FAULT_TOLERANCE.md)
- [API Documentation](../docs/API_DOCUMENTATION.md)
EOF

    log_success "Report generated: $report_file"
}

##############################################################################
# Main Execution
##############################################################################

show_help() {
    echo ""
    echo "Chat4All Load Test Runner"
    echo ""
    echo "Usage: $0 [OPTIONS] [PROFILE]"
    echo ""
    echo "Profiles:"
    echo "  smoke     Quick validation test (30s, 1 VU)"
    echo "  standard  Standard load test (uses k6-load-test.js config)"
    echo "  stress    High load stress test (10m, up to 300 VUs)"
    echo "  spike     Sudden load spike test (500 VUs spike)"
    echo "  soak      Extended duration test (30m, 50 VUs)"
    echo "  custom    Custom test (use K6_VUS and K6_DURATION env vars)"
    echo ""
    echo "Options:"
    echo "  --api-url URL      API base URL (default: http://localhost:8080)"
    echo "  --ws-url URL       WebSocket URL (default: ws://localhost:8081)"
    echo "  --vus N            Number of virtual users for custom test"
    echo "  --duration TIME    Duration for custom test (e.g., 5m, 1h)"
    echo "  --help             Show this help message"
    echo ""
    echo "Environment Variables:"
    echo "  API_BASE_URL       API base URL"
    echo "  WS_URL             WebSocket URL"
    echo "  K6_VUS             Virtual users for custom test"
    echo "  K6_DURATION        Duration for custom test"
    echo ""
    echo "Examples:"
    echo "  $0 smoke                    # Run smoke test"
    echo "  $0 standard                 # Run standard load test"
    echo "  $0 --vus 100 custom         # Run custom test with 100 VUs"
    echo "  API_BASE_URL=http://api:8080 $0 stress  # Run stress test"
    echo ""
}

main() {
    echo ""
    echo "╔════════════════════════════════════════════════════════════╗"
    echo "║           Chat4All - Load Test Runner                      ║"
    echo "║                                                            ║"
    echo "║  CONCEITO: Teste de Carga em Sistemas Distribuídos         ║"
    echo "║  - Validação de throughput e latência                      ║"
    echo "║  - Identificação de gargalos                               ║"
    echo "║  - Planejamento de capacidade                              ║"
    echo "╚════════════════════════════════════════════════════════════╝"
    echo ""
    
    # Parse arguments
    while [[ $# -gt 0 ]]; do
        case $1 in
            --api-url)
                API_BASE_URL="$2"
                shift 2
                ;;
            --ws-url)
                WS_URL="$2"
                shift 2
                ;;
            --vus)
                K6_VUS="$2"
                shift 2
                ;;
            --duration)
                K6_DURATION="$2"
                shift 2
                ;;
            --help)
                show_help
                exit 0
                ;;
            smoke|standard|stress|spike|soak|custom)
                TEST_PROFILE="$1"
                shift
                ;;
            *)
                log_error "Unknown option: $1"
                show_help
                exit 1
                ;;
        esac
    done
    
    # Initialization
    check_dependencies
    create_results_dir
    wait_for_api
    
    log_info "Running test profile: $TEST_PROFILE"
    log_info "API URL: $API_BASE_URL"
    log_info "WebSocket URL: $WS_URL"
    echo ""
    
    # Run selected test
    local start_time=$(date +%s)
    
    case $TEST_PROFILE in
        smoke)
            run_smoke_test
            ;;
        standard)
            run_standard_test
            ;;
        stress)
            run_stress_test
            ;;
        spike)
            run_spike_test
            ;;
        soak)
            run_soak_test
            ;;
        custom)
            run_custom_test
            ;;
        *)
            log_error "Unknown test profile: $TEST_PROFILE"
            show_help
            exit 1
            ;;
    esac
    
    local end_time=$(date +%s)
    local duration=$((end_time - start_time))
    
    echo ""
    log_success "Test completed in ${duration} seconds"
    
    # Generate summary
    generate_summary_report
    
    echo ""
    echo "============================================================"
    echo "                    TEST COMPLETE"
    echo "============================================================"
    echo ""
    echo "Results saved to: $RESULTS_DIR"
    echo ""
    echo "Next steps:"
    echo "  1. Review the generated reports"
    echo "  2. Check Grafana dashboards for real-time metrics"
    echo "  3. Analyze bottlenecks and optimize"
    echo ""
}

# Run main function
main "$@"
