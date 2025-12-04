# RNF02 - Concorrência e Multithreading

---

## 1. Resumo do Requisito

> **RNF02 - Concorrência e Multithreading**:
> - O servidor deve ser multithreaded ou usar mecanismos assíncronos para gerenciar múltiplas conexões simultâneas.
> - Suporte a múltiplos clientes conectados simultaneamente (testado com pelo menos 5 clientes).

### Dependências com Outros Requisitos

| Requisito | Tipo de Dependência | Descrição |
|-----------|---------------------|-----------|
| **RNF01** | Implementação | Concorrência para gerenciar múltiplos sockets |
| **RF08** | Uso | WebSocket Worker gerencia N conexões simultâneas |
| **RNF06** | Complementar | Escalabilidade horizontal complementa concorrência |

### Conceito Teórico

Concorrência é **essencial** para sistemas multi-usuário. O Chat4All adota o modelo **Event Loop** (assíncrono) ao invés de thread-per-connection, permitindo milhares de conexões com recursos limitados.

---

## 2. Fundamentos Teóricos

### 2.1 Modelos de Concorrência

```
┌─────────────────────────────────────────────────────────────┐
│                  MODELOS DE CONCORRÊNCIA                    │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  1. THREAD PER CONNECTION (Tradicional)                     │
│     ┌────┐ ┌────┐ ┌────┐                                   │
│     │ T1 │ │ T2 │ │ T3 │  ... (N threads)                  │
│     └──┬─┘ └──┬─┘ └──┬─┘                                   │
│        │      │      │                                      │
│     Cliente1 Cliente2 Cliente3                              │
│     ⚠️ Problema: 10.000 conexões = 10.000 threads           │
│                                                             │
│  2. EVENT LOOP (Assíncrono) - Usado no Chat4All ✅          │
│     ┌─────────────────────────────────────┐                 │
│     │          Event Loop                  │                │
│     │  ┌─────────────────────────────┐    │                │
│     │  │ Callback Queue              │    │                │
│     │  │ [read_ready, write_ready,   │    │                │
│     │  │  timer_expired, ...]        │    │                │
│     │  └─────────────────────────────┘    │                │
│     └─────────────────────────────────────┘                │
│     ✅ Vantagem: 1 thread gerencia N conexões              │
│                                                             │
│  3. WORKER POOL + MESSAGE QUEUE                             │
│     ┌────────────┐    ┌──────────────────┐                 │
│     │   Kafka    │───▶│ Worker Pool      │                 │
│     │   Queue    │    │ [W1, W2, W3...]  │                 │
│     └────────────┘    └──────────────────┘                 │
│     ✅ Vantagem: Scaling horizontal                        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Por que Event Loop?

| Característica | Thread-per-Connection | Event Loop |
|----------------|----------------------|------------|
| **Memória** | ~1MB/thread | ~1KB/conexão |
| **Context Switch** | Frequente, custoso | Inexistente |
| **10k conexões** | 10GB RAM, impossível | ~10MB, trivial |
| **Complexidade** | Race conditions, locks | Callback hell (mitigável) |

**PHP e Event Loop**: PHP é single-threaded por design. ReactPHP oferece I/O assíncrono elegante, permitindo o padrão event loop.

---

## 3. Implementação

### 3.1 Event Loop em WebSocket Worker

```php
// server.php
$loop = Loop::get();  // Event loop singleton

// Handler de WebSocket (concurrent connections)
$wsHandler = new StatusNotificationHandler($logger, $config);

// Redis Subscriber em loop separado
$redisSubscriber = new RedisSubscriber(
    $config['redis_host'],
    $config['redis_port'],
    $wsHandler,
    $logger,
    $loop
);

// Tudo roda no mesmo event loop
$server = IoServer::factory(
    new HttpServer(new WsServer($wsHandler)),
    $config['websocket_port'],
    '0.0.0.0',
    $loop
);

$logger->info('WebSocket server running on port ' . $config['websocket_port']);
$loop->run();  // Blocking - processa eventos indefinidamente
```

### 3.2 Gerenciamento de Conexões Concorrentes

```php
// StatusNotificationHandler.php
class StatusNotificationHandler implements MessageComponentInterface
{
    // Estruturas de dados para N conexões
    protected \SplObjectStorage $clients;          // Todas as conexões
    protected array $userConnections = [];         // user_id => [connId1, connId2, ...]
    protected array $connectionUsers = [];         // connId => user_id

    public function onOpen(ConnectionInterface $conn)
    {
        $this->clients->attach($conn);  // O(1) - HashSet
        $this->logger->info("Nova conexão: {$conn->resourceId}");
    }

    // Broadcast para múltiplos clientes
    public function notifyUser(string $userId, array $message): void
    {
        if (!isset($this->userConnections[$userId])) {
            return;  // Usuário não conectado
        }
        
        $payload = json_encode($message);
        
        // Notifica TODAS as conexões do usuário (multi-device)
        foreach ($this->userConnections[$userId] as $connId) {
            foreach ($this->clients as $client) {
                if ($client->resourceId === $connId) {
                    $client->send($payload);  // Non-blocking
                    break;
                }
            }
        }
    }
}
```

### 3.3 Kafka Workers - Paralelismo via Processos

```php
// workers/router-worker/src/KafkaConsumer.php
// Cada instância do worker é um PROCESSO separado
// Docker Compose permite escalar:
// docker compose up --scale router-worker=5
```

**Scaling horizontal via processos**:
```bash
docker compose up --scale router-worker=5
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| Multithreading/Async | ✅ | ReactPHP event loop |
| 5+ clientes | ✅ | `\SplObjectStorage` sem limite |

### 4.2 Pontos Fortes

1. **Event Loop Eficiente**: ReactPHP permite milhares de conexões WebSocket com 1 processo
2. **SplObjectStorage**: HashSet O(1) para gerenciamento de conexões
3. **Non-blocking I/O**: Operações não bloqueiam o loop

### 4.3 Limitações Identificadas

#### Limitação 1: Handler Bloqueante Trava Todo o Sistema

**Problema**: Se um callback demorar, todas as conexões ficam paradas.

```php
public function onMessage(ConnectionInterface $from, $msg)
{
    sleep(5);  // ⚠️ BLOQUEIA TODAS AS CONEXÕES!
    $from->send('done');
}
```

**Solução**: Operações lentas devem ser assíncronas:
```php
$loop->addTimer(0, function() use ($from) {
    // Operação lenta em callback
    $this->processAsync($from);
});
```

#### Limitação 2: PHP Síncrono no API Gateway

**Problema**: API Gateway usa PHP-FPM tradicional (síncrono).

**Impacto**: Cada request = 1 processo PHP-FPM.

**Solução**: Swoole ou RoadRunner para PHP assíncrono no gateway.

---

## 5. Testes

### 5.1 Teste de Múltiplos Clientes

```bash
# Conectar 5+ clientes WebSocket
for i in {1..10}; do
  websocat ws://localhost:8081 &
done

# Verificar conexões ativas
docker logs websocket-worker 2>&1 | grep "Nova conexão"
```

---

## 6. Referências Teóricas

- **ReactPHP** - Event-driven, non-blocking I/O
- **C10K Problem** - Kegel, Dan (1999)
- **The Node.js Event Loop** - Modelo similar ao ReactPHP
- **libuv** - Biblioteca de event loop (usada pelo Node.js)
