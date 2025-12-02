# Conectores Mock - Chat4All

Este diretório contém os conectores simulados (mock) para integração com plataformas de mensageria externas.

## 📦 Conectores Disponíveis

### 1. WhatsApp Mock (`whatsapp-mock`)
Simula a integração com a API do WhatsApp Business.

**Porta:** 8081  
**Tópico Kafka:** `whatsapp.messages`  
**Delays típicos:** DELIVERED 1-3s, READ 3-8s

### 2. Instagram Mock (`instagram-mock`)
Simula a integração com a API do Instagram Direct.

**Porta:** 8082  
**Tópico Kafka:** `instagram.messages`  
**Delays típicos:** DELIVERED 2-4s, READ 5-12s

---

## 🏗️ Arquitetura

Cada connector possui:

### 1. **Consumer Kafka**
- Consome mensagens do tópico específico do canal
- Processa mensagens de forma assíncrona
- Simula delays de rede realistas

### 2. **API HTTP** (Slim Framework)
- **GET `/health`**: Health check do connector
- **POST `/webhook/incoming`**: Recebe mensagens simuladas do canal externo
- **POST `/send`**: Endpoint de teste para simular envio manual

### 3. **CallbackSender**
Classe responsável por enviar callbacks de status para o backend com:
- **Retry com backoff exponencial** (1s, 2s, 4s)
- **Tratamento de erros HTTP** (retry apenas para 5xx)
- **Logs estruturados** para debugging

### 4. **Simulação de Callbacks**
Cada connector simula os seguintes eventos:

| Status | WhatsApp | Instagram | Descrição |
|--------|----------|-----------|-----------|
| **SENT** | Imediato | Imediato | Mensagem enviada ao servidor |
| **DELIVERED** | 1-3s | 2-4s | Mensagem entregue ao dispositivo |
| **READ** | 3-8s | 5-12s | Mensagem visualizada pelo usuário |
| **FAILED** | Simulado | Simulado | Falha no envio (configurável) |

---

## 🚀 Como Funciona

### Fluxo de Envio de Mensagem

```mermaid
sequenceDiagram
    participant Backend as API Service
    participant Kafka as Apache Kafka
    participant Connector as WhatsApp/Instagram Mock
    participant External as "API Externa" (Simulada)

    Backend->>Kafka: Publica mensagem no tópico
    Kafka->>Connector: Consumer recebe mensagem
    Connector->>External: Simula envio (delay 50-300ms)
    Connector->>Backend: Callback: SENT (HTTP POST)
    Connector->>Connector: Log: "✅ Mensagem enviada"
    
    Note over Connector: Aguarda 1-4 segundos
    Connector->>Backend: Callback: DELIVERED (HTTP POST)
    
    Note over Connector: Aguarda 3-12 segundos
    Connector->>Backend: Callback: READ (HTTP POST)
```

### Fluxo de Recebimento de Mensagem

```mermaid
sequenceDiagram
    participant External as "API Externa" (Simulada)
    participant Connector as WhatsApp/Instagram Mock
    participant Backend as API Service

    External->>Connector: POST /webhook/incoming
    Connector->>Connector: Log: "📥 Mensagem recebida"
    Connector->>Backend: Encaminha mensagem
    Backend->>Connector: Confirmação
    Connector->>External: Response 200 OK
```

---

## 📝 Formato de Mensagens

### Mensagem no Kafka (Enviada pelo Backend)

```json
{
  "message_id": "uuid-da-mensagem",
  "to": "+5511999999999",
  "text": "Olá, como vai?",
  "file_id": "uuid-do-arquivo",
  "conversation_id": "uuid-da-conversa",
  "timestamp": 1234567890
}
```

### Callback de Status (Enviado pelo Connector)

```json
{
  "message_id": "uuid-da-mensagem",
  "status": "DELIVERED",
  "timestamp": 1234567890,
  "connector": "whatsapp"
}
```

---

## 🧪 Testando os Conectores

### 1. Verificar Health Check

```bash
# WhatsApp
curl http://localhost:8081/health

# Instagram
curl http://localhost:8082/health
```

### 2. Simular Envio Manual

```bash
# WhatsApp
curl -X POST http://localhost:8081/send \
  -H "Content-Type: application/json" \
  -d '{
    "to": "+5511999999999",
    "text": "Teste de mensagem"
  }'

# Instagram
curl -X POST http://localhost:8082/send \
  -H "Content-Type: application/json" \
  -d '{
    "to": "@usuario_instagram",
    "text": "Teste de mensagem"
  }'
```

### 3. Simular Recebimento de Mensagem

```bash
# WhatsApp
curl -X POST http://localhost:8081/webhook/incoming \
  -H "Content-Type: application/json" \
  -d '{
    "from": "+5511999999999",
    "text": "Olá, preciso de ajuda"
  }'

# Instagram
curl -X POST http://localhost:8082/webhook/incoming \
  -H "Content-Type: application/json" \
  -d '{
    "from": "@usuario_instagram",
    "text": "Olá, preciso de ajuda"
  }'
```

---

## 📊 Logs

Os conectores geram logs coloridos e informativos:

```
🚀 WhatsApp Mock Connector Consumer starting...
✅ Subscribed to topic: whatsapp.messages
🔄 Waiting for messages...

[WhatsApp] 📥 Mensagem recebida do Kafka {"message_id":"abc123","to":"+5511999999999"}
[WhatsApp] ✅ Entregue a usuário +5511999999999 {"message_id":"abc123","text":"Olá!"}
[WhatsApp] 📬 Callback: DELIVERED {"message_id":"abc123","to":"+5511999999999","timestamp":"2025-11-24 20:30:45"}
[WhatsApp] 👁️ Callback: READ {"message_id":"abc123","to":"+5511999999999","timestamp":"2025-11-24 20:30:55"}
```

---

## 🔧 Configuração

### Variáveis de Ambiente

| Variável | Padrão | Descrição |
|----------|--------|-----------|
| `KAFKA_BROKER` | `kafka:9093` | Endereço do broker Kafka |
| `BACKEND_CALLBACK_URL` | `http://api-service:8080/v1/callbacks/status` | URL para enviar callbacks |
| `DELIVERY_MIN_DELAY` | `1` (WA) / `2` (IG) | Delay mínimo para DELIVERED (segundos) |
| `DELIVERY_MAX_DELAY` | `3` (WA) / `4` (IG) | Delay máximo para DELIVERED (segundos) |
| `READ_MIN_DELAY` | `3` (WA) / `5` (IG) | Delay mínimo para READ (segundos) |
| `READ_MAX_DELAY` | `8` (WA) / `12` (IG) | Delay máximo para READ (segundos) |
| `FAILURE_PROBABILITY` | `0.0` | Probabilidade de falha simulada (0.0 a 1.0) |

### Exemplo de Configuração no docker-compose.yml

```yaml
connector-whatsapp:
  environment:
    KAFKA_BROKER: kafka:9093
    BACKEND_CALLBACK_URL: http://api-service:8080/v1/callbacks/status
    DELIVERY_MIN_DELAY: 1
    DELIVERY_MAX_DELAY: 3
    READ_MIN_DELAY: 3
    READ_MAX_DELAY: 8
    FAILURE_PROBABILITY: 0.05  # 5% de falhas simuladas
```

---

## 🛠️ Desenvolvimento

### Estrutura de cada connector:

```
connector-{nome}/
├── composer.json          # Dependências PHP
├── Dockerfile            # Imagem Docker
├── start.sh             # Script de inicialização
├── consumer.php         # Entrypoint do consumer Kafka
├── public/
│   └── index.php       # API HTTP (Slim)
└── src/
    ├── KafkaConsumer.php    # Lógica de consumo do Kafka
    ├── MessageProcessor.php  # Processamento e simulação
    └── CallbackSender.php   # Envio de callbacks com retry
```

### Adicionando um novo connector:

1. Copie a estrutura de um connector existente
2. Ajuste os namespaces em `composer.json` e classes PHP
3. Modifique os delays e logs conforme características do canal
4. Adicione ao `docker-compose.yml`:

```yaml
connector-{nome}:
  build:
    context: .
    dockerfile: connectors/{nome}-mock/Dockerfile
  container_name: chat4all-connector-{nome}
  ports:
    - "808X:808X"  # Escolha uma porta livre
  environment:
    KAFKA_BROKER: kafka:9093
    BACKEND_CALLBACK_URL: http://api-service:8080/v1/callbacks/{nome}
  depends_on:
    kafka:
      condition: service_started
  networks:
    - chat4all-network
```

---

## 📚 Próximos Passos

- [x] Implementar CallbackSender com retry exponencial
- [x] Adicionar suporte a anexos (file_id no payload)
- [x] Configurar delays via variáveis de ambiente
- [x] Simular erros e falhas (FAILURE_PROBABILITY)
- [ ] Implementar rate limiting simulado
- [ ] Adicionar métricas Prometheus
