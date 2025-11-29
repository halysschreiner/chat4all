#!/bin/bash

# ===================================================================
# Chat4All - k6 Load Test Runner
# Week 7-8: Execute k6 load tests with different configurations
# ===================================================================

set -e

# Colors
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
NC='\033[0m'

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║          Chat4All - k6 Load Test Runner              ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}\n"

# Check if k6 is installed
if ! command -v k6 &> /dev/null; then
    echo -e "${YELLOW}k6 is not installed. Installing...${NC}"
    
    # Detect OS and install k6
    if [[ "$OSTYPE" == "linux-gnu"* ]]; then
        sudo gpg -k
        sudo gpg --no-default-keyring --keyring /usr/share/keyrings/k6-archive-keyring.gpg --keyserver hkp://keyserver.ubuntu.com:80 --recv-keys C5AD17C747E3415A3642D57D77C6C491D6AC1D69
        echo "deb [signed-by=/usr/share/keyrings/k6-archive-keyring.gpg] https://dl.k6.io/deb stable main" | sudo tee /etc/apt/sources.list.d/k6.list
        sudo apt-get update
        sudo apt-get install k6
    elif [[ "$OSTYPE" == "darwin"* ]]; then
        brew install k6
    else
        echo -e "${YELLOW}Please install k6 manually: https://k6.io/docs/getting-started/installation/${NC}"
        exit 1
    fi
fi

echo -e "${GREEN}✓ k6 is available${NC}\n"

# Set API base URL
export API_BASE_URL="${API_BASE_URL:-http://localhost:8000}"

# Create results directory
mkdir -p ../results

echo -e "${YELLOW}Running k6 load test...${NC}"
echo -e "API Base URL: ${API_BASE_URL}\n"

# Run k6 test
k6 run \
    --out json=../results/k6_results_$(date +%Y%m%d_%H%M%S).json \
    --summary-export=../results/k6_summary_$(date +%Y%m%d_%H%M%S).json \
    k6-load-test.js

echo -e "\n${GREEN}✓ k6 load test completed${NC}"
echo -e "${BLUE}Results saved to: finalTest/results/${NC}\n"
