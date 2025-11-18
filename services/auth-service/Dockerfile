# Auth Service Dockerfile
FROM php:8.4-cli

# Instalar dependências do sistema
RUN apt-get update && apt-get install -y \
    libpq-dev \
    libssl-dev \
    git \
    unzip \
    zlib1g-dev \
    && docker-php-ext-install pdo pdo_pgsql

# Instalar Redis extension
RUN pecl install redis && docker-php-ext-enable redis

# Instalar gRPC extension
RUN pecl install grpc && docker-php-ext-enable grpc

# Instalar Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Diretório de trabalho
WORKDIR /app

# Copiar composer files
COPY composer.json composer.lock ./

# Instalar dependências PHP
RUN composer install --no-dev --optimize-autoloader

# Copiar código fonte
COPY . .

# Gerar código gRPC a partir dos arquivos proto
# Nota: Isso seria feito em um step de build, aqui é simplificado
RUN mkdir -p generated

# Expor porta gRPC
EXPOSE 50051

# Comando para iniciar o servidor
CMD ["php", "src/Server.php"]