FROM php:8.3-cli-alpine

# Instalar dependências do sistema
RUN apk add --no-cache \
    postgresql-dev \
    librdkafka-dev \
    autoconf \
    g++ \
    make \
    git

# Instalar extensões PHP
RUN docker-php-ext-install pdo pdo_pgsql pcntl

# Instalar rdkafka
RUN pecl install rdkafka && docker-php-ext-enable rdkafka

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Diretório de trabalho
WORKDIR /app

# Copiar código da aplicação
COPY . .

# Instalar dependências
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Comando de inicialização: instalar deps e rodar
CMD composer install --no-dev --optimize-autoloader --no-interaction && php consumer.php
