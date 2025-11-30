#!/bin/bash
# ================================================
# Script de Inicialização de Tópicos Kafka
# Chat4All - Sistema de Mensagens Distribuído
# ================================================
# Este script cria os tópicos Kafka necessários
# para o funcionamento do sistema de mensagens.
# ================================================

set -e

echo "=== Inicializando Tópicos Kafka ==="

# Aguardar Kafka estar pronto
echo "Aguardando Kafka estar disponível..."
sleep 10

# Configuração
KAFKA_BROKER=${KAFKA_BROKER:-"kafka:9093"}
REPLICATION_FACTOR=${REPLICATION_FACTOR:-1}
PARTITIONS=${PARTITIONS:-3}

# Lista de tópicos a serem criados
TOPICS=(
    "messages"
    "whatsapp.messages"
    "instagram.messages"
    "status-updates"
    "file-events"
    "delivery-callbacks"
)

# Função para criar tópico
create_topic() {
    local topic=$1
    echo "Criando tópico: $topic"
    
    kafka-topics --bootstrap-server "$KAFKA_BROKER" \
        --create \
        --if-not-exists \
        --topic "$topic" \
        --partitions "$PARTITIONS" \
        --replication-factor "$REPLICATION_FACTOR" \
        2>/dev/null || echo "Tópico $topic já existe ou erro na criação"
}

# Criar todos os tópicos
for topic in "${TOPICS[@]}"; do
    create_topic "$topic"
done

# Listar tópicos criados
echo ""
echo "=== Tópicos Kafka Disponíveis ==="
kafka-topics --bootstrap-server "$KAFKA_BROKER" --list

echo ""
echo "=== Inicialização Concluída ==="
