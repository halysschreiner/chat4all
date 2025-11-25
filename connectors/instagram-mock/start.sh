#!/bin/bash

echo "🟣 Starting Instagram Mock Connector..."

# Iniciar servidor HTTP em background
php -S 0.0.0.0:8082 public/index.php &
HTTP_PID=$!
echo "✅ HTTP Server started on port 8082 (PID: $HTTP_PID)"

# Iniciar consumer Kafka
echo "✅ Starting Kafka Consumer..."
php consumer.php

# Se o consumer cair, matar o servidor HTTP também
kill $HTTP_PID
