# Frontend Dockerfile - Build de produção simplificado

# Stage 1: Build
FROM node:18 AS builder

WORKDIR /app

# Copiar package files
COPY package*.json ./

# Instalar dependências
RUN npm install

# Copiar código fonte
COPY . .

# Build da aplicação
RUN npm run build --prod || npm run build

# Stage 2: Servir com Nginx
FROM nginx:alpine

# Copiar build para nginx
COPY --from=builder /app/dist/* /usr/share/nginx/html/

# Configurar nginx
RUN echo 'server { \n\
    listen 80; \n\
    location / { \n\
        root /usr/share/nginx/html; \n\
        index index.html; \n\
        try_files $uri $uri/ /index.html; \n\
    } \n\
}' > /etc/nginx/conf.d/default.conf

EXPOSE 80

CMD ["nginx", "-g", "daemon off;"]