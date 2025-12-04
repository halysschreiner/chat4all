# RNF04 - Message Broker (Apache Kafka)

---

## 1. Resumo do Requisito

### RNF04 - Message Broker (Apache Kafka)
> - Utilizar Apache Kafka para comunicação assíncrona entre serviços.
> - Tópicos particionados por `conversation_id` (5 partições).
> - Consumer Groups para balanceamento automático de carga.
> - Garantia "at-least-once delivery".

### Importância Teórica

**Kafka** é o backbone de sistemas event-driven modernos:
- Netflix: 700 bilhões de eventos/dia
- LinkedIn: 1 trilhão de mensagens/dia
- Uber: 1 trilhão de mensagens/dia

---

## 2. Fundamentos Teóricos

### 2.1 Apache Kafka - Arquitetura

```
┌─────────────────────────────────────────────────────────────┐
│                     KAFKA CLUSTER                           │
│                                                             │
│  ┌─────────────────────────────────────────────────┐       │
│  │                    TÓPICO: messages              │       │
│  │  ┌────────────────────────────────────────────┐ │       │
│  │  │ Partition 0: [msg1] [msg4] [msg7] [msg10]  │ │       │
│  │  ├────────────────────────────────────────────┤ │       │
│  │  │ Partition 1: [msg2] [msg5] [msg8]          │ │       │
│  │  ├────────────────────────────────────────────┤ │       │
│  │  │ Partition 2: [msg3] [msg6] [msg9]          │ │       │
│  │  └────────────────────────────────────────────┘ │       │
│  └─────────────────────────────────────────────────┘       │
│                                                             │
│  Producer ──▶ hash(conversation_id) % 5 = partition        │
│                                                             │
│  Consumer Group "router-worker-group":                      │
│    Consumer 1: Partition 0, 1                               │
│    Consumer 2: Partition 2                                  │
│    (Rebalance automático quando consumer entra/sai)         │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Conceitos-Chave do Kafka

| Conceito | Descrição | No Chat4All |
|----------|-----------|-------------|
| **Topic** | Canal de mensagens | `messages`, `whatsapp.messages`, `instagram.messages` |
| **Partition** | Divisão ordenada do tópico | 5 partições por tópico |
| **Producer** | Publica mensagens | `KafkaProducer.php` em api-service |
| **Consumer** | Consome mensagens | `KafkaConsumer.php` em router-worker |
| **Consumer Group** | Grupo de consumers | `router-worker-group` |
| **Offset** | Posição da mensagem | Commitado manualmente |

### 2.3 Garantias de Entrega

```
┌─────────────────────────────────────────────────────────────┐
│              GARANTIAS DE ENTREGA NO KAFKA                  │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  AT-MOST-ONCE (autocommit=true, sem retry)                  │
│  ─────────────────────────────────────────                  │
│  Producer envia → Consumer recebe → Commit → Processa       │
│  ⚠️ Se falhar no processamento, mensagem perdida            │
│                                                             │
│  AT-LEAST-ONCE (autocommit=false, commit após processar) ✅ │
│  ────────────────────────────────────────────────────────   │
│  Producer envia → Consumer recebe → Processa → Commit       │
│  ⚠️ Se falhar após processar mas antes de commit:           │
│     Mensagem reprocessada (duplicação possível)             │
│                                                             │
│  EXACTLY-ONCE (transactional API, Kafka Streams)            │
│  ─────────────────────────────────────────────              │
│  Produtor e Consumer coordenados via transação              │
│  ✅ Sem duplicação, mas complexidade maior                  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.4 Kafka vs RabbitMQ - Análise Comparativa

| Aspecto | **Apache Kafka** | **RabbitMQ** |
|---------|-----------------|--------------|
| **Paradigma** | **Commit Log** (append-only) | **Message Queue** (consume-and-delete) |
| **Mensagens após consumo** | **Persistem** por tempo configurado | **Removidas** por padrão |
| **Semântica** | Event Streaming | Task Queue |
| **Ordenação** | **Garantida por partição** | Garantida por fila |
| **Replay** | ✅ Nativo (seek to offset) | ❌ Não existe |
| **Throughput** | ~1M msgs/s | ~50-200k msgs/s |
| **Múltiplos consumers** | Consumer Groups nativos | Fan-out exchanges |
| **Complexidade** | Alta (Zookeeper, partições) | Média (AMQP simples) |
| **Latência** | 5-10ms (batching) | ~1ms |

#### Por que Kafka para o Chat4All?

| Cenário | Justificativa |
|---------|---------------|
| **Requisito de Auditoria (RF10)** | Commit log = histórico imutável. Reconstrução de estado a partir de eventos. |
| **Replay para Debug/Recovery** | Se `router-worker` falha: `consumer.seek(offset - N)`. Com RabbitMQ: perdido. |
| **Múltiplos Consumers** | `router-worker` + `websocket-worker` + `analytics-worker` consumindo `messages`. |
| **Ordenação por Conversa** | Partição por `conversation_id` garante FIFO dentro da conversa. |

---

## 3. Implementação no Chat4All

### 3.1 Configuração do Cluster (`docker-compose.yml`)

**Zookeeper**:
```yaml
zookeeper:
  image: confluentinc/cp-zookeeper:7.5.0
  container_name: chat4all-zookeeper
  environment:
    ZOOKEEPER_CLIENT_PORT: 2181
    ZOOKEEPER_TICK_TIME: 2000
  healthcheck:
    test: ["CMD", "nc", "-z", "localhost", "2181"]
    interval: 10s
```

**Kafka**:
```yaml
kafka:
  image: confluentinc/cp-kafka:7.5.0
  container_name: chat4all-kafka
  depends_on:
    zookeeper:
      condition: service_healthy
  environment:
    KAFKA_BROKER_ID: 1
    KAFKA_ZOOKEEPER_CONNECT: zookeeper:2181
    KAFKA_ADVERTISED_LISTENERS: PLAINTEXT://localhost:9092,INTERNAL://kafka:9093
    KAFKA_LISTENER_SECURITY_PROTOCOL_MAP: PLAINTEXT:PLAINTEXT,INTERNAL:PLAINTEXT
    KAFKA_INTER_BROKER_LISTENER_NAME: INTERNAL
    KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
    KAFKA_AUTO_CREATE_TOPICS_ENABLE: "true"
```

### 3.2 Inicialização de Tópicos (`scripts/init-kafka-topics.sh`)

```bash
#!/bin/bash
# Criar tópicos com 5 partições

kafka-topics --create \
    --bootstrap-server kafka:9092 \
    --topic messages \
    --partitions 5 \
    --replication-factor 1 \
    --if-not-exists

kafka-topics --create \
    --bootstrap-server kafka:9092 \
    --topic whatsapp.messages \
    --partitions 5 \
    --replication-factor 1 \
    --if-not-exists

kafka-topics --create \
    --bootstrap-server kafka:9092 \
    --topic instagram.messages \
    --partitions 5 \
    --replication-factor 1 \
    --if-not-exists
```

### 3.3 Produtor Kafka (`KafkaProducer.php`)

```php
/**
 * Publicar mensagem no Kafka
 * 
 * @param array $message Dados da mensagem
 * @param string|null $key Chave de particionamento (conversation_id para garantir ordem)
 */
public function publish(array $message, ?string $key = null): void
{
    try {
        $payload = json_encode($message);

        // RD_KAFKA_PARTITION_UA = usar particionamento automático baseado na key
        // hash(key) % num_partitions = partition assignment
        $this->topic->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);

        // Poll para processar callbacks internos
        $this->producer->poll(0);

        $this->logger->info('Message published to Kafka', [
            'message_id' => $message['message_id'] ?? 'unknown',
            'key' => $key  // conversation_id
        ]);

        // Flush síncrono para garantir entrega
        for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
            $result = $this->producer->flush(1000);  // Timeout 1s
            if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                break;
            }
        }
    } catch (\Exception $e) {
        $this->logger->error('Failed to publish message to Kafka: ' . $e->getMessage());
        throw $e;
    }
}
```

**Particionamento por conversation_id** (`MessageService.php`):
```php
// Publica com key = conversation_id
$this->kafkaProducer->publish($kafkaMessage, $conversationId);

// Isso garante que mensagens da mesma conversa vão para a mesma partição
// Resultado: ORDEM PRESERVADA por conversa
```

### 3.4 Consumidor Kafka (`KafkaConsumer.php`)

**Configuração**:
```php
// Configurações básicas de conexão
$conf->set('metadata.broker.list', $brokers);
$conf->set('group.id', $groupId);  // "router-worker-group"

// TOLERÂNCIA A FALHAS: Commit manual de offsets
// Com auto.commit desabilitado, o offset só é commitado após
// processamento bem-sucedido
$conf->set('enable.auto.commit', 'false');  // 🔑 AT-LEAST-ONCE

// Começar do início se não houver offset armazenado
$conf->set('auto.offset.reset', 'earliest');

// Configurações de sessão para rebalanceamento rápido
$conf->set('session.timeout.ms', '10000');   // 10s para detectar consumer morto
$conf->set('heartbeat.interval.ms', '3000'); // Heartbeat a cada 3s
$conf->set('max.poll.interval.ms', '300000'); // 5min máximo entre polls
```

**Loop de Consumo**:
```php
public function consume(): void
{
    // Iniciar consumidor na partição 0 do início
    $this->topic->consumeStart(0, RD_KAFKA_OFFSET_STORED);

    $this->logger->info('Starting message consumption loop');
    $processedCount = 0;

    while (true) {
        // Poll com timeout de 1000ms
        $message = $this->topic->consume(0, 1000);

        if ($message === null) {
            continue;
        }

        switch ($message->err) {
            case RD_KAFKA_RESP_ERR_NO_ERROR:
                // Mensagem recebida com sucesso
                $payload = json_decode($message->payload, true);
                
                try {
                    // Processar mensagem (rotear para connector)
                    $this->processor->process($payload);
                    $processedCount++;

                    // COMMIT MANUAL após processamento bem-sucedido
                    // Se falhar antes do commit, mensagem será reprocessada
                    $this->topic->offsetStore($message->partition, $message->offset);
                    
                    $this->logger->debug('Message processed and offset stored', [
                        'offset' => $message->offset,
                        'partition' => $message->partition
                    ]);
                } catch (\Exception $e) {
                    // Falha no processamento - NÃO commita offset
                    // Mensagem será reprocessada no próximo poll
                    $this->logger->error('Error processing message: ' . $e->getMessage());
                }
                break;

            case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                // Fim da partição - normal, continuar polling
                break;

            case RD_KAFKA_RESP_ERR__TIMED_OUT:
                // Timeout - normal, continuar polling
                break;

            default:
                $this->logger->error('Kafka error: ' . $message->errstr());
                break;
        }
    }
}
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| Kafka assíncrono | ✅ | `KafkaProducer.php`, `KafkaConsumer.php` |
| 5 partições | ✅ | `init-kafka-topics.sh` |
| Consumer Groups | ✅ | `group.id: router-worker-group` |
| At-least-once | ✅ | `enable.auto.commit: false` + commit manual |

### 4.2 Pontos Fortes

1. **Particionamento por conversation_id**: Garante ordem de mensagens por conversa
2. **Commit manual**: Evita perda de mensagens em falhas
3. **Flush síncrono no Producer**: Garante que mensagem foi persistida no broker
4. **Consumer Groups**: Permite escalabilidade horizontal dos workers

### 4.3 Limitações Identificadas

#### Limitação 1: Replication Factor = 1

**Problema** (`docker-compose.yml`):
```yaml
KAFKA_OFFSETS_TOPIC_REPLICATION_FACTOR: 1
```

Se o broker único falhar, **todos os dados são perdidos**. Isso contradiz o requisito RNF07 de "zero perda de mensagens".

**Solução para produção**:
```yaml
# Adicionar mais brokers
kafka-1:
  environment:
    KAFKA_BROKER_ID: 1
    KAFKA_DEFAULT_REPLICATION_FACTOR: 3
    KAFKA_MIN_INSYNC_REPLICAS: 2

kafka-2:
  environment:
    KAFKA_BROKER_ID: 2
    
kafka-3:
  environment:
    KAFKA_BROKER_ID: 3
```

#### Limitação 2: Idempotência Não Garantida

**Problema**: At-least-once pode causar duplicação.

```php
// Se isso falhar após persistir no banco mas antes do commit Kafka:
$this->database->saveMessage($message);  // ✅ Salvo
$this->topic->offsetStore(...);          // ❌ Falhou
// Próximo poll: mensagem reprocessada e salva novamente
```

**Solução**: Upsert com message_id como chave:
```sql
INSERT INTO messages (message_id, content, ...)
VALUES ($1, $2, ...)
ON CONFLICT (message_id) DO NOTHING;  -- Idempotente
```

#### Limitação 3: Hot Partition

**Problema**: Se uma conversa gera 90% do tráfego, sua partição fica sobrecarregada.

**Solução**: Monitorar distribuição de mensagens por partição e considerar chave híbrida:
```php
// Chave composta para distribuição mais uniforme
$key = $conversationId . ':' . floor(time() / 60);  // Janela de 1 minuto
```

#### Limitação 4: Zookeeper como SPOF

**Problema**: Dependência do Zookeeper para coordenação.

**Solução**: Migrar para **KRaft** (Kafka sem Zookeeper, disponível desde Kafka 3.3).

### 4.4 Perguntas Socráticas para Aprofundamento

1. "Se uma partição fica indisponível, o que acontece com as mensagens destinadas a ela?"
2. "Consumer Group garante ordem entre consumers? Ou apenas dentro de cada partição?"
3. "O que é o teorema de FLP e como se relaciona com a coordenação do Kafka?"
4. "Por que 5 partições especificamente? Qual cálculo levou a esse número?"
5. "Qual o retention period configurado? O que acontece com mensagens após esse período?"
6. "Kafka prioriza C ou A em partição de rede (CAP Theorem)?"

---

## 5. Referências Teóricas

- **Kleppmann, M.** - *Designing Data-Intensive Applications* (Capítulos 4, 11)
- **Apache Kafka Documentation** - *Kafka: The Definitive Guide*
- **Narkhede, N., Shapira, G., Palino, T.** - *Kafka: The Definitive Guide* (O'Reilly)
- **CAP Theorem** - Brewer, E. (2000)
- **FLP Impossibility** - Fischer, Lynch, Paterson (1985)
