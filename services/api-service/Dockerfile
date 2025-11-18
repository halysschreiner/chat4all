FROM php:8.3-cli-alpine

# Instalar dependências do sistema
RUN apk add --no-cache \
    postgresql-dev \
    librdkafka-dev \
    autoconf \
    g++ \
    make \
    git \
    zip \
    unzip

# Instalar extensões PHP
RUN docker-php-ext-install pdo pdo_pgsql

# Instalar rdkafka
RUN pecl install rdkafka && docker-php-ext-enable rdkafka

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Diretório de trabalho
WORKDIR /app

# Copiar código da aplicação primeiro
COPY . .

# Instalar dependências
RUN composer install --no-dev --optimize-autoloader --no-interaction || true

# Expor porta
EXPOSE 8080

# Comando de inicialização: instalar deps e rodar servidor web com router
CMD composer install --no-dev --optimize-autoloader --no-interaction && \
    php -S 0.0.0.0:8080 -t public/ public/router.php
