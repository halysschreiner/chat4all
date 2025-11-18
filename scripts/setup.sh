#!/bin/bash

# Script de setup do Chat4All v2
# Gera código gRPC e prepara ambiente

set -e

echo "🚀 Chat4All v2 - Setup Script"
echo "================================"

# Cores
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Verificar se protoc está instalado
if ! command -v protoc &> /dev/null; then
    echo -e "${RED}❌ protoc não encontrado!${NC}"
    echo "Instale com:"
    echo "  Ubuntu/Debian: sudo apt-get install -y protobuf-compiler"
    echo "  macOS: brew install protobuf"
    exit 1
fi

echo -e "${GREEN}✓ protoc encontrado${NC}"

# Criar diretórios para código gerado
echo -e "${YELLOW}📁 Criando diretórios...${NC}"
mkdir -p shared/generated/auth
mkdir -p shared/generated/message
mkdir -p shared/generated/conversation

# Gerar código PHP a partir dos arquivos .proto
echo -e "${YELLOW}🔨 Gerando código gRPC...${NC}"

# Auth Service
echo "  - auth.proto"
protoc --proto_path=shared/proto \
       --php_out=shared/generated/auth \
       --grpc_out=shared/generated/auth \
       --plugin=protoc-gen-grpc=$(which grpc_php_plugin) \
       shared/proto/auth.proto

# Message Service
echo "  - message.proto"
protoc --proto_path=shared/proto \
       --php_out=shared/generated/message \
       --grpc_out=shared/generated/message \
       --plugin=protoc-gen-grpc=$(which grpc_php_plugin) \
       shared/proto/message.proto

# Conversation Service
echo "  - conversation.proto"
protoc --proto_path=shared/proto \
       --php_out=shared/generated/conversation \
       --grpc_out=shared/generated/conversation \
       --plugin=protoc-gen-grpc=$(which grpc_php_plugin) \
       shared/proto/conversation.proto

echo -e "${GREEN}✓ Código gRPC gerado com sucesso!${NC}"

# Criar arquivo .env se não existir
if [ ! -f .env ]; then
    echo -e "${YELLOW}📝 Criando arquivo .env...${NC}"
    cat > .env << EOF
# Chat4All v2 - Environment Variables

# PostgreSQL
POSTGRES_DB=chat4all
POSTGRES_USER=chat4all
POSTGRES_PASSWORD=chat4all123

# Redis
REDIS_HOST=redis
REDIS_PORT=6379

# Services
AUTH_SERVICE_HOST=auth-service
AUTH_SERVICE_PORT=50051
MESSAGE_SERVICE_HOST=message-service
MESSAGE_SERVICE_PORT=50052
CONVERSATION_SERVICE_HOST=conversation-service
CONVERSATION_SERVICE_PORT=50053

# API Gateway
API_GATEWAY_PORT=8080

# Frontend
FRONTEND_PORT=4200
EOF
    echo -e "${GREEN}✓ Arquivo .env criado${NC}"
fi

echo ""
echo -e "${GREEN}✅ Setup completo!${NC}"
echo ""
echo "Próximos passos:"
echo "  1. docker-compose up --build"
echo "  2. Acesse http://localhost:4200"
echo ""
echo "Usuários de teste:"
echo "  - alice@chat4all.com (senha: password)"
echo "  - bob@chat4all.com (senha: password)"
echo "  - charlie@chat4all.com (senha: password)"
echo ""