# Chat4All - Fault Tolerance Documentation

## Conceito de Sistemas Distribuídos

A **tolerância a falhas** é a capacidade de um sistema distribuído continuar operando corretamente mesmo quando alguns de seus componentes falham. O Chat4All implementa diversos mecanismos para garantir que mensagens não sejam perdidas e que o sistema se recupere automaticamente de falhas.

## Visão Geral da Arquitetura de Tolerância a Falhas

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Tolerância a Falhas Chat4All                        │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                             │
│   ┌────────────┐      ┌─────────────┐      ┌────────────────┐              │
│   │   API      │──────▶│   Kafka     │──────▶│  Router Worker │              │
│   │ (Producer) │      │  (Broker)   │      │  (Consumer)    │              │
│   └────────────┘      └─────────────┘      └────────────────┘              │
│         │                    │                     │                        │
│         │                    │                     │                        │
│   ┌─────▼─────┐        ┌────▼────┐          ┌─────▼──────┐                 │
│   │ PostgreSQL│        │Partition│          │ Manual     │                 │
│   │(Durável)  │        │Replicação│         │ Commit     │                 │
│   └───────────┘        └─────────┘          └────────────┘                 │
│                                                                             │
│   Mecanismos de Tolerância:                                                │
│   ✓ At-least-once delivery (Kafka manual commit)                           │
│   ✓ Consumer group rebalancing                                             │
│   ✓ Graceful shutdown handlers                                              │
│   ✓ Docker restart policies                                                 │
│   ✓ Session timeout configurável                                            │
└─────────────────────────────────────────────────────────────────────────────┘
```

## 1. Kafka Manual Commit

### Por que não usar Auto-Commit?

O auto-commit do Kafka marca mensagens como processadas automaticamente em intervalos regulares, independentemente de o processamento ter sido concluído. Isso pode causar:

- **Perda de mensagens**: Se o worker falhar antes de processar, mas após o auto-commit
- **Inconsistência**: Mensagens marcadas como processadas, mas que nunca foram

### Implementação Manual Commit

```php
// workers/router-worker/src/KafkaConsumer.php

// DESABILITAR auto-commit
$conf->set('enable.auto.commit', 'false');

// No loop de consumo:
switch ($message->err) {
    case RD_KAFKA_RESP_ERR_NO_ERROR:
        try {
            // 1. Processar mensagem
            $this->processor->process($message->payload);
            
            // 2. SÓ commitar após sucesso
            $this->topic->offsetStore($message->partition, $message->offset);
            $this->logger->debug('Offset committed after successful processing');
            
        } catch (\Exception $e) {
            // 3. NÃO commitar em caso de erro
            // Mensagem será reprocessada automaticamente
            $this->logger->error('Failed to process - offset NOT committed');
        }
        break;
}
```

### Benefícios

1. **At-least-once delivery**: Mensagens podem ser processadas mais de uma vez, mas nunca são perdidas
2. **Recuperação automática**: Após reinício, o worker continua de onde parou
3. **Consistência**: O offset só avança quando o processamento é confirmado

## 2. Consumer Group Rebalancing

### Como Funciona

Quando workers no mesmo Consumer Group falham ou são adicionados, o Kafka redistribui automaticamente as partições entre os membros restantes.

```
Estado Inicial (3 workers, 3 partições):
┌─────────────┐  ┌─────────────┐  ┌─────────────┐
│  Worker 1   │  │  Worker 2   │  │  Worker 3   │
│ Partition 0 │  │ Partition 1 │  │ Partition 2 │
└─────────────┘  └─────────────┘  └─────────────┘

Após falha do Worker 2 (rebalanceamento):
┌─────────────┐                  ┌─────────────┐
│  Worker 1   │                  │  Worker 3   │
│ Partition 0 │                  │ Partition 1 │
│             │                  │ Partition 2 │
└─────────────┘                  └─────────────┘
```

### Configuração para Rebalanceamento Rápido

```php
// Timeout de sessão: tempo antes de considerar consumer morto
$conf->set('session.timeout.ms', '10000');  // 10 segundos

// Frequência de heartbeat para o coordinator
$conf->set('heartbeat.interval.ms', '3000');  // 3 segundos

// Tempo máximo entre polls antes de rebalanceamento
$conf->set('max.poll.interval.ms', '300000');  // 5 minutos
```

## 3. Graceful Shutdown

### Handlers de Sinal

O sistema registra handlers para sinais do sistema operacional que permitem encerramento gracioso:

```php
// Registrar handlers para shutdown graceful
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () {
        $this->logger->info('Received SIGTERM, initiating graceful shutdown...');
        $this->shutdown = true;
    });
    pcntl_signal(SIGINT, function () {
        $this->logger->info('Received SIGINT, initiating graceful shutdown...');
        $this->shutdown = true;
    });
}

// No loop de consumo:
while (!$this->shutdown) {
    // Processar signals pendentes
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }
    
    // ... consumir e processar mensagens
}

// Após sair do loop:
$consumer->close();
$this->logger->info('Consumer closed gracefully');
```

### Benefícios

1. **Mensagens em processamento são concluídas** antes do encerramento
2. **Offsets são commitados** corretamente
3. **Recursos são liberados** de forma limpa
4. **Rebalanceamento é mais rápido** pois o broker é notificado

## 4. Docker Restart Policies

### Configuração no docker-compose.yml

```yaml
services:
  router-worker:
    build: ./workers/router-worker
    restart: always  # Reinicia automaticamente após falha
    environment:
      - KAFKA_GROUP_ID=router-worker-group
    depends_on:
      - kafka
      - postgres
    deploy:
      resources:
        limits:
          memory: 512M
        reservations:
          memory: 256M

  whatsapp-mock:
    build: ./connectors/whatsapp-mock
    restart: always
    environment:
      - KAFKA_BROKER=kafka:9092
```

### Políticas de Restart Disponíveis

| Política | Comportamento |
|----------|---------------|
| `no` | Não reinicia automaticamente |
| `always` | Sempre reinicia, a menos que parado manualmente |
| `on-failure` | Reinicia apenas em caso de erro (exit code != 0) |
| `unless-stopped` | Reinicia exceto se parado manualmente |

## 5. Cenários de Falha e Recuperação

### Cenário 1: Falha de Worker Individual

```
1. Worker 2 falha durante processamento
2. Mensagem em processamento NÃO foi commitada
3. Docker detecta falha e reinicia o container
4. Kafka detecta timeout de sessão (10s)
5. Partições são redistribuídas
6. Worker reiniciado volta ao Consumer Group
7. Mensagem não commitada é reprocessada
8. Sistema continua operando normalmente

Tempo de recuperação: ~15-30 segundos
Mensagens perdidas: 0
```

### Cenário 2: Falha de Múltiplos Workers

```
1. Workers 1 e 2 falham simultaneamente
2. Kafka redistribui partições para Worker 3
3. Docker reinicia containers
4. Workers retornam ao Consumer Group
5. Partições são redistribuídas novamente

Impacto: Throughput reduzido temporariamente
Mensagens perdidas: 0
```

### Cenário 3: Falha do Kafka Broker

```
1. Kafka broker falha
2. Producers recebem erro de conexão
3. API retorna erro 503 para clientes
4. Kafka cluster elege novo líder (se cluster)
5. Conexões são reestabelecidas
6. Mensagens pendentes são enviadas

Requisito: Kafka em modo cluster (3+ brokers) para HA
```

## 6. Monitoramento de Falhas

### Métricas Importantes

| Métrica | Descrição | Alerta |
|---------|-----------|--------|
| `kafka_consumer_lag` | Mensagens pendentes | > 1000 |
| `container_restart_count` | Reinícios de container | > 3/hora |
| `kafka_rebalance_count` | Rebalanceamentos | > 5/hora |
| `message_processing_errors` | Erros de processamento | > 1% |

### Logs de Diagnóstico

```bash
# Ver logs de rebalanceamento
docker logs router-worker 2>&1 | grep -i "rebalance\|shutdown\|commit"

# Ver erros de processamento
docker logs router-worker 2>&1 | grep -i "error\|failed\|NOT committed"

# Ver status do consumer group
docker exec kafka kafka-consumer-groups.sh \
  --bootstrap-server localhost:9092 \
  --describe --group router-worker-group
```

## 7. Testando Tolerância a Falhas

### Script de Teste

```bash
# Executar testes de failover
./finalTest/scripts/test-failover.sh

# Opções disponíveis:
./finalTest/scripts/test-failover.sh --api-url http://localhost:8080
./finalTest/scripts/test-failover.sh --timeout 60
```

### Cenários de Teste Automatizados

1. **Single Worker Failure**: Mata um worker e verifica recuperação
2. **Connector Failure**: Mata connector e verifica callbacks
3. **Multiple Failures**: Mata vários componentes simultaneamente
4. **Consumer Rebalance**: Escala workers e mata um para testar rebalanceamento

### Teste Manual

```bash
# 1. Iniciar sistema
docker-compose up -d

# 2. Enviar mensagens
curl -X POST http://localhost:8080/v1/messages \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer $TOKEN" \
  -d '{"conversation_id": "123", "content": "Test", "type": "text"}'

# 3. Matar worker durante processamento
docker kill $(docker ps -qf "name=router-worker")

# 4. Verificar que Docker reinicia o container
docker ps -a | grep router-worker

# 5. Verificar que mensagem foi processada após reinício
docker logs router-worker | tail -20
```

## 8. Melhores Práticas

### Para Desenvolvedores

1. **Sempre use manual commit** para operações críticas
2. **Implemente idempotência** nas operações de processamento
3. **Configure timeouts adequados** para o ambiente
4. **Use structured logging** para facilitar diagnóstico

### Para Operações

1. **Monitore consumer lag** constantemente
2. **Configure alertas** para reinícios frequentes
3. **Mantenha Kafka em cluster** para alta disponibilidade
4. **Faça backup** regular do offset store

### Para Testes

1. **Execute testes de failover** antes de deploys
2. **Simule falhas em staging** antes de produção
3. **Documente tempos de recuperação** esperados
4. **Valide idempotência** das operações

## 9. Limitações Conhecidas

### At-Least-Once vs Exactly-Once

O Chat4All implementa **at-least-once delivery**, o que significa que em caso de falha, uma mensagem pode ser processada mais de uma vez. Para sistemas que requerem **exactly-once**, é necessário:

1. Implementar idempotência nas operações
2. Usar transações Kafka (Kafka Transactions)
3. Implementar deduplicação baseada em ID

### Tempo de Recuperação

O tempo de recuperação depende de:
- `session.timeout.ms`: Tempo para detectar falha
- Tempo de reinício do container
- Tempo de reconexão ao Kafka

**Tempo típico**: 15-30 segundos

### Dependência do Kafka

Se o cluster Kafka falhar completamente, o sistema não consegue processar mensagens. Recomendações:
- Usar 3+ brokers com replicação
- Configurar `min.insync.replicas=2`
- Implementar circuit breaker na API

## 10. Referências

- [Kafka Consumer Group Rebalance](https://kafka.apache.org/documentation/#consumerconfigs)
- [Docker Restart Policies](https://docs.docker.com/compose/compose-file/compose-file-v3/#restart)
- [PHP PCNTL Extension](https://www.php.net/manual/en/book.pcntl.php)
- [Trabalho Final - Escalabilidade e Relatório (UFG)](../Trabalho%20Final%20-%20Escalabilidade%20e%20Relatório.md)
