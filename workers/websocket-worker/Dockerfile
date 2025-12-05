# ================================================
# WebSocket Worker Dockerfile
# Chat4All - Sistema de Mensagens Distribuído
# ================================================
# Este worker gerencia conexões WebSocket para
# notificações em tempo real de status de mensagens
# ================================================

FROM php:8.3-cli

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    git \
    unzip \
    librdkafka-dev \
    libzip-dev \
    netcat-openbsd \
    && rm -rf /var/lib/apt/lists/*

# Instalar extensões PHP necessárias
RUN docker-php-ext-install \
    sockets \
    pcntl \
    zip

# Instalar rdkafka via PECL
RUN pecl install rdkafka && docker-php-ext-enable rdkafka

# Instalar Redis via PECL
RUN pecl install redis && docker-php-ext-enable redis

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Definir diretório de trabalho
WORKDIR /app

# Copiar arquivos de configuração do Composer
COPY composer.json ./

# Instalar dependências PHP
RUN composer install --no-dev --optimize-autoloader --no-scripts

# Copiar código fonte
COPY . .

# Expor porta do WebSocket (3333)
EXPOSE 3333

# Comando padrão - iniciar servidor WebSocket
CMD ["php", "server.php"]
