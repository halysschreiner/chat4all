FROM php:8.3-cli-alpine

# Install dependencies and extensions using mlocati/php-extension-installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo pdo_pgsql pcntl rdkafka

# Install system dependencies
RUN apk add --no-cache git zip unzip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Workdir
WORKDIR /app

# Copy composer.json
COPY workers/router-worker/composer.json .

# Install dependencies
RUN mkdir -p src && composer install --no-dev --optimize-autoloader --no-interaction

# Copy application code
COPY workers/router-worker/ .

# Command
CMD ["php", "consumer.php"]
