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

# Copy start script
COPY services/api-service/start.sh /app/start.sh
RUN chmod +x /app/start.sh

# Expose gRPC port and HTTP port
EXPOSE 50051
EXPOSE 8080

# Command to run both servers
CMD ["/app/start.sh"]
