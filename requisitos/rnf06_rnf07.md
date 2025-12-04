# RNF06 e RNF07 - Escalabilidade Horizontal e Tolerância a Falhas

---

## 1. Resumo dos Requisitos

### RNF06 - Escalabilidade Horizontal
> - Executar múltiplas instâncias do router-worker (testado 1-5 instâncias).
> - Executar múltiplas instâncias dos connectors (testado 1-3 instâncias por tipo).
> - Demonstrar aumento de throughput ao adicionar nós.
> - Redistribuição automática de carga ao adicionar/remover workers.

### RNF07 - Tolerância a Falhas
> - Manual commit no Kafka (evita perda de mensagens).
> - Consumer Group Rebalancing automático quando workers falham.
> - Graceful shutdown handlers para encerramento controlado.
> - Políticas de restart do Docker (`restart: unless-stopped`).
> - Recuperação automática sem intervenção manual.
> - Zero perda de mensagens garantida.

### Importância Teórica

Estes requisitos refletem os pilares de sistemas distribuídos:
- **Escalabilidade**: Lei de Amdahl vs Lei de Gustafson
- **Disponibilidade**: Uptime garantido mesmo com falhas parciais
- **Resiliência**: Capacidade de recuperação automática

---

## 2. Fundamentos Teóricos

### 2.1 Escalabilidade: Vertical vs Horizontal

```
┌─────────────────────────────────────────────────────────────┐
│              ESCALABILIDADE VERTICAL (SCALE UP)             │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   Antes:        Depois:                                     │
│   ┌────────┐    ┌────────────────┐                         │
│   │ Server │    │    BIGGER      │                         │
│   │ 4 CPU  │ →  │   SERVER       │                         │
│   │ 16 GB  │    │  32 CPU        │                         │
│   └────────┘    │  256 GB        │                         │
│                 └────────────────┘                         │
│                                                             │
│   ✅ Simples (sem mudança de código)                       │
│   ⚠️ Limite físico (hardware máximo)                       │
│   ⚠️ Ponto único de falha                                  │
│   ⚠️ Custo exponencial                                     │
│                                                             │
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│            ESCALABILIDADE HORIZONTAL (SCALE OUT) ✅         │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│   Antes:            Depois:                                 │
│   ┌────────┐        ┌────────┐ ┌────────┐ ┌────────┐       │
│   │ Server │   →    │Server 1│ │Server 2│ │Server 3│       │
│   │ 4 CPU  │        │ 4 CPU  │ │ 4 CPU  │ │ 4 CPU  │       │
│   └────────┘        └────────┘ └────────┘ └────────┘       │
│                                                             │
│   ✅ Sem limite teórico                                    │
│   ✅ Tolerância a falhas (redundância)                     │
│   ✅ Custo linear                                          │
│   ⚠️ Complexidade de coordenação                           │
│   ⚠️ Consistência distribuída (CAP)                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Consumer Group Rebalancing

```
┌─────────────────────────────────────────────────────────────┐
│                 KAFKA CONSUMER GROUP                        │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Tópico: messages (5 partições)                            │
│  Consumer Group: router-worker-group                        │
│                                                             │
│  CENÁRIO 1: 2 Workers                                       │
│  ┌─────────────────────────────────────────────┐           │
│  │ Worker 1: P0, P1, P2    Worker 2: P3, P4    │           │
│  └─────────────────────────────────────────────┘           │
│                                                             │
│  CENÁRIO 2: 5 Workers (máximo efetivo)                     │
│  ┌─────────────────────────────────────────────┐           │
│  │ W1:P0  W2:P1  W3:P2  W4:P3  W5:P4           │           │
│  └─────────────────────────────────────────────┘           │
│                                                             │
│  CENÁRIO 3: 7 Workers (2 ociosos!)                         │
│  ┌─────────────────────────────────────────────┐           │
│  │ W1:P0  W2:P1  W3:P2  W4:P3  W5:P4  W6:∅  W7:∅ │          │
│  └─────────────────────────────────────────────┘           │
│                                                             │
│  ⚠️ Número de workers > partições = desperdício            │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.3 Tolerância a Falhas: At-Least-Once Delivery

```
┌─────────────────────────────────────────────────────────────┐
│                    FLUXO DE COMMIT MANUAL                   │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  1. Consumer recebe mensagem (offset = 42)                  │
│     ┌────────────┐                                         │
│     │ poll()     │ ──▶ msg at offset 42                    │
│     └────────────┘                                         │
│                                                             │
│  2. Processa mensagem (pode falhar aqui)                   │
│     ┌────────────┐                                         │
│     │ process()  │ ──▶ ✅ Sucesso OU ❌ Falha              │
│     └────────────┘                                         │
│                                                             │
│  3a. Se SUCESSO: Commit offset                             │
│     ┌────────────┐                                         │
│     │ commit(42) │ ──▶ Broker sabe: consumidor está em 42  │
│     └────────────┘                                         │
│                                                             │
│  3b. Se FALHA: NÃO commit                                  │
│     ┌────────────┐                                         │
│     │ (nada)     │ ──▶ Próximo poll() retorna offset 42    │
│     └────────────┘     (mensagem reprocessada)             │
│                                                             │
│  RESULTADO: Zero perda de mensagens                        │
│  TRADE-OFF: Possível duplicação (idempotência necessária)  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Implementação no Chat4All

### 3.1 RNF06 - Escalabilidade Horizontal

#### 3.1.1 Configuração para Scaling (`docker-compose.yml`)

**Linhas 161-183 (Router Worker)**:
```yaml
router-worker:
  build:
    context: .
    dockerfile: workers/router-worker/Dockerfile
  # container_name comentado para permitir scaling
  # container_name: chat4all-router-worker
  
  environment:
    DB_HOST: postgres
    DB_PORT: 5432
    DB_NAME: chat4all
    DB_USER: chat4all_user
    DB_PASSWORD: chat4all_pass
    KAFKA_BROKERS: kafka:9093
    KAFKA_GROUP_ID: router-worker-group  # 🔑 Mesmo grupo!
    KAFKA_TOPIC: messages
  depends_on:
    postgres:
      condition: service_healthy
    kafka:
      condition: service_started
```

**Linhas 220-245 (Connectors)**:
```yaml
whatsapp-connector:
  build:
    context: .
    dockerfile: connectors/whatsapp-mock/Dockerfile
  # container_name comentado para permitir scaling
  # container_name: whatsapp-connector
  # ports comentado para evitar conflito
  # ports:
  #   - "9003:80"
  environment:
    KAFKA_BROKER: kafka:9093
    BACKEND_CALLBACK_URL: http://api-service:8080/v1/callbacks/whatsapp

instagram-connector:
  build:
    context: .
    dockerfile: connectors/instagram-mock/Dockerfile
  # container_name: instagram-connector
```

#### 3.1.2 Script de Teste de Scaling (`test-horizontal-scaling.sh`)

**Linhas 164-190 (Scaling workers)**:
```bash
# Escalar workers
print_test "4. Escalando workers para ${SCALE_WORKERS} instâncias"

$COMPOSE_CMD up -d --scale router-worker=$SCALE_WORKERS 2>/dev/null

# Aguardar workers iniciarem
print_info "Aguardando workers iniciarem..."
sleep 10

NEW_WORKERS=$($COMPOSE_CMD ps router-worker 2>/dev/null | grep -c "Up" || echo "0")
print_info "Workers ativos após scaling: ${NEW_WORKERS}"

if [ "$NEW_WORKERS" -eq "$SCALE_WORKERS" ]; then
    print_success "Scaling bem-sucedido: ${NEW_WORKERS} workers ativos"
fi

# Verificar distribuição no consumer group
CG_INFO=$(docker exec $KAFKA_CONTAINER kafka-consumer-groups \
    --describe \
    --group router-worker-group \
    --bootstrap-server kafka:9092)

# Contar consumers ativos
ACTIVE_CONSUMERS=$(echo "$CG_INFO" | grep -v "^$" | grep -v "GROUP" | wc -l)
print_info "Consumers ativos no grupo: ${ACTIVE_CONSUMERS}"
```

#### 3.1.3 Consumer Group para Balanceamento (`KafkaConsumer.php`)

**Linhas 50-75**:
```php
// CRÍTICO: Mesmo group.id para todos os workers
$conf->set('group.id', $groupId);  // "router-worker-group"

// Kafka distribui partições automaticamente entre consumers do grupo
// Se 5 partições e 3 workers:
//   Worker 1: Partitions 0, 1
//   Worker 2: Partitions 2, 3
//   Worker 3: Partition 4

// Configurações de rebalanceamento
$conf->set('session.timeout.ms', '10000');   // 10s para detectar falha
$conf->set('heartbeat.interval.ms', '3000'); // Heartbeat cada 3s
```

### 3.2 RNF07 - Tolerância a Falhas

#### 3.2.1 Manual Commit (`KafkaConsumer.php`)

**Linhas 56-58**:
```php
// TOLERÂNCIA A FALHAS: Commit manual de offsets
// Com auto.commit desabilitado, o offset só é commitado após
// processamento bem-sucedido
$conf->set('enable.auto.commit', 'false');  // 🔑 AT-LEAST-ONCE
```

**Linhas 120-150 (Loop de processamento)**:
```php
switch ($message->err) {
    case RD_KAFKA_RESP_ERR_NO_ERROR:
        $payload = json_decode($message->payload, true);
        
        try {
            // 1. Processar mensagem
            $this->processor->process($payload);
            $processedCount++;

            // 2. COMMIT MANUAL após sucesso
            $this->topic->offsetStore($message->partition, $message->offset);
            
            $this->logger->debug('Message processed and offset stored', [
                'offset' => $message->offset,
                'partition' => $message->partition
            ]);
        } catch (\Exception $e) {
            // 3. NÃO commita em falha - será reprocessado
            $this->logger->error('Error processing message: ' . $e->getMessage());
        }
        break;
}
```

#### 3.2.2 Graceful Shutdown

**Linhas 170-190 (Signal handlers)**:
```php
// Registrar handlers para shutdown graceful
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use ($logger, &$running) {
        $logger->info('Received SIGTERM, initiating graceful shutdown...');
        $running = false;
    });
    
    pcntl_signal(SIGINT, function () use ($logger, &$running) {
        $logger->info('Received SIGINT, initiating graceful shutdown...');
        $running = false;
    });
}

// No loop de consumo
while ($running) {
    // Processar signals pendentes
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    
    // ... consumir mensagens
}

// Cleanup ao sair
$logger->info('Shutting down gracefully...');
```

#### 3.2.3 Docker Restart Policies (`docker-compose.yml`)

**Linhas 195-215**:
```yaml
websocket-worker:
  # ...
  restart: unless-stopped  # 🔑 Reinicia em falhas
  healthcheck:
    test: ["CMD", "nc", "-z", "localhost", "8081"]
    interval: 30s
    timeout: 10s
    retries: 3
```

#### 3.2.4 Cenários de Falha Documentados (`FAULT_TOLERANCE.md`)

```markdown
### Cenário 1: Falha de Worker Individual

1. Worker 2 falha durante processamento
2. Mensagem em processamento NÃO foi commitada
3. Docker detecta falha e reinicia o container
4. Kafka detecta timeout de sessão (10s)
5. Partições são redistribuídas para workers restantes
6. Worker reiniciado volta ao Consumer Group
7. Mensagem não commitada é reprocessada

### Cenário 2: Falha do Kafka Broker

1. Broker torna-se indisponível
2. Producers recebem erro ao tentar publicar
3. Consumers param de receber mensagens
4. Após recuperação:
   - Producers reiniciam publicação
   - Consumers continuam do último offset commitado
   - Zero mensagens perdidas
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| **RNF06**: 1-5 router-workers | ✅ | `--scale router-worker=5` |
| **RNF06**: 1-3 connectors | ✅ | `--scale whatsapp-connector=3` |
| **RNF06**: Aumento de throughput | ✅ | Teste em `test-horizontal-scaling.sh` |
| **RNF06**: Redistribuição automática | ✅ | Consumer Group rebalancing |
| **RNF07**: Manual commit | ✅ | `enable.auto.commit: false` |
| **RNF07**: Rebalancing | ✅ | `session.timeout.ms: 10000` |
| **RNF07**: Graceful shutdown | ✅ | `pcntl_signal(SIGTERM, ...)` |
| **RNF07**: Docker restart | ✅ | `restart: unless-stopped` |
| **RNF07**: Zero perda | ✅ | Commit após processo + at-least-once |

### 4.2 Pontos Fortes

1. **Consumer Group Pattern**: Escalabilidade sem código adicional
2. **Timeout configurável**: 10s rápido o suficiente para reação, lento o suficiente para evitar falsos positivos
3. **Health checks**: Docker detecta containers não responsivos
4. **Logs estruturados**: Facilita debugging de falhas

### 4.3 Limitações Identificadas

#### Limitação 1: Número de Workers > Partições

**Problema**: Com 5 partições, máximo de 5 workers efetivos.

```
# Se 7 workers para 5 partições:
Workers 6 e 7 ficam ociosos (sem partições atribuídas)
```

**Solução**: Criar mais partições ou usar trabalho em lote:
```bash
# Criar tópico com mais partições
kafka-topics --alter --topic messages --partitions 10
```

#### Limitação 2: Rebalancing Causa Pause

**Problema**: Durante rebalanceamento, todos os consumers param momentaneamente.

**Impacto**: Latência aumentada durante scaling.

**Solução**: Incremental Cooperative Rebalancing (Kafka 2.4+):
```php
$conf->set('partition.assignment.strategy', 'cooperative-sticky');
```

#### Limitação 3: Sem Circuit Breaker em Connectors

**Problema**: Se callback para API falha, connector continua tentando.

```php
// MessageProcessor.php - Linha ~80
$this->callbackSender->sendCallback($status);  // E se falhar 100 vezes?
```

**Solução**: Implementar circuit breaker:
```php
if ($this->circuitBreaker->isOpen()) {
    $this->logger->warn('Circuit open, skipping callback');
    return;
}

try {
    $this->callbackSender->sendCallback($status);
    $this->circuitBreaker->recordSuccess();
} catch (\Exception $e) {
    $this->circuitBreaker->recordFailure();
    throw $e;
}
```

#### Limitação 4: Sem Exactly-Once

**Problema**: At-least-once pode causar duplicação.

**Solução**: Idempotência no lado do consumidor:
```sql
-- Upsert idempotente
INSERT INTO messages (message_id, status, ...)
VALUES ($1, $2, ...)
ON CONFLICT (message_id) DO UPDATE SET status = EXCLUDED.status;
```

### 4.4 Perguntas Socráticas para Aprofundamento

1. **Sobre Escalabilidade**:
   - "Se você precisa de mais throughput que 5 workers permitem, qual sua estratégia?"
   - "Como você garantiria ordem de mensagens com múltiplos workers?"
   - "A Lei de Amdahl limita sua escalabilidade horizontal? Em qual componente?"

2. **Sobre Tolerância a Falhas**:
   - "O que acontece se o PostgreSQL cair enquanto o worker está processando?"
   - "Como você testaria falhas de rede intermitentes?"
   - "Qual o tempo máximo de indisponibilidade aceitável?"

3. **Sobre Trade-offs**:
   - "Exactly-once é possível? Qual o custo em complexidade?"
   - "Rebalancing rápido (5s timeout) vs estabilidade - como equilibrar?"

---

## 5. Testes de Validação

### 5.1 Teste de Scaling

```bash
# 1. Estado inicial (1 worker)
docker compose up -d router-worker

# 2. Escalar para 5 workers
docker compose up -d --scale router-worker=5

# 3. Verificar distribuição
docker exec chat4all-kafka kafka-consumer-groups \
    --describe --group router-worker-group \
    --bootstrap-server kafka:9092

# Expected: 5 consumers, cada um com 1 partição
```

### 5.2 Teste de Falha

```bash
# 1. Identificar um worker
WORKER_ID=$(docker ps -q -f name=router-worker | head -1)

# 2. Matar abruptamente
docker kill $WORKER_ID

# 3. Verificar rebalanceamento (10s timeout)
sleep 15
docker exec chat4all-kafka kafka-consumer-groups \
    --describe --group router-worker-group \
    --bootstrap-server kafka:9092

# Expected: partições redistribuídas entre workers restantes
```

---

## 6. Referências Teóricas

- **Kafka Documentation** - *Consumer Group Rebalancing*
- **Amdahl's Law** - Limites de paralelização
- **CAP Theorem** - Trade-offs em sistemas distribuídos
- **Netflix** - *Hystrix: Latency and Fault Tolerance* (Circuit Breaker)
- **Martin Kleppmann** - *Designing Data-Intensive Applications*, Capítulo 11
