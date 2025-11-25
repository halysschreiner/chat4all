#!/bin/sh
set -e

echo "========================================"
echo "Starting Chat4All API Service..."
echo "========================================"

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
