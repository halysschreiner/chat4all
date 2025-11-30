# Chat4All - Roteiro de Demonstração

Este documento descreve o roteiro completo para demonstração prática do sistema Chat4All, cobrindo todos os conceitos de Sistemas Distribuídos implementados.

## 📋 Pré-requisitos

### Ambiente
- Docker e Docker Compose instalados
- Mínimo 8GB RAM disponível
- Portas livres: 8080, 8081, 8082, 8083, 9092, 3000, 9090, 5432, 9000

### Verificação
```bash
# Verificar Docker
docker --version
docker-compose --version

# Verificar portas
netstat -tuln | grep -E '8080|8081|8082|9092|3000|9090'
```

## 🚀 Estrutura da Demonstração

A demonstração está dividida em 8 etapas que cobrem progressivamente todos os conceitos do trabalho.

### Tempo Estimado
- **Total**: 15-20 minutos
- **Por etapa**: 1-3 minutos cada

---

## 📍 Etapa 1: Registro de Usuário

### Conceitos Demonstrados
- API REST via API Gateway
- Persistência em PostgreSQL
- Validação de dados

### Ações
```bash
# Registrar novo usuário
curl -X POST http://localhost:8080/api/users \
  -H "Content-Type: application/json" \
  -d '{"name": "Demo User", "email": "demo@chat4all.com", "password": "demo123"}'
```

### Verificação
- ✅ Resposta HTTP 201 Created
- ✅ Usuário persistido no banco
- ✅ Senha armazenada com hash

### Pontos de Discussão
- Arquitetura de microserviços
- Separação de responsabilidades
- Validação centralizada no API Gateway

---

## 📍 Etapa 2: Autenticação JWT

### Conceitos Demonstrados
- Autenticação stateless
- Tokens JWT
- Segurança distribuída

### Ações
```bash
# Autenticar e obter token
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "demo@chat4all.com", "password": "demo123"}'
```

### Verificação
- ✅ Token JWT retornado
- ✅ Token contém claims (user_id, email, exp)
- ✅ Token expira em 24 horas

### Pontos de Discussão
- Autenticação stateless em sistemas distribuídos
- Vantagens do JWT para escalabilidade
- Não necessita compartilhar sessão entre instâncias

---

## 📍 Etapa 3: Criar Conversação

### Conceitos Demonstrados
- Comunicação gRPC
- API Gateway como proxy
- Relacionamentos no banco

### Ações
```bash
# Criar conversação (usar token da etapa anterior)
curl -X POST http://localhost:8080/api/conversations \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"platform": "whatsapp", "external_id": "+5562999999999"}'
```

### Verificação
- ✅ Conversação criada com UUID
- ✅ Associada ao usuário autenticado
- ✅ Plataforma registrada corretamente

### Pontos de Discussão
- gRPC para comunicação entre serviços
- Protocol Buffers para serialização
- Tipagem forte e performance

---

## 📍 Etapa 4: Envio de Mensagem

### Conceitos Demonstrados
- Comunicação assíncrona via Kafka
- WebSocket para tempo real
- Roteamento inteligente

### Ações
```bash
# Enviar mensagem
curl -X POST http://localhost:8080/api/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "conversation_id": "CONV_ID",
    "content": "Hello from Chat4All!",
    "type": "text"
  }'
```

### Verificação
- ✅ Mensagem enviada para Kafka
- ✅ Router-worker consome e roteia
- ✅ Conector mock processa
- ✅ WebSocket notifica em tempo real

### Pontos de Discussão
- Kafka como message broker
- Desacoplamento produtor/consumidor
- Garantia de entrega

### Demonstração Visual
1. Abrir frontend em `http://localhost:4200`
2. Conectar WebSocket
3. Enviar mensagem via API
4. Observar notificação em tempo real

---

## 📍 Etapa 5: Upload de Arquivo

### Conceitos Demonstrados
- Armazenamento distribuído (MinIO/S3)
- Upload multipart
- Metadados em PostgreSQL

### Ações
```bash
# Upload de arquivo
curl -X POST http://localhost:8080/api/files \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@/path/to/test-image.png" \
  -F "conversation_id=CONV_ID"
```

### Verificação
- ✅ Arquivo salvo no MinIO
- ✅ Metadados no PostgreSQL
- ✅ URL de acesso gerada
- ✅ Hash MD5 calculado

### Pontos de Discussão
- Object Storage para arquivos
- Separação de dados estruturados e binários
- Escalabilidade de armazenamento

---

## 📍 Etapa 6: Mensagem com Arquivo

### Conceitos Demonstrados
- Integração completa
- Referência entre serviços
- Fluxo end-to-end

### Ações
```bash
# Mensagem com arquivo anexo
curl -X POST http://localhost:8080/api/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{
    "conversation_id": "CONV_ID",
    "content": "Check this file!",
    "type": "file",
    "file_id": "FILE_ID"
  }'
```

### Verificação
- ✅ Mensagem referencia arquivo
- ✅ Conector recebe URL do arquivo
- ✅ Status de entrega atualizado

---

## 📍 Etapa 7: Escalabilidade Horizontal

### Conceitos Demonstrados
- Scaling de containers
- Consumer groups do Kafka
- Load balancing automático

### Ações
```bash
# Escalar workers para 3 instâncias
docker-compose up -d --scale router-worker=3 --scale whatsapp-mock=2

# Verificar partições
docker-compose exec kafka kafka-consumer-groups \
  --bootstrap-server localhost:9092 \
  --describe --group router-worker-group
```

### Verificação
- ✅ Múltiplas instâncias rodando
- ✅ Partições distribuídas entre consumers
- ✅ Rebalanceamento automático

### Demonstração Visual
1. Mostrar containers no `docker ps`
2. Enviar múltiplas mensagens
3. Observar distribuição nos logs

### Pontos de Discussão
- Consumer groups do Kafka
- Particionamento para paralelismo
- Escalabilidade elástica

---

## 📍 Etapa 8: Tolerância a Falhas

### Conceitos Demonstrados
- Recuperação de falhas
- Commit manual no Kafka
- Graceful shutdown

### Ações
```bash
# Parar um worker abruptamente
docker-compose stop router-worker

# Verificar offset lag
docker-compose exec kafka kafka-consumer-groups \
  --bootstrap-server localhost:9092 \
  --describe --group router-worker-group

# Reiniciar worker
docker-compose up -d router-worker

# Verificar recuperação
docker-compose logs -f --tail=50 router-worker
```

### Verificação
- ✅ Mensagens pendentes preservadas
- ✅ Worker reconecta ao grupo
- ✅ Mensagens reprocessadas
- ✅ Nenhuma mensagem perdida

### Pontos de Discussão
- Garantia at-least-once
- Manual commit após processamento
- Idempotência necessária

---

## 📍 Monitoramento (Bônus)

### Grafana Dashboard
1. Acessar `http://localhost:3000`
2. Login: admin/admin
3. Abrir dashboard "Chat4All - Complete"

### Métricas Demonstradas
- **Throughput**: Mensagens por segundo
- **Latência**: P50, P95, P99
- **WebSocket**: Conexões ativas
- **Kafka**: Consumer lag
- **Erros**: Taxa de falhas

### Prometheus Queries
```promql
# Taxa de mensagens
rate(messages_total[1m])

# Latência P95
histogram_quantile(0.95, rate(message_latency_seconds_bucket[5m]))

# Consumer lag
kafka_consumer_lag
```

---

## 🎬 Script Automatizado

Para executar toda a demonstração automaticamente:

```bash
cd finalTest/scripts
./full-demo.sh
```

### Opções do Script
- Execução passo a passo com confirmação
- Cores e formatação visual
- Timing de cada operação
- Saída JSON para análise

---

## 📸 Screenshots Sugeridos

### Durante a Demo
1. **Arquitetura**: Diagrama de componentes
2. **API Gateway**: Requisição e resposta
3. **Kafka UI**: Mensagens nos tópicos
4. **Grafana**: Dashboard completo
5. **Docker**: Containers rodando
6. **Frontend**: Interface WebSocket

### Para o Relatório
1. Fluxo de mensagem completo
2. Escalabilidade horizontal (antes/depois)
3. Recuperação de falha
4. Métricas de performance

---

## 🔧 Troubleshooting

### Kafka não conecta
```bash
# Verificar se Kafka está healthy
docker-compose ps kafka
docker-compose logs kafka | tail -50
```

### WebSocket não funciona
```bash
# Verificar WebSocket worker
docker-compose logs websocket-worker | tail -50
netstat -tuln | grep 8082
```

### Métricas não aparecem
```bash
# Verificar Prometheus targets
curl http://localhost:9090/api/v1/targets
```

### Workers não consomem
```bash
# Verificar consumer groups
docker-compose exec kafka kafka-consumer-groups \
  --bootstrap-server localhost:9092 --list
```

---

## 📝 Checklist Final

### Antes da Apresentação
- [ ] Docker Compose funcionando (`docker-compose ps`)
- [ ] Todos os serviços healthy
- [ ] Grafana acessível e logado
- [ ] Frontend conectando WebSocket
- [ ] Script de demo testado

### Durante a Apresentação
- [ ] Explicar arquitetura antes de demonstrar
- [ ] Mostrar código relevante quando apropriado
- [ ] Destacar conceitos de SD em cada etapa
- [ ] Responder perguntas com exemplos práticos

### Conceitos a Enfatizar
- [ ] Comunicação assíncrona (Kafka)
- [ ] Comunicação síncrona (gRPC)
- [ ] Escalabilidade horizontal
- [ ] Tolerância a falhas
- [ ] Monitoramento distribuído

---

## 🎯 Roteiro de Vídeo (Gravação)

### Introdução (1 min)
- Apresentar sistema Chat4All
- Mostrar diagrama de arquitetura
- Listar tecnologias utilizadas

### Demonstração (10-12 min)
- Executar etapas 1-8
- Narrar conceitos em cada etapa
- Mostrar logs e métricas

### Conclusão (2 min)
- Resumir conceitos demonstrados
- Mostrar dashboard final
- Apresentar resultados de testes

### Dicas de Gravação
- Usar terminal com fonte grande
- Pausar entre comandos para narração
- Destacar outputs importantes
- Manter Grafana visível quando possível
