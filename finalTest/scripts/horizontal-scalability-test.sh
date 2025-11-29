#!/bin/bash

# ===================================================================
# Chat4All - Horizontal Scalability Test Script
# Week 7-8: Load Testing & Scalability Validation
# ===================================================================

set -e

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuration
API_BASE_URL="${API_BASE_URL:-http://localhost:8000}"
RESULTS_DIR="$(pwd)/finalTest/results"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
TEST_REPORT="${RESULTS_DIR}/scalability_test_${TIMESTAMP}.json"

# Test parameters
INITIAL_WORKERS=1
MAX_WORKERS=5
MESSAGES_PER_WORKER=100

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   Chat4All - Horizontal Scalability Test Suite       ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}"
echo ""

# Create results directory
mkdir -p "${RESULTS_DIR}"

# ===================================================================
# Function: Check prerequisites
# ===================================================================
check_prerequisites() {
    echo -e "${YELLOW}[1/6] Checking prerequisites...${NC}"
    
    # Check if docker-compose is available
    if ! command -v docker &> /dev/null; then
        echo -e "${RED}Error: docker is not installed${NC}"
        exit 1
    fi
    
    # Check if jq is available for JSON processing
    if ! command -v jq &> /dev/null; then
        echo -e "${YELLOW}Warning: jq not installed. Installing...${NC}"
        sudo apt-get update && sudo apt-get install -y jq
    fi
    
    # Check if curl is available
    if ! command -v curl &> /dev/null; then
        echo -e "${RED}Error: curl is not installed${NC}"
        exit 1
    fi
    
    echo -e "${GREEN}✓ All prerequisites met${NC}\n"
}

# ===================================================================
# Function: Test API availability
# ===================================================================
test_api_availability() {
    echo -e "${YELLOW}[2/6] Testing API availability...${NC}"
    
    local max_retries=30
    local retry=0
    
    while [ $retry -lt $max_retries ]; do
        if curl -s -f "${API_BASE_URL}/health" > /dev/null 2>&1; then
            echo -e "${GREEN}✓ API is available${NC}\n"
            return 0
        fi
        
        retry=$((retry + 1))
        echo -e "Waiting for API... (${retry}/${max_retries})"
        sleep 2
    done
    
    echo -e "${RED}✗ API is not available after ${max_retries} retries${NC}"
    exit 1
}

# ===================================================================
# Function: Register test users
# ===================================================================
register_test_users() {
    echo -e "${YELLOW}[3/6] Registering test users...${NC}"
    
    for i in $(seq 1 10); do
        local username="scaletest_user${i}"
        local response=$(curl -s -X POST "${API_BASE_URL}/v1/auth/register" \
            -H "Content-Type: application/json" \
            -d "{
                \"username\": \"${username}\",
                \"email\": \"${username}@test.com\",
                \"password\": \"Test123!@#\",
                \"full_name\": \"Scale Test User ${i}\"
            }")
        
        if echo "$response" | jq -e '.user_id' > /dev/null 2>&1; then
            echo -e "${GREEN}✓ Registered user: ${username}${NC}"
        else
            echo -e "${YELLOW}⚠ User ${username} may already exist${NC}"
        fi
    done
    
    echo -e "${GREEN}✓ Test users ready${NC}\n"
}

# ===================================================================
# Function: Scale workers
# ===================================================================
scale_workers() {
    local count=$1
    echo -e "${BLUE}Scaling router-worker to ${count} instances...${NC}"
    
    docker-compose up -d --scale router-worker=${count}
    sleep 5
    
    # Verify scaling
    local actual_count=$(docker ps --filter "name=chat4all-router-worker" --format "{{.Names}}" | wc -l)
    echo -e "${GREEN}✓ Running ${actual_count} worker instances${NC}"
}

# ===================================================================
# Function: Run throughput test
# ===================================================================
run_throughput_test() {
    local worker_count=$1
    local total_messages=$((worker_count * MESSAGES_PER_WORKER))
    
    echo -e "${YELLOW}Testing with ${worker_count} workers (${total_messages} messages)...${NC}"
    
    # Get auth token
    local login_response=$(curl -s -X POST "${API_BASE_URL}/v1/auth/login" \
        -H "Content-Type: application/json" \
        -d '{"email": "scaletest_user1@test.com", "password": "Test123!@#"}')
    
    local token=$(echo "$login_response" | jq -r '.token')
    
    if [ "$token" = "null" ] || [ -z "$token" ]; then
        echo -e "${RED}✗ Failed to get authentication token${NC}"
        return 1
    fi
    
    # Create a test conversation
    local conv_response=$(curl -s -X POST "${API_BASE_URL}/v1/conversations" \
        -H "Authorization: Bearer ${token}" \
        -H "Content-Type: application/json" \
        -d '{
            "title": "Scalability Test Conversation",
            "type": "group",
            "participant_ids": [1, 2, 3]
        }')
    
    local conv_id=$(echo "$conv_response" | jq -r '.conversation_id')
    
    # Measure throughput
    local start_time=$(date +%s.%N)
    local success_count=0
    local error_count=0
    
    for i in $(seq 1 $total_messages); do
        local response=$(curl -s -w "\n%{http_code}" -X POST "${API_BASE_URL}/v1/messages" \
            -H "Authorization: Bearer ${token}" \
            -H "Content-Type: application/json" \
            -d "{
                \"conversation_id\": ${conv_id},
                \"content\": \"Load test message #${i} with ${worker_count} workers\",
                \"type\": \"text\"
            }")
        
        local http_code=$(echo "$response" | tail -n1)
        
        if [ "$http_code" = "201" ] || [ "$http_code" = "200" ]; then
            success_count=$((success_count + 1))
        else
            error_count=$((error_count + 1))
        fi
        
        # Show progress every 20 messages
        if [ $((i % 20)) -eq 0 ]; then
            echo -e "${BLUE}Progress: ${i}/${total_messages} messages sent${NC}"
        fi
    done
    
    local end_time=$(date +%s.%N)
    local duration=$(echo "$end_time - $start_time" | bc)
    local throughput=$(echo "scale=2; $success_count / $duration" | bc)
    local avg_latency=$(echo "scale=2; $duration / $total_messages" | bc)
    
    # Store results
    cat >> "${TEST_REPORT}" <<EOF
{
    "timestamp": "$(date -Iseconds)",
    "worker_count": ${worker_count},
    "total_messages": ${total_messages},
    "success_count": ${success_count},
    "error_count": ${error_count},
    "duration_seconds": ${duration},
    "throughput_msg_per_sec": ${throughput},
    "avg_latency_ms": $(echo "${avg_latency} * 1000" | bc)
}
EOF
    
    echo -e "${GREEN}✓ Test completed:${NC}"
    echo -e "  - Messages sent: ${total_messages}"
    echo -e "  - Success: ${success_count}"
    echo -e "  - Errors: ${error_count}"
    echo -e "  - Duration: ${duration}s"
    echo -e "  - Throughput: ${throughput} msg/s"
    echo -e "  - Avg Latency: $(echo "${avg_latency} * 1000" | bc)ms\n"
}

# ===================================================================
# Function: Test worker failure recovery
# ===================================================================
test_worker_failure() {
    echo -e "${YELLOW}[5/6] Testing worker failure and recovery...${NC}"
    
    # Scale to 3 workers
    scale_workers 3
    
    # Get list of worker containers
    local workers=($(docker ps --filter "name=router-worker" --format "{{.Names}}"))
    
    if [ ${#workers[@]} -lt 2 ]; then
        echo -e "${RED}✗ Not enough workers running${NC}"
        return 1
    fi
    
    local target_worker=${workers[1]}
    
    echo -e "${BLUE}Simulating failure by stopping: ${target_worker}${NC}"
    docker stop "${target_worker}"
    
    echo -e "${BLUE}Waiting for load redistribution (10s)...${NC}"
    sleep 10
    
    # Send messages to verify system still works
    echo -e "${BLUE}Sending messages to verify recovery...${NC}"
    run_throughput_test 2
    
    # Restart the failed worker
    echo -e "${BLUE}Restarting failed worker...${NC}"
    docker start "${target_worker}"
    sleep 5
    
    echo -e "${GREEN}✓ Worker failure recovery test completed${NC}\n"
}

# ===================================================================
# Function: Run scalability tests
# ===================================================================
run_scalability_tests() {
    echo -e "${YELLOW}[4/6] Running horizontal scalability tests...${NC}\n"
    
    # Initialize results file
    echo "[" > "${TEST_REPORT}"
    
    for worker_count in $(seq ${INITIAL_WORKERS} ${MAX_WORKERS}); do
        echo -e "${BLUE}═══════════════════════════════════════════${NC}"
        echo -e "${BLUE}  Test with ${worker_count} worker(s)${NC}"
        echo -e "${BLUE}═══════════════════════════════════════════${NC}"
        
        scale_workers ${worker_count}
        run_throughput_test ${worker_count}
        
        # Add comma separator except for last item
        if [ ${worker_count} -lt ${MAX_WORKERS} ]; then
            echo "," >> "${TEST_REPORT}"
        fi
        
        sleep 3
    done
    
    echo "]" >> "${TEST_REPORT}"
    echo -e "${GREEN}✓ All scalability tests completed${NC}\n"
}

# ===================================================================
# Function: Generate summary report
# ===================================================================
generate_summary() {
    echo -e "${YELLOW}[6/6] Generating summary report...${NC}"
    
    echo -e "\n${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║              Test Results Summary                     ║${NC}"
    echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}\n"
    
    if [ -f "${TEST_REPORT}" ]; then
        echo "Results saved to: ${TEST_REPORT}"
        echo ""
        echo "Summary:"
        jq -r '.[] | "Workers: \(.worker_count) | Throughput: \(.throughput_msg_per_sec) msg/s | Latency: \(.avg_latency_ms)ms"' "${TEST_REPORT}"
    fi
    
    echo -e "\n${GREEN}✓ All tests completed successfully!${NC}"
    echo -e "${BLUE}Results directory: ${RESULTS_DIR}${NC}\n"
}

# ===================================================================
# Main execution
# ===================================================================
main() {
    check_prerequisites
    test_api_availability
    register_test_users
    run_scalability_tests
    test_worker_failure
    generate_summary
}

# Run main function
main "$@"
