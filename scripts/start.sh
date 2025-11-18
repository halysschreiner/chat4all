#!/bin/bash

#
# Script de inicialização do Chat4All
# Sobe todos os serviços necessários usando Docker Compose
#

set -e

echo "================================================"
echo "  Chat4All - Sistema de Mensagens Distribuído"
echo "  Inicializando serviços..."
echo "================================================"
echo ""

# Verificar se Docker está instalado
if ! command -v docker &> /dev/null; then
    echo "❌ Docker não está instalado. Por favor, instale o Docker primeiro."
    exit 1
fi

# Verificar se Docker Compose está instalado
if ! command -v docker-compose &> /dev/null; then
    echo "❌ Docker Compose não está instalado. Por favor, instale o Docker Compose primeiro."
    exit 1
fi

# Parar containers anteriores se existirem
echo "🧹 Limpando containers anteriores..."
docker-compose down -v 2>/dev/null || true

echo ""
echo "🔧 Construindo imagens Docker..."
docker-compose build

echo ""
echo "🚀 Iniciando serviços..."
docker-compose up -d postgres redis zookeeper kafka

echo ""
echo "⏳ Aguardando inicialização do banco de dados e Kafka..."
sleep 15

# Verificar saúde do PostgreSQL
echo "🔍 Verificando PostgreSQL..."
docker-compose exec -T postgres pg_isready -U chat4all_user -d chat4all

# Verificar saúde do Redis
echo "🔍 Verificando Redis..."
docker-compose exec -T redis redis-cli ping

echo ""
echo "🚀 Iniciando serviços da aplicação..."
docker-compose up -d api-service router-worker web

echo ""
echo "⏳ Aguardando serviços ficarem prontos..."
sleep 10

echo ""
echo "================================================"
echo "  ✅ Chat4All iniciado com sucesso!"
echo "================================================"
echo ""
echo "📋 Serviços disponíveis:"
echo ""
echo "  🌐 Interface Web:     http://localhost:9000"
echo "  🌐 API Service:       http://localhost:8080"
echo "  🗄️  PostgreSQL:        localhost:5432"
echo "  📦 Redis:             localhost:6379"
echo "  📨 Kafka:             localhost:9092"
echo ""
echo "👤 Usuários de teste:"
echo ""
echo "  Email: alice@chat4all.com"
echo "  Senha: password123"
echo ""
echo "  Email: bob@chat4all.com"
echo "  Senha: password123"
echo ""
echo "🔍 Para ver logs:"
echo "  docker-compose logs -f"
echo ""
echo "🛑 Para parar:"
echo "  docker-compose down"
echo ""
echo "================================================"
