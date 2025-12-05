#!/bin/sh
set -e

echo "========================================"
echo "Starting Chat4All API Service..."
echo "========================================"

# Wait for Kafka to be ready
echo "Waiting for Kafka to be ready..."
MAX_WAIT=30
ELAPSED=0
until nc -z kafka 9093 2>/dev/null; do
  if [ $ELAPSED -ge $MAX_WAIT ]; then
    echo "⚠️  Kafka not ready after ${MAX_WAIT}s, proceeding anyway (lazy init will retry)"
    break
  fi
  echo "Kafka is unavailable - sleeping (${ELAPSED}s/${MAX_WAIT}s)"
  sleep 2
  ELAPSED=$((ELAPSED + 2))
done
echo "✓ Kafka is ready"

# Start gRPC server in background
echo "[1/2] Starting gRPC server on port 50051..."
php src/server.php &
GRPC_PID=$!
echo "✓ gRPC server started (PID: $GRPC_PID)"

# Start HTTP server on port 8080
echo "[2/2] Starting HTTP REST server on port 8080..."
echo "✓ CORS headers configured in router.php"
echo "✓ File upload endpoints available at /v1/files/*"
echo "========================================"
php -S 0.0.0.0:8080 -t public public/router.php

# Wait for any process to exit
wait -n

# Exit with status of process that exited first
exit $?
