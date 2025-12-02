# Quickstart: Trabalho Final - Escalabilidade e Relatório

**Feature**: 001-final-scalability-report  
**Date**: 2025-11-29

## Pré-requisitos

- Docker 24+ e Docker Compose v2
- 8GB RAM disponível (recomendado para todos os serviços)
- Portas disponíveis: 3001, 4200, 5432, 6379, 8000, 8080, 8081, 9001, 9002, 9090, 9092

## Quick Start (5 minutos)

### 1. Iniciar todos os serviços

```bash
cd /home/halys/projects/ufg/sd/chat4all
docker-compose up -d
```

### 2. Verificar se tudo está rodando

```bash
docker-compose ps
```

Todos os serviços devem estar `healthy` ou `running`.

### 3. Acessar as interfaces

| Serviço | URL | Credenciais |
|---------|-----|-------------|
| Frontend Angular | http://localhost:4200 | (criar conta) |
| API Gateway | http://localhost:8000 | (via API) |
| WebSocket | ws://localhost:8081/ws | (via token) |
| Grafana | http://localhost:3001 | admin / admin |
| MinIO Console | http://localhost:9002 | chat4all_admin / chat4all_minio_pass |
| Prometheus | http://localhost:9090 | (sem auth) |

## Demonstração Passo a Passo

### Demo 1: Envio de Mensagem com Status em Tempo Real

```bash
# Terminal 1: Registrar usuário e obter token
curl -X POST http://localhost:8000/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"username": "demo_user", "email": "demo@test.com", "password": "demo123"}'

# Copie o token retornado
export TOKEN="seu_token_aqui"

# Terminal 2: Conectar WebSocket (use websocat ou similar)
websocat "ws://localhost:8081/ws?token=$TOKEN"

# Terminal 1: Criar conversa
curl -X POST http://localhost:8000/v1/conversations \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"type": "private", "memberIds": []}'

# Terminal 1: Enviar mensagem
curl -X POST http://localhost:8000/v1/messages \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "conversationId": "CONVERSATION_ID",
    "content": "Hello World!",
    "type": "text"
  }'

# Observe no Terminal 2: 
# - status_update: SENT → DELIVERED (após 1-3s)
# - status_update: DELIVERED → READ (após 3-8s)
```

### Demo 2: Upload de Arquivo Grande

```bash
# Criar arquivo de teste de 100MB
dd if=/dev/zero of=test_file.bin bs=1M count=100

# Iniciar upload multipart
curl -X POST http://localhost:8000/v1/files/upload/initiate \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "filename": "test_file.bin",
    "mimeType": "application/octet-stream",
    "size": 104857600
  }'

# Copie uploadId e faça upload das partes
# (script completo em finalTest/scripts/test-file-upload.sh)
```

### Demo 3: Escalabilidade Horizontal

```bash
# Verificar throughput com 1 worker
docker-compose logs -f router-worker | grep "processed"

# Escalar para 3 workers
docker-compose up -d --scale router-worker=3

# Verificar distribuição de carga
docker-compose logs -f router-worker

# Executar teste de carga
cd finalTest/scripts
./run-k6-test.sh
```

### Demo 4: Tolerância a Falhas

```bash
# Iniciar teste de carga em background
k6 run -d 60s finalTest/scripts/k6-load-test.js &

# Após 20s, derrubar um worker
sleep 20
docker stop $(docker ps -q -f name=router-worker | head -1)

# Verificar que mensagens continuam sendo processadas
docker-compose logs -f router-worker

# Reiniciar worker derrubado
docker-compose up -d --scale router-worker=3
```

### Demo 5: Monitoramento

1. Acesse Grafana: http://localhost:3001
2. Faça login com admin/admin
3. Navegue para Dashboard > Chat4All
4. Execute testes de carga e observe métricas em tempo real:
   - messages_processed_total
   - latency_ms
   - websocket_active_connections

## Comandos Úteis

```bash
# Ver logs de todos os serviços
docker-compose logs -f

# Ver logs de um serviço específico
docker-compose logs -f router-worker

# Reiniciar um serviço
docker-compose restart api-service

# Escalar workers
docker-compose up -d --scale router-worker=3 --scale whatsapp-connector=2

# Parar tudo
docker-compose down

# Parar e limpar volumes (reset completo)
docker-compose down -v
```

## Verificação de Saúde

```bash
# Health check da API
curl http://localhost:8000/health

# Verificar Kafka topics
docker exec chat4all-kafka kafka-topics --bootstrap-server localhost:9092 --list

# Verificar conexões Redis
docker exec chat4all-redis redis-cli info clients

# Verificar MinIO buckets
docker exec chat4all-minio mc ls local/
```

## Troubleshooting

### Problema: Serviço não inicia

```bash
# Verificar logs do serviço
docker-compose logs SERVICE_NAME

# Verificar se dependências estão prontas
docker-compose ps
```

### Problema: Kafka não conecta

```bash
# Aguardar Kafka estar pronto (pode levar 30-60s)
docker-compose logs kafka | grep "started"

# Verificar conexão
docker exec chat4all-kafka kafka-broker-api-versions --bootstrap-server localhost:9092
```

### Problema: Upload falha

```bash
# Verificar MinIO
curl http://localhost:9001/minio/health/live

# Verificar bucket existe
docker exec chat4all-minio mc ls local/chat4all-files
```

### Problema: WebSocket não conecta

```bash
# Verificar se websocket-worker está rodando
docker-compose logs websocket-worker

# Testar conexão
websocat "ws://localhost:8081/ws?token=TEST"
```

## Estrutura de Arquivos Importantes

```
chat4all/
├── docker-compose.yml          # Configuração de todos os serviços
├── services/api-service/       # Backend PHP principal
├── workers/
│   ├── router-worker/          # Processador de mensagens
│   └── websocket-worker/       # Servidor WebSocket
├── connectors/
│   ├── whatsapp-mock/          # Simulador WhatsApp
│   └── instagram-mock/         # Simulador Instagram
├── frontend/                   # Angular UI
├── grafana/dashboards/         # Dashboards de monitoramento
└── finalTest/scripts/          # Scripts de teste
```
