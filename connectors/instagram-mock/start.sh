#!/bin/bash

echo "🟣 Starting Instagram Mock Connector..."

# Porta configurável via variável de ambiente
PORT=${CONNECTOR_PORT:-80}

# Iniciar servidor HTTP em background
php -S 0.0.0.0:$PORT public/index.php &
HTTP_PID=$!
echo "✅ HTTP Server started on port $PORT (PID: $HTTP_PID)"

# Iniciar consumer Kafka
echo "✅ Starting Kafka Consumer..."
php consumer.php

# Se o consumer cair, matar o servidor HTTP também
kill $HTTP_PID
