FROM php:8.3-cli-alpine

# Install dependencies and extensions using mlocati/php-extension-installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

# Install extensions: pdo, pdo_pgsql, rdkafka, grpc, protobuf
# This uses pre-built binaries when available, avoiding long compilation times
RUN install-php-extensions pdo pdo_pgsql rdkafka grpc protobuf

# Install system dependencies
RUN apk add --no-cache git zip unzip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Workdir
WORKDIR /app

# Copy composer.json
COPY services/api-service/composer.json .

# Copy shared folder (needed for autoloading generated classes)
COPY shared/ /app/shared/

# Install dependencies
# We create src directory to avoid composer warnings about missing mapped paths
RUN mkdir -p src && composer install --no-dev --optimize-autoloader --no-interaction

# Copy application code
COPY services/api-service/ .

# Copy custom php.ini
COPY services/api-service/php.ini /usr/local/etc/php/conf.d/custom.ini

# Create start script inline to avoid Windows line ending issues
# Added auto-restart for gRPC server with error logging
RUN apk add --no-cache netcat-openbsd && \
	printf '#!/bin/sh\nset -e\necho "Starting Chat4All API Service..."\necho "Waiting for Kafka..."\nMAX_WAIT=30\nELAPSED=0\nuntil nc -z kafka 9093 2>/dev/null; do\n  if [ $ELAPSED -ge $MAX_WAIT ]; then\n    echo "Kafka not ready, proceeding anyway"\n    break\n  fi\n  sleep 2\n  ELAPSED=$((ELAPSED + 2))\ndone\n\n# Function to run gRPC server with auto-restart\nrun_grpc() {\n  while true; do\n    echo "[$(date)] Starting gRPC server on port 50051..."\n    php src/server.php 2>&1 | tee -a /tmp/grpc.log\n    echo "[$(date)] gRPC server exited, restarting in 2s..."\n    sleep 2\n  done\n}\n\n# Start gRPC server in background with auto-restart\nrun_grpc &\nGRPC_PID=$!\n\necho "Starting HTTP REST server on port 8080..."\nphp -S 0.0.0.0:8080 -t public public/router.php\n\nwait -n\nexit $?\n' > /app/start.sh && chmod +x /app/start.sh

# Expose gRPC port and HTTP port
EXPOSE 50051
EXPOSE 8080

# Command to run both servers
CMD ["/app/start.sh"]
