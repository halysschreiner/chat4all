# Dockerfile para servidor web estático
FROM nginx:alpine

# Copiar arquivos HTML
COPY index.html /usr/share/nginx/html/

# Configuração customizada do Nginx para CORS
RUN echo 'server { \
    listen 80; \
    server_name localhost; \
    root /usr/share/nginx/html; \
    index index.html; \
    \
    location / { \
        try_files $uri $uri/ /index.html; \
    } \
}' > /etc/nginx/conf.d/default.conf

EXPOSE 80
