# RNF01, RNF02, RNF03 - Comunicação, Concorrência e Arquitetura de Microsserviços

---

## 1. Resumo dos Requisitos

### RNF01 - Comunicação via Socket
> - Implementação utilizando sockets TCP para comunicação entre componentes.
> - WebSocket para comunicação em tempo real com clientes.

### RNF02 - Concorrência e Multithreading
> - O servidor deve ser multithreaded ou usar mecanismos assíncronos para gerenciar múltiplas conexões simultâneas.
> - Suporte a múltiplos clientes conectados simultaneamente (testado com pelo menos 5 clientes).

### RNF03 - Arquitetura de Microsserviços
> - Arquitetura baseada em microsserviços com comunicação gRPC.
> - Serviços independentes que podem escalar separadamente.
> - API Gateway como único ponto de entrada (padrão API Gateway Pattern).

### Importância Teórica

Estes três requisitos são **fundamentais** na disciplina de Sistemas Distribuídos:
- **Sockets**: Base de toda comunicação em rede (camada de transporte OSI)
- **Concorrência**: Essencial para throughput em sistemas multi-usuário
- **Microsserviços**: Padrão arquitetural dominante para escalabilidade

---

## 2. Fundamentos Teóricos

### 2.1 Comunicação via Socket (RNF01)

#### Modelo OSI e TCP/IP

```
┌─────────────────────────────────────────────────────────────┐
│  CAMADA 7 - APLICAÇÃO                                       │
│  ┌─────────┐ ┌─────────┐ ┌──────────────┐                  │
│  │  HTTP   │ │ gRPC    │ │  WebSocket   │                  │
│  │  REST   │ │ (HTTP/2)│ │  (upgrade)   │                  │
│  └────┬────┘ └────┬────┘ └──────┬───────┘                  │
│       │           │             │                          │
├───────┼───────────┼─────────────┼──────────────────────────┤
│  CAMADA 4 - TRANSPORTE                                      │
│       └───────────┴─────────────┘                          │
│                   │                                         │
│               TCP SOCKET                                    │
│       (reliable, ordered, connection-oriented)              │
└─────────────────────────────────────────────────────────────┘
```

**Conceitos-chave**:
- **Socket**: Abstração de endpoint de comunicação (IP:porta)
- **TCP**: Garante entrega ordenada e confiável (RFC 793)
- **Three-way Handshake**: SYN → SYN-ACK → ACK

#### WebSocket vs HTTP

| Característica | HTTP | WebSocket |
|---------------|------|-----------|
| **Direção** | Request-Response | Full-duplex |
| **Conexão** | Nova a cada request | Persistente |
| **Overhead** | Headers em cada request | Handshake único |
| **Use case** | CRUD, API tradicional | Real-time, push notifications |

### 2.2 Concorrência (RNF02)

#### Modelos de Concorrência

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
│  2. EVENT LOOP (Assíncrono) - Usado no Chat4All             │
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

#### Event Loop em ReactPHP

O Chat4All usa **ReactPHP** para event loop:

```php
// workers/websocket-worker/server.php - Linha 66
$loop = Loop::get();  // Event loop singleton

// Registra callbacks para I/O non-blocking
$loop->addReadStream($socket, function($socket) {
    // Executado quando há dados para ler
});

$loop->run();  // Bloqueia e processa eventos indefinidamente
```

**Por que Event Loop?**
- PHP é single-threaded por design
- pcntl_fork() é complexo e limitado
- ReactPHP oferece I/O assíncrono elegante

### 2.3 Microsserviços e gRPC (RNF03)

#### Comparação REST vs gRPC

| Aspecto | REST (JSON) | gRPC (Protobuf) |
|---------|-------------|-----------------|
| **Serialização** | Texto (~30% overhead) | Binário (compacto) |
| **Tipagem** | Schema opcional | Schema obrigatório (.proto) |
| **Streaming** | Polling ou WebSocket | Bidirecional nativo |
| **Código** | Manual | Geração automática |
| **Performance** | Baseline | ~10x mais rápido |

#### Protobuf: Serialização Binária

```protobuf
// shared/proto/message.proto
message Message {
    string message_id = 1;      // Tag 1 = 1 byte header
    string conversation_id = 2; // Posição define layout binário
    string content = 3;
    string status = 4;
}
```

**Vantagem sobre JSON**:
```json
{"message_id": "abc123", "content": "Hello"}  // 45 bytes
```
```
// Protobuf: ~20 bytes (tags + valores apenas)
```

---

## 3. Implementação no Chat4All

### 3.1 RNF01 - Sockets TCP e WebSocket

#### API Gateway - Sockets HTTP/1.1 (`api-gateway/public/index.php`)

```php
// NOTA: PHP com Apache/Nginx usa sockets gerenciados pelo web server
// Cada request HTTP é uma conexão TCP nova

// Headers CORS (linhas 9-13)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
```

#### gRPC Clients - Sockets TCP Persistentes (linhas 20-35)

```php
// Conexões gRPC são HTTP/2 sobre TCP - multiplexadas e persistentes
$authClient = new Auth\AuthServiceClient(
    getenv('AUTH_SERVICE_HOST') . ':' . getenv('AUTH_SERVICE_PORT'),
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);

$messageClient = new Message\MessageServiceClient(
    getenv('MESSAGE_SERVICE_HOST') . ':' . getenv('MESSAGE_SERVICE_PORT'),
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);
```

#### WebSocket Server (`workers/websocket-worker/server.php`)

```php
// Linhas 23-31 - Imports do Ratchet
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

// Linha 66 - Event Loop ReactPHP
$loop = Loop::get();

// Criação do servidor WebSocket (estrutura no código)
$wsServer = new WsServer($wsHandler);
$httpServer = new HttpServer($wsServer);
$server = new IoServer($httpServer, $socket, $loop);
```

**Fluxo de Upgrade HTTP → WebSocket**:
```
Cliente                                  Servidor
   │                                        │
   │─── GET /ws HTTP/1.1 ───────────────────▶│
   │    Connection: Upgrade                 │
   │    Upgrade: websocket                  │
   │    Sec-WebSocket-Key: dGhlIHNhbXBsZQ== │
   │                                        │
   │◀── HTTP/1.1 101 Switching Protocols ───│
   │    Upgrade: websocket                  │
   │    Connection: Upgrade                 │
   │    Sec-WebSocket-Accept: s3pPLMBi...   │
   │                                        │
   │═══════ WEBSOCKET FRAME ═══════════════▶│
   │◀══════ WEBSOCKET FRAME ═══════════════│
   │           (full-duplex)                │
```

### 3.2 RNF02 - Concorrência

#### Event Loop em WebSocket Worker

```php
// server.php - Linhas 66-107
$loop = Loop::get();

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

#### Kafka Consumer Workers - Paralelismo via Processos

```php
// workers/router-worker/src/KafkaConsumer.php
// Cada instância do worker é um PROCESSO separado

// Docker Compose permite escalar (docker-compose.yml linhas 161-183)
// container_name comentado para permitir scaling
router-worker:
    build: ...
    # container_name: chat4all-router-worker  # Comentado para scaling
```

**Scaling horizontal**:
```bash
docker compose up --scale router-worker=5
```

#### Gerenciamento de Conexões Concorrentes (`StatusNotificationHandler.php`)

```php
// Linhas 33-40 - Estruturas de dados para N conexões
protected \SplObjectStorage $clients;          // Todas as conexões
protected array $userConnections = [];         // user_id => [connId1, connId2, ...]
protected array $connectionUsers = [];         // connId => user_id

// Linha 63-67 - Quando cliente conecta
public function onOpen(ConnectionInterface $conn)
{
    $this->clients->attach($conn);  // O(1) - HashSet
    $this->logger->info("Nova conexão: {$conn->resourceId}");
}

// Linha 165-200 - Broadcast para múltiplos clientes
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
```

### 3.3 RNF03 - Arquitetura de Microsserviços

#### Diagrama de Componentes

```
┌─────────────────────────────────────────────────────────────┐
│                        CLIENTS                              │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐        │
│  │ Angular │  │  CLI    │  │ Mobile  │  │ Outros  │        │
│  │   SPA   │  │ Client  │  │  App    │  │         │        │
│  └────┬────┘  └────┬────┘  └────┬────┘  └────┬────┘        │
│       │            │            │            │              │
│       └────────────┴────────────┴────────────┘              │
│                        │ HTTP/REST                          │
│                        ▼                                    │
├─────────────────────────────────────────────────────────────┤
│               API GATEWAY (Porta 8000)                      │
│  ┌─────────────────────────────────────────────────┐       │
│  │  • Roteamento REST → gRPC                       │       │
│  │  • CORS handling                                 │       │
│  │  • Rate limiting (futuro)                       │       │
│  │  • Authentication forwarding                    │       │
│  └────────────────────────┬────────────────────────┘       │
│                           │ gRPC (HTTP/2)                   │
│                           ▼                                 │
├─────────────────────────────────────────────────────────────┤
│                    API SERVICE (Porta 50051)                │
│  ┌─────────────────────────────────────────────────┐       │
│  │  AuthService    │ MessageService │ ConversationSvc│     │
│  │  ─────────────  │ ───────────────│ ───────────────│     │
│  │  • Register     │ • SendMessage  │ • CreatePrivate│     │
│  │  • Login        │ • ListMessages │ • CreateGroup  │     │
│  │  • ValidateToken│ • GetMessage   │ • ListConversations  │
│  └────────────────────────────┬────────────────────┘       │
│                               │                             │
│              ┌────────────────┼────────────────┐            │
│              │                │                │            │
│              ▼                ▼                ▼            │
│        ┌──────────┐    ┌──────────┐    ┌──────────┐        │
│        │PostgreSQL│    │  Redis   │    │  MinIO   │        │
│        │  (ACID)  │    │ (Cache)  │    │  (S3)    │        │
│        └──────────┘    └──────────┘    └──────────┘        │
│                               │                             │
├───────────────────────────────┼─────────────────────────────┤
│                          KAFKA                              │
│  ┌─────────────────────────────────────────────────┐       │
│  │  messages    │ whatsapp.messages│ instagram.messages   │
│  │  ───────     │ ─────────────────│ ──────────────────   │
│  │  (5 partições por tópico)                       │       │
│  └────────────────────────┬────────────────────────┘       │
│                           │                                 │
│              ┌────────────┼────────────┐                   │
│              ▼            ▼            ▼                   │
│        ┌──────────┐ ┌──────────┐ ┌──────────┐             │
│        │ Router   │ │ WhatsApp │ │Instagram │             │
│        │ Worker   │ │ Connector│ │Connector │             │
│        │ (1-5x)   │ │  (1-3x)  │ │  (1-3x)  │             │
│        └──────────┘ └──────────┘ └──────────┘             │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

#### Definição dos Serviços gRPC (`shared/proto/`)

**auth.proto**:
```protobuf
service AuthService {
    rpc Register(RegisterRequest) returns (RegisterResponse);
    rpc Login(LoginRequest) returns (LoginResponse);
    rpc ValidateToken(ValidateTokenRequest) returns (ValidateTokenResponse);
}
```

**message.proto**:
```protobuf
service MessageService {
    rpc SendMessage(SendMessageRequest) returns (SendMessageResponse);
    rpc ListMessages(ListMessagesRequest) returns (ListMessagesResponse);
    rpc GetMessageStatus(GetMessageStatusRequest) returns (GetMessageStatusResponse);
}
```

**conversation.proto**:
```protobuf
service ConversationService {
    rpc CreatePrivateConversation(CreatePrivateConversationRequest) returns (CreateConversationResponse);
    rpc CreateGroup(CreateGroupRequest) returns (CreateConversationResponse);
    rpc ListConversations(ListConversationsRequest) returns (ListConversationsResponse);
}
```

#### API Gateway Pattern (`api-gateway/public/index.php`)

```php
// Linhas 42-100 - Router traduz REST → gRPC
switch ($path) {
    case '/v1/auth/register':
        if ($requestMethod === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            // Mapeia JSON para Request Protobuf
            $request = new Auth\RegisterRequest();
            $request->setUsername($data['username'] ?? '');
            $request->setEmail($data['email'] ?? '');
            $request->setPassword($data['password'] ?? '');
            
            // Chama gRPC (HTTP/2, binário, tipado)
            list($response, $status) = $authClient->Register($request)->wait();
            
            // Mapeia Response Protobuf para JSON
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'user' => $response->getUser() ? [...] : null
            ]);
        }
        break;
    
    case '/v1/messages':
        // Outra rota, outro serviço gRPC
        break;
}
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| **RNF01**: TCP Sockets | ✅ | gRPC usa HTTP/2 sobre TCP |
| **RNF01**: WebSocket | ✅ | Ratchet em `websocket-worker` |
| **RNF02**: Multithreading/Async | ✅ | ReactPHP event loop |
| **RNF02**: 5+ clientes | ✅ | `\SplObjectStorage` sem limite |
| **RNF03**: gRPC | ✅ | Protobuf definitions em `shared/proto/` |
| **RNF03**: Serviços independentes | ✅ | Containers Docker separados |
| **RNF03**: API Gateway | ✅ | `api-gateway` porta 8000 |

### 4.2 Pontos Fortes

1. **Event Loop Eficiente**: ReactPHP permite milhares de conexões WebSocket com 1 processo
2. **Protobuf Tipado**: Contratos claros entre serviços, impossível enviar dados inválidos
3. **Gateway Centralizado**: Único ponto de entrada simplifica CORS, auth, logging
4. **Escalabilidade via Kafka**: Workers escalam horizontalmente sem coordenação

### 4.3 Limitações Identificadas

#### Limitação 1: API Gateway Sem Load Balancing

**Problema**: Gateway é single-instance, ponto único de falha.

```yaml
# docker-compose.yml - Linha 75-90
api-gateway:
    container_name: chat4all-gateway  # Nome fixo = 1 instância
    ports:
      - "8000:80"  # Única porta exposta
```

**Solução**:
```yaml
# Com Traefik ou Nginx como load balancer
api-gateway:
    deploy:
      replicas: 3
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.gateway.rule=Host(`api.chat4all.com`)"
```

#### Limitação 2: WebSocket Worker Não Escala

**Problema**: WebSocket mantém estado em memória (`$userConnections`).

```php
// StatusNotificationHandler.php
protected array $userConnections = [];  // Estado local!
```

Se rodar 2 instâncias, usuário conecta em uma, mensagem chega em outra.

**Solução**: Redis Pub/Sub para coordenação:
```php
// Quando mensagem chega (qualquer worker)
$redis->publish('status:user:' . $userId, json_encode($message));

// Cada worker subscreve
$redis->subscribe(['status:user:*'], function($channel, $message) {
    $userId = explode(':', $channel)[2];
    $this->notifyLocalConnections($userId, $message);
});
```

#### Limitação 3: PHP Síncrono no API Gateway

**Problema**: Cada request HTTP bloqueia uma worker thread do Apache/Nginx.

```php
// api-gateway/public/index.php
// Este código roda em PHP-FPM (sync) ou mod_php
// Não há async aqui - cada request = 1 processo
```

**Alternativa**: Swoole ou RoadRunner para PHP assíncrono.

### 4.4 Perguntas Socráticas para Aprofundamento

1. **Sobre Sockets**:
   - "O gRPC usa HTTP/2 sobre TCP. Qual vantagem de multiplexação isso traz versus HTTP/1.1?"
   - "Se a conexão TCP cair no meio de um stream gRPC, como o cliente detecta?"

2. **Sobre Concorrência**:
   - "O event loop é single-threaded. O que acontece se um handler demorar 5s?"
   - "Como você testaria race conditions no `$userConnections`?"

3. **Sobre Microsserviços**:
   - "Qual o custo de adicionar um novo serviço gRPC ao sistema?"
   - "Se o API Gateway cair, o que acontece com requests em andamento?"

---

## 5. Referências Teóricas

- **Stevens, W. R.** - *Unix Network Programming* (Sockets TCP/IP)
- **RFC 793** - Transmission Control Protocol
- **RFC 6455** - The WebSocket Protocol
- **Google** - *gRPC: A high-performance, open source universal RPC framework*
- **ReactPHP** - Event-driven, non-blocking I/O
- **Tanenbaum & Van Steen** - *Distributed Systems: Principles and Paradigms*
