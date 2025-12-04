# RNF10 - Containerização

---

## 1. Resumo do Requisito

> - Todos os serviços executam em containers Docker.
> - Docker Compose para orquestração de múltiplos containers.
> - Health checks configurados para cada serviço.
> - Inicialização automática de todos os serviços com script (`docker-compose up`).

### Importância Teórica

Containerização é **fundamental** para sistemas distribuídos modernos. Garante isolamento, reprodutibilidade e portabilidade - os três pilares de DevOps. Sem containers, o famoso "funciona na minha máquina" seria realidade.

---

## 2. Fundamentos Teóricos

### 2.1 VMs vs Containers

```
┌─────────────────────────────────────────────────────────────┐
│                VMs vs CONTAINERS                            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  VIRTUAL MACHINES             CONTAINERS (Docker)           │
│  ┌────────────────┐           ┌────────────────┐           │
│  │    App 1       │           │    App 1       │           │
│  ├────────────────┤           ├────────────────┤           │
│  │  Guest OS      │           │   Bins/Libs    │           │
│  ├────────────────┤           └────────┬───────┘           │
│  │  Hypervisor    │                    │                   │
│  ├────────────────┤           ┌────────┴───────┐           │
│  │    Host OS     │           │  Docker Engine │           │
│  ├────────────────┤           ├────────────────┤           │
│  │   Hardware     │           │    Host OS     │           │
│  └────────────────┘           ├────────────────┤           │
│                               │   Hardware     │           │
│  ⚠️ Overhead de GB            └────────────────┘           │
│  ⚠️ Boot lento (minutos)      ✅ Overhead de MB            │
│                               ✅ Boot rápido (segundos)    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Conceitos-Chave

- **Image**: Template imutável com filesystem e dependências
- **Container**: Instância em execução de uma image
- **Dockerfile**: Receita para construir uma image
- **Docker Compose**: Orquestrador para múltiplos containers
- **Health Check**: Verificação de saúde do serviço

---

## 3. Implementação no Chat4All

### 3.1 Docker Compose Completo (`docker-compose.yml`)

**Estrutura de serviços (328 linhas)**:
```yaml
services:
  # === INFRAESTRUTURA ===
  postgres:
    image: postgres:16-alpine
    container_name: chat4all-postgres
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U chat4all_user"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    container_name: chat4all-redis
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s

  kafka:
    image: confluentinc/cp-kafka:7.5.0
    depends_on:
      zookeeper:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "kafka-topics", "--bootstrap-server", "localhost:9092", "--list"]

  minio:
    image: minio/minio:latest
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"]

  # === APLICAÇÃO ===
  api-gateway:
    build: ./api-gateway
    depends_on:
      - api-service

  api-service:
    build: ./services/api-service
    depends_on:
      postgres:
        condition: service_healthy
      kafka:
        condition: service_started

  router-worker:
    build: ./workers/router-worker
    # container_name comentado para scaling

  websocket-worker:
    build: ./workers/websocket-worker
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "nc", "-z", "localhost", "8081"]

  # === CONNECTORS ===
  whatsapp-connector:
    build: ./connectors/whatsapp-mock

  instagram-connector:
    build: ./connectors/instagram-mock

  # === FRONTEND ===
  web:
    build: ./frontend
    depends_on:
      - api-gateway

  # === MONITORAMENTO ===
  prometheus:
    image: prom/prometheus:latest

  grafana:
    image: grafana/grafana:latest

networks:
  chat4all-network:
    driver: bridge

volumes:
  postgres_data:
  redis_data:
  minio_data:
  prometheus-data:
  grafana-data:
```

### 3.2 Dockerfile Multi-Stage (Frontend)

```dockerfile
# Stage 1: Build the Angular app
FROM node:20-alpine as build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install
COPY frontend/ .
RUN npm run build -- --configuration production

# Stage 2: Serve with Nginx
FROM nginx:alpine
COPY --from=build /app/dist/chat4all-frontend /usr/share/nginx/html

# SPA routing
RUN echo 'server { \
    listen 80; \
    root /usr/share/nginx/html; \
    location / { \
        try_files $uri $uri/ /index.html; \
    } \
}' > /etc/nginx/conf.d/default.conf

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
```

### 3.3 Scripts de Inicialização

**start.sh**:
```bash
#!/bin/bash
echo "🚀 Iniciando Chat4All..."

# Build e start
docker-compose up -d --build

# Aguardar health checks
echo "⏳ Aguardando serviços ficarem healthy..."
sleep 30

# Verificar status
docker-compose ps

echo "✅ Chat4All iniciado com sucesso!"
echo "📱 Frontend: http://localhost:4200"
echo "🔌 API: http://localhost:8000"
echo "📊 Grafana: http://localhost:3001"
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| Todos serviços em Docker | ✅ | Dockerfiles em cada serviço |
| Docker Compose | ✅ | `docker-compose.yml` 328 linhas |
| Health checks | ✅ | `healthcheck:` em cada serviço crítico |
| Inicialização automática | ✅ | `scripts/start.sh` |

### 4.2 Pontos Fortes

1. **Multi-stage builds**: Frontend compila em Node, serve com Nginx mínimo (~20MB vs ~1GB)
2. **Health checks completos**: Todos serviços críticos monitorados
3. **Alpine images**: Imagens mínimas reduzem superfície de ataque
4. **Volumes nomeados**: Persistência de dados entre restarts

### 4.3 Limitações Identificadas

#### Limitação 1: Sem Orquestrador de Produção

**Problema**: Docker Compose não é adequado para produção em escala.

```yaml
# docker-compose.yml - Sem rolling updates, sem auto-healing avançado
```

**Solução**: Kubernetes ou Docker Swarm para produção.

#### Limitação 2: Secrets em Variáveis de Ambiente

**Problema**: Senhas visíveis no docker-compose.yml.

```yaml
environment:
  - POSTGRES_PASSWORD=secret  # Visível em plain text!
```

**Solução**: Docker secrets ou vault externo.

### 4.4 Perguntas Socráticas para Aprofundamento

1. "O que acontece se o health check falhar 5 vezes consecutivas?"
2. "Como você faria rolling update sem downtime?"
3. "Qual a diferença entre `depends_on` e `condition: service_healthy`?"
4. "Por que usar Alpine? Qual o trade-off?"

---

## 5. Referências Teóricas

- **Docker Documentation** - *Best practices for writing Dockerfiles*
- **12-Factor App** - Heroku (Configuration, Dependencies)
- **The Phoenix Project** - DevOps practices
