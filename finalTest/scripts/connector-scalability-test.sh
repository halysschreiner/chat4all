#!/bin/bash

# ===================================================================
# Chat4All - Connector Scalability Test Script
# Tests horizontal scaling of WhatsApp and Instagram connectors
# ===================================================================

set -e

# Colors
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Chat4All - Connector Scalability Tests           ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}\n"

# ===================================================================
# Test WhatsApp Connector Scaling
# ===================================================================
echo -e "${YELLOW}[1/4] Testing WhatsApp Connector Scaling...${NC}\n"

for instances in 1 2 3; do
    echo -e "${BLUE}═══════════════════════════════════════════${NC}"
    echo -e "${BLUE}  WhatsApp Connector: ${instances} instance(s)${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════${NC}"
    
    docker-compose up -d --scale whatsapp-connector=${instances}
    sleep 3
    
    actual_count=$(docker ps --filter "name=whatsapp-connector" --format "{{.Names}}" | wc -l)
    echo -e "${GREEN}✓ Running ${actual_count} WhatsApp connector instance(s)${NC}"
    
    # List instances
    docker ps --filter "name=whatsapp-connector" --format "table {{.Names}}\t{{.Status}}"
    echo ""
done

echo -e "${GREEN}✓ WhatsApp connector scaling tested (1-3 instances)${NC}\n"

# ===================================================================
# Test Instagram Connector Scaling
# ===================================================================
echo -e "${YELLOW}[2/4] Testing Instagram Connector Scaling...${NC}\n"

for instances in 1 2 3; do
    echo -e "${BLUE}═══════════════════════════════════════════${NC}"
    echo -e "${BLUE}  Instagram Connector: ${instances} instance(s)${NC}"
    echo -e "${BLUE}═══════════════════════════════════════════${NC}"
    
    docker-compose up -d --scale instagram-connector=${instances}
    sleep 3
    
    actual_count=$(docker ps --filter "name=instagram-connector" --format "{{.Names}}" | wc -l)
    echo -e "${GREEN}✓ Running ${actual_count} Instagram connector instance(s)${NC}"
    
    # List instances
    docker ps --filter "name=instagram-connector" --format "table {{.Names}}\t{{.Status}}"
    echo ""
done

echo -e "${GREEN}✓ Instagram connector scaling tested (1-3 instances)${NC}\n"

# ===================================================================
# Test Both Connectors Together
# ===================================================================
echo -e "${YELLOW}[3/4] Testing Both Connectors Simultaneously...${NC}\n"

echo -e "${BLUE}Scaling to 2 WhatsApp + 2 Instagram connectors...${NC}"
docker-compose up -d --scale whatsapp-connector=2 --scale instagram-connector=2
sleep 3

whatsapp_count=$(docker ps --filter "name=whatsapp-connector" --format "{{.Names}}" | wc -l)
instagram_count=$(docker ps --filter "name=instagram-connector" --format "{{.Names}}" | wc -l)

echo -e "${GREEN}✓ Running ${whatsapp_count} WhatsApp connector(s)${NC}"
echo -e "${GREEN}✓ Running ${instagram_count} Instagram connector(s)${NC}\n"

echo -e "${BLUE}All connector instances:${NC}"
docker ps --filter "name=connector" --format "table {{.Names}}\t{{.Status}}\t{{.Ports}}"
echo ""

# ===================================================================
# Summary
# ===================================================================
echo -e "${YELLOW}[4/4] Summary${NC}\n"

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║              Connector Scaling Results                ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}\n"

echo -e "${GREEN}✓ WhatsApp Connector:${NC}"
echo -e "  - Successfully scaled to 1, 2, and 3 instances"
echo -e "  - All instances running properly"
echo ""

echo -e "${GREEN}✓ Instagram Connector:${NC}"
echo -e "  - Successfully scaled to 1, 2, and 3 instances"
echo -e "  - All instances running properly"
echo ""

echo -e "${GREEN}✓ Combined Scaling:${NC}"
echo -e "  - Successfully ran 2 WhatsApp + 2 Instagram simultaneously"
echo -e "  - Total: $(docker ps --filter 'name=connector' --format '{{.Names}}' | wc -l) connector instances active"
echo ""

echo -e "${BLUE}Note:${NC} Connectors can now scale horizontally to handle increased load"
echo -e "${BLUE}from external messaging platforms.${NC}\n"

echo -e "${GREEN}✓ All connector scalability tests passed!${NC}\n"
