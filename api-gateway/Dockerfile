FROM php:8.3-fpm-alpine

# Install dependencies and extensions using mlocati/php-extension-installer
COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo pdo_pgsql redis grpc protobuf

# Install nginx and other deps
RUN apk add --no-cache nginx git zip unzip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Workdir
WORKDIR /var/www/html

# Copy composer.json
COPY api-gateway/composer.json .

# Copy shared folder
COPY shared/ /var/www/html/shared/

# Install dependencies
RUN mkdir -p src && composer install --no-dev --optimize-autoloader --no-interaction

# Copy application code
COPY api-gateway/ .

# Configure Nginx
RUN mkdir -p /run/nginx
RUN echo 'server { \
    listen 80; \
    root /var/www/html/public; \
    index index.php; \
    location / { \
        try_files $uri $uri/ /index.php?$query_string; \
    } \
    location ~ \.php$ { \
        fastcgi_pass 127.0.0.1:9000; \
        fastcgi_index index.php; \
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; \
        include fastcgi_params; \
    } \
}' > /etc/nginx/http.d/default.conf

# Start script
RUN echo '#!/bin/sh' > /start.sh && \
    echo 'php-fpm -D' >> /start.sh && \
    echo 'nginx -g "daemon off;"' >> /start.sh && \
    chmod +x /start.sh

EXPOSE 80

CMD ["/start.sh"]

CMD ["/start.sh"]