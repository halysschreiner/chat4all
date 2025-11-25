#!/bin/bash

# Cores para output
GREEN='\033[0;32m'
BLUE='\033[0;34m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

echo -e "${BLUE}========================================${NC}"
echo -e "${BLUE}  Chat4All - Teste de Conectores Mock${NC}"
echo -e "${BLUE}========================================${NC}"
echo

# Verificar se os serviços estão rodando
echo -e "${YELLOW}📋 Verificando conectores...${NC}"
echo

# WhatsApp Health Check
echo -n "🟢 WhatsApp Connector: "
WHATSAPP_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8081/health)
if [ "$WHATSAPP_STATUS" == "200" ]; then
    echo -e "${GREEN}✅ Online${NC}"
else
    echo -e "${RED}❌ Offline (HTTP $WHATSAPP_STATUS)${NC}"
fi

# Instagram Health Check
echo -n "🟣 Instagram Connector: "
INSTAGRAM_STATUS=$(curl -s -o /dev/null -w "%{http_code}" http://localhost:8082/health)
if [ "$INSTAGRAM_STATUS" == "200" ]; then
    echo -e "${GREEN}✅ Online${NC}"
else
    echo -e "${RED}❌ Offline (HTTP $INSTAGRAM_STATUS)${NC}"
fi

echo
echo -e "${BLUE}========================================${NC}"
echo -e "${YELLOW}🧪 Testando WhatsApp Connector${NC}"
echo -e "${BLUE}========================================${NC}"
echo

echo -e "${YELLOW}📤 Enviando mensagem de teste...${NC}"
WHATSAPP_RESPONSE=$(curl -s -X POST http://localhost:8081/send \
  -H "Content-Type: application/json" \
  -d '{
    "to": "+5511999999999",
    "text": "Olá! Esta é uma mensagem de teste do WhatsApp Mock."
  }')

echo -e "${GREEN}Resposta:${NC}"
echo "$WHATSAPP_RESPONSE" | jq .

echo
echo -e "${YELLOW}📥 Simulando recebimento de mensagem...${NC}"
WHATSAPP_WEBHOOK=$(curl -s -X POST http://localhost:8081/webhook/incoming \
  -H "Content-Type: application/json" \
  -d '{
    "from": "+5511888888888",
    "text": "Olá, preciso de ajuda com meu pedido!"
  }')

echo -e "${GREEN}Resposta:${NC}"
echo "$WHATSAPP_WEBHOOK" | jq .

echo
echo -e "${BLUE}========================================${NC}"
echo -e "${YELLOW}🧪 Testando Instagram Connector${NC}"
echo -e "${BLUE}========================================${NC}"
echo

echo -e "${YELLOW}📤 Enviando mensagem de teste...${NC}"
INSTAGRAM_RESPONSE=$(curl -s -X POST http://localhost:8082/send \
  -H "Content-Type: application/json" \
  -d '{
    "to": "@usuario_teste",
    "text": "Olá! Esta é uma mensagem de teste do Instagram Mock."
  }')

echo -e "${GREEN}Resposta:${NC}"
echo "$INSTAGRAM_RESPONSE" | jq .

echo
echo -e "${YELLOW}📥 Simulando recebimento de mensagem...${NC}"
INSTAGRAM_WEBHOOK=$(curl -s -X POST http://localhost:8082/webhook/incoming \
  -H "Content-Type: application/json" \
  -d '{
    "from": "@cliente_instagram",
    "text": "Quero saber mais sobre os produtos!"
  }')

echo -e "${GREEN}Resposta:${NC}"
echo "$INSTAGRAM_WEBHOOK" | jq .

echo
echo -e "${BLUE}========================================${NC}"
echo -e "${GREEN}✅ Testes concluídos!${NC}"
echo -e "${BLUE}========================================${NC}"
echo
echo -e "${YELLOW}💡 Dica:${NC} Acompanhe os logs em tempo real com:"
echo -e "   docker-compose logs -f connector-whatsapp"
echo -e "   docker-compose logs -f connector-instagram"
echo
