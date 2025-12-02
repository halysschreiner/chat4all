# Escalabilidade Horizontal - Chat4All

Este documento descreve como escalar horizontalmente os componentes do Chat4All para aumentar throughput e disponibilidade.

## 📊 Visão Geral da Arquitetura

```
                    ┌──────────────────┐
                    │   Load Balancer  │
                    │     (nginx)      │
                    └────────┬─────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
       ┌──────┴─────┐ ┌──────┴─────┐ ┌──────┴─────┐
       │ api-service│ │ api-service│ │ api-service│
       │    (1)     │ │    (2)     │ │    (3)     │
       └──────┬─────┘ └──────┬─────┘ └──────┬─────┘
              │              │              │
              └──────────────┼──────────────┘
                             │
                    ┌────────▼────────┐
                    │      Kafka      │
                    │  (3 partitions) │
                    └────────┬────────┘
                             │
              ┌──────────────┼──────────────┐
              │              │              │
       ┌──────┴─────┐ ┌──────┴─────┐ ┌──────┴─────┐
       │router-worker│ │router-worker│ │router-worker│
       │    (1)     │ │    (2)     │ │    (3)     │
       └──────┬─────┘ └──────┬─────┘ └──────┬─────┘
              │              │              │
              └──────────────┼──────────────┘
                             │
                    ┌────────▼────────┐
                    │  Platform Topics │
                    │ whatsapp/instagram│
                    └────────┬────────┘
                             │
       ┌─────────────────────┼─────────────────────┐
       │                     │                     │
┌──────┴─────┐        ┌──────┴─────┐        ┌──────┴─────┐
│ connector  │        │ connector  │        │ connector  │
│ whatsapp(1)│        │ whatsapp(2)│        │ instagram  │
└────────────┘        └────────────┘        └────────────┘
```

## 🚀 Comandos de Scaling

### Escalar Router Workers

```bash
# Escalar para 3 instâncias
docker-compose up -d --scale router-worker=3

# Escalar para 5 instâncias
docker-compose up -d --scale router-worker=5

# Voltar para 1 instância
docker-compose up -d --scale router-worker=1
```

### Escalar Conectores

```bash
# Escalar WhatsApp connector para 3 instâncias
docker-compose up -d --scale connector-whatsapp=3

# Escalar Instagram connector para 2 instâncias
docker-compose up -d --scale connector-instagram=2
```

### Escalar API Service

```bash
# Escalar API para 3 instâncias
docker-compose up -d --scale api-service=3

# Nota: Requer load balancer configurado
```

## ⚙️ Configuração do Kafka para Paralelismo

### Criar Tópicos com Múltiplas Partições

```bash
# Entrar no container do Kafka
docker exec -it chat4all-kafka bash

# Criar tópico messages com 3 partições
kafka-topics --create \
  --topic messages \
  --bootstrap-server kafka:9092 \
  --partitions 3 \
  --replication-factor 1

# Criar tópicos por plataforma
kafka-topics --create \
  --topic whatsapp.messages \
  --bootstrap-server kafka:9092 \
  --partitions 3 \
  --replication-factor 1

kafka-topics --create \
  --topic instagram.messages \
  --bootstrap-server kafka:9092 \
  --partitions 3 \
  --replication-factor 1

# Tópico de status updates
kafka-topics --create \
  --topic status-updates \
  --bootstrap-server kafka:9092 \
  --partitions 3 \
  --replication-factor 1
```

### Alterar Partições de Tópico Existente

```bash
# Aumentar partições de tópico existente
kafka-topics --alter \
  --topic messages \
  --partitions 6 \
  --bootstrap-server kafka:9092

# Verificar configuração
kafka-topics --describe \
  --topic messages \
  --bootstrap-server kafka:9092
```

## 🔍 Verificação de Distribuição de Carga

### Verificar Consumer Groups

```bash
# Listar consumer groups
kafka-consumer-groups --list --bootstrap-server kafka:9092

# Ver detalhes do grupo router-worker
kafka-consumer-groups --describe \
  --group router-worker-group \
  --bootstrap-server kafka:9092

# Ver lag de cada partição
kafka-consumer-groups --describe \
  --group router-worker-group \
  --bootstrap-server kafka:9092 \
  --members --verbose
```

### Output Esperado (3 workers, 3 partições)

```
GROUP                  TOPIC      PARTITION  CURRENT-OFFSET  LOG-END-OFFSET  LAG
router-worker-group    messages   0          1500            1500            0
router-worker-group    messages   1          1500            1500            0
router-worker-group    messages   2          1500            1500            0

CONSUMER-ID                                      HOST            CLIENT-ID       #PARTITIONS
router-worker-1                                  /172.20.0.5     rdkafka         1
router-worker-2                                  /172.20.0.6     rdkafka         1
router-worker-3                                  /172.20.0.7     rdkafka         1
```

## 📈 Métricas de Throughput

### Monitorar via Prometheus

```bash
# Métricas de mensagens processadas
curl http://localhost:9090/api/v1/query?query=messages_processed_total

# Taxa de mensagens por segundo
curl http://localhost:9090/api/v1/query?query=rate(messages_processed_total[1m])
```

### Métricas no Grafana

Dashboard "Chat4All Workers" mostra:
- Mensagens processadas por worker
- Tempo médio de processamento
- Lag do consumer group
- Taxa de erros

## 🔧 Configurações Importantes

### Consumer Group IDs

| Componente | Group ID | Propósito |
|-----------|----------|-----------|
| router-worker | router-worker-group | Processa mensagens do tópico principal |
| whatsapp-connector | whatsapp-connector-group | Processa mensagens para WhatsApp |
| instagram-connector | instagram-connector-group | Processa mensagens para Instagram |
| websocket-worker | websocket-status-group | Processa atualizações de status |

### Variáveis de Ambiente

```yaml
# docker-compose.yml
router-worker:
  environment:
    KAFKA_GROUP_ID: router-worker-group
    KAFKA_TOPIC_MESSAGES: messages
    # Configurações de performance
    KAFKA_FETCH_MIN_BYTES: 1024
    KAFKA_FETCH_MAX_WAIT_MS: 500
```

## ⚠️ Considerações

### Ordenação de Mensagens

- Kafka garante ordem **dentro da mesma partição**
- Se a ordem for importante, use `conversation_id` como chave da mensagem
- Todas as mensagens de uma conversa vão para a mesma partição

### Rebalanceamento

Quando você escala workers:
1. Kafka detecta novo/removido consumer
2. Inicia rebalanceamento (pausa consumo)
3. Redistribui partições entre consumers
4. Consumers retomam do último offset commitado

Tempo típico de rebalanceamento: 5-15 segundos

### Limites

| Componente | Limite Recomendado | Motivo |
|-----------|-------------------|--------|
| router-worker | ≤ número de partições | Mais workers que partições = workers ociosos |
| connectors | 1-3 por plataforma | Dependente de rate limits da API externa |
| api-service | 3-10 | Dependente de conexões DB disponíveis |

## 📝 Exemplo de Teste de Scaling

```bash
# 1. Estado inicial
docker-compose ps

# 2. Enviar carga de teste
./finalTest/scripts/test-horizontal-scaling.sh

# 3. Escalar durante teste
docker-compose up -d --scale router-worker=3

# 4. Verificar distribuição
kafka-consumer-groups --describe --group router-worker-group --bootstrap-server localhost:9092

# 5. Verificar métricas no Grafana
# http://localhost:3001/d/workers
```

## 🔗 Referências

- [Kafka Consumer Groups](https://kafka.apache.org/documentation/#consumerconfigs)
- [Docker Compose Scale](https://docs.docker.com/compose/reference/up/)
- [Prometheus Metrics](https://prometheus.io/docs/introduction/overview/)
