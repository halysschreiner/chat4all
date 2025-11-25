FROM php:8.3-cli

# Instalar extensões necessárias
RUN apt-get update && apt-get install -y \
    librdkafka-dev \
    git \
    unzip \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && rm -rf /var/lib/apt/lists/*

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /app

# Copiar composer files
COPY connectors/whatsapp-mock/composer.json .

# Instalar dependências
RUN composer install --no-dev --optimize-autoloader

# Copiar código fonte
COPY connectors/whatsapp-mock/ .

# Tornar script de inicialização executável
RUN chmod +x /app/start.sh

# Expor porta padrão
EXPOSE 80

CMD ["/app/start.sh"]
