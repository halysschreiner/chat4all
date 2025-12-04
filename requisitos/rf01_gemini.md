# RF01 - Conexão Cliente-Servidor: Análise de Implementação

---

## 1. Resumo do Requisito

### Transcrição do RF01

> **RF01 - Conexão Cliente-Servidor**
> - A arquitetura deve seguir o modelo cliente-servidor.
> - O servidor deve gerenciar as conexões dos clientes, identificar usuários e rotear mensagens/arquivos.
> - O cliente deve permitir ao usuário autenticar-se, iniciar conversas privadas e interagir com grupos.

### Importância no Contexto do Sistema

O RF01 é o **requisito fundacional** do sistema Chat4All. Ele define o modelo de comunicação primário — a arquitetura **Cliente-Servidor** — que sustenta todos os demais requisitos funcionais (RF02-RF10).

Do ponto de vista de **Sistemas Distribuídos**, este requisito aborda diretamente três conceitos fundamentais:

1. **Gerenciamento de Conexões**: O servidor atua como ponto de coordenação central, mantendo estado de sessão de múltiplos clientes simultâneos (**RNF02 - Concorrência**).

2. **Identificação e Autenticação**: Antes de qualquer operação, o cliente deve ser identificado univocamente — condição prévia para garantir **isolamento de sessão** e **controle de acesso** (fundamento para RF02).

3. **Roteamento de Mensagens**: O servidor implementa lógica de **message routing** para entregar mensagens ao destinatário correto (privado ou broadcast em grupo), materializado no `router-worker`.

Em termos do **CAP Theorem**, este requisito estabelece preferência por **Consistência (C)** e **Partition Tolerance (P)**: as mensagens devem chegar ao destinatário correto, mesmo sob particionamento de rede (via Kafka como message broker).

---

## 2. Solução Arquitetural

### 2.1 Arquitetura Adotada: API Gateway + Microservices + Message Broker

O sistema implementa uma **arquitetura de microsserviços** com os seguintes componentes principais:

```
┌─────────────────┐      HTTP/REST       ┌───────────────────┐      gRPC       ┌─────────────────┐
│                 │  ─────────────────▶  │                   │  ─────────────▶ │                 │
│   Frontend      │                      │   API Gateway     │                 │   API Service   │
│   (Angular)     │  ◀─────────────────  │   (PHP/nginx)     │  ◀───────────── │   (PHP gRPC)    │
│                 │      JSON            │   :8000           │                 │   :50051/:8080  │
└────────┬────────┘                      └───────────────────┘                 └────────┬────────┘
         │                                                                              │
         │ WebSocket                                                                    │ Kafka Produce
         │                                                                              ▼
         │                               ┌───────────────────┐                 ┌─────────────────┐
         │                               │                   │                 │                 │
         └─────────────────────────────▶ │ WebSocket Worker  │ ◀─── Redis ◀─── │   Apache Kafka  │
                                         │   (Ratchet PHP)   │     Pub/Sub     │   (Broker)      │
                                         │   :8081           │                 │   :9093         │
                                         └───────────────────┘                 └────────┬────────┘
                                                                                        │
                                                                               Kafka Consume
                                                                                        ▼
                                                                               ┌─────────────────┐
                                                                               │  Router Worker  │
                                                                               │  (Kafka Consumer│
                                                                               │   + Platform    │
                                                                               │    Routing)     │
                                                                               └─────────────────┘
```

### 2.2 Padrões Arquiteturais Implementados

| Padrão | Componente | Justificativa |
|--------|------------|---------------|
| **API Gateway Pattern** | `api-gateway/` | Único ponto de entrada; traduz REST → gRPC; simplifica CORS e autenticação |
| **Backend for Frontend (BFF)** | Gateway expondo endpoints JSON | Frontend Angular consome JSON; gRPC é interno |
| **Service Mesh (simplificado)** | Docker Network `chat4all-network` | Comunicação interna via DNS de containers |
| **Event-Driven Architecture** | Apache Kafka | Desacoplamento produtor/consumidor; escalabilidade horizontal |
| **Pub/Sub** | Redis + WebSocket Worker | Notificações em tempo real para clientes conectados |

### 2.3 Justificativa das Escolhas

| Critério | Análise |
|----------|---------|
| **Escalabilidade** | Microsserviços permitem escalar componentes independentemente. O `docker-compose.yml` permite `docker-compose up --scale router-worker=5`. Kafka particiona por `conversation_id`, distribuindo carga. |
| **Manutenibilidade** | Separação clara de responsabilidades: API Gateway (roteamento HTTP), API Service (lógica de negócio), Workers (processamento assíncrono). |
| **Performance** | gRPC (Protocol Buffers) reduz **latência de serialização** em ~10x comparado a JSON. WebSocket evita **polling overhead** para notificações. |
| **Complexidade** | Moderada. A introdução de Kafka adiciona complexidade operacional, mas é justificada pela garantia de **at-least-once delivery** e tolerância a falhas. |

### 2.4 Alternativas Arquiteturais Descartadas

#### Alternativa 1: Monolito com WebSocket Integrado

**Descrição**: Um único servidor PHP gerenciando HTTP, WebSocket e processamento de mensagens.

**Trade-offs**:
| Vantagem | Desvantagem |
|----------|-------------|
| Menor latência interna (sem hops de rede) | **Single Point of Failure** |
| Deploy simplificado | Impossível escalar componentes independentemente |
| Menor consumo de memória base | **Thread contention** em PHP (modelo share-nothing) |

**Motivo da Rejeição**: Viola diretamente **RNF06 (Escalabilidade Horizontal)** e **RNF07 (Tolerância a Falhas)**. O modelo **share-nothing** do PHP dificulta compartilhamento de estado de conexões WebSocket em múltiplas instâncias.

#### Alternativa 2: Arquitetura P2P (Peer-to-Peer)

**Descrição**: Clientes se comunicam diretamente, servidor apenas para discovery e NAT traversal (como WebRTC Data Channels).

**Trade-offs**:
| Vantagem | Desvantagem |
|----------|-------------|
| Menor carga no servidor | Impossível garantir **auditoria centralizada** (RF10) |
| Latência potencialmente menor | **NAT traversal** complexo (STUN/TURN) |
| Descentralizado | Impossível implementar **controle de status** centralizado (RF06) |

**Motivo da Rejeição**: Inviabiliza os requisitos de auditoria (RF10) e controle de status (RF06). O modelo P2P transfere responsabilidade de delivery para os clientes, violando a garantia de entrega exigida.

#### Alternativa 3: REST Puro (Polling)

**Descrição**: Clientes fazem requisições HTTP periódicas para buscar novas mensagens.

**Trade-offs**:
| Vantagem | Desvantagem |
|----------|-------------|
| Simplicidade máxima | **Latência proporcional ao intervalo de polling** |
| Stateless no servidor | **Overhead de requisições** (até 99% redundantes) |
| Cache HTTP tradicional | Viola "tempo real" exigido em RF03 e RF08 |

**Motivo da Rejeição**: O requisito RF03 exige que "o receptor receba em tempo real a mensagem". Polling introduz latência artificial (tipicamente 1-5 segundos) e sobrecarga de rede inaceitável para um sistema de chat.

---

## 3. Stack Tecnológica

### 3.1 Mapeamento de Tecnologias

| Camada | Tecnologia | Versão | Papel no RF01 |
|--------|------------|--------|---------------|
| Frontend | Angular | 17 | Cliente SPA; gerencia autenticação e UI de chat |
| API Gateway | PHP + nginx | 8.3 | Traduz REST → gRPC; valida JWT |
| RPC Framework | gRPC | - | Comunicação interna de baixa latência |
| Backend | PHP (Slim-like) | 8.3 | Lógica de negócio; persiste mensagens |
| WebSocket | Ratchet (PHP) | - | Conexões full-duplex para notificações |
| Message Broker | Apache Kafka | 7.5.0 | Filas de mensagens; tolerância a falhas |
| Database | PostgreSQL | 16 | Persistência relacional (ACID) |
| Cache | Redis | 7 | Sessões JWT; Pub/Sub para WebSocket |

### 3.2 Análise Detalhada por Tecnologia

#### **gRPC (Google Remote Procedure Call)**

**Por que foi escolhida**:
- **Serialização binária** (Protocol Buffers): ~10x mais rápida que JSON
- **Contrato tipado**: Arquivos `.proto` garantem compatibilidade cliente-servidor
- **HTTP/2**: Multiplexação de streams; menor overhead de conexão
- **Streaming bidirecional**: Preparado para futuras funcionalidades

**Trade-offs**:
| Ganho | Limitação |
|-------|-----------|
| Latência de serialização ~10x menor | Debugging mais complexo (binário) |
| Validação de tipos em compile-time | Curva de aprendizado (protobuf) |
| Backward/forward compatibility | Não funciona diretamente em browsers |

**Alternativas consideradas**:
- **REST/JSON**: Descartado para comunicação interna; mantido no Gateway para compatibilidade com frontend
- **GraphQL**: Overhead de parsing desnecessário para chamadas internas
- **Apache Thrift**: Menor ecossistema PHP comparado a gRPC

**Evidência no código** (`shared/proto/auth.proto`, linhas 1-30):
```protobuf
syntax = "proto3";
package auth;

service AuthService {
  rpc Register(RegisterRequest) returns (RegisterResponse);
  rpc Login(LoginRequest) returns (LoginResponse);
  rpc ValidateToken(ValidateTokenRequest) returns (ValidateTokenResponse);
}
```

#### **Apache Kafka**

**Por que foi escolhida**:
- **Garantia de entrega**: at-least-once delivery (RF07)
- **Particionamento**: Mensagens particionadas por `conversation_id` mantêm ordem
- **Consumer Groups**: Escala horizontal automática com rebalanceamento
- **Durabilidade**: Mensagens persistidas em disco; replay possível

**Trade-offs**:
| Ganho | Limitação |
|-------|-----------|
| Tolerância a falhas de consumers | Complexidade operacional (Zookeeper) |
| Throughput massivo (milhões msg/s) | Latência ligeiramente maior que in-memory |
| Replay de eventos para debugging | Curva de aprendizado |

**Alternativas consideradas**:
- **RabbitMQ**: Menor throughput; modelo de filas (não log)
- **Redis Streams**: Menos maduro; sem garantias de durabilidade comparáveis
- **Amazon SQS**: Vendor lock-in; latência WAN

**Evidência no código** (`services/api-service/src/Service/KafkaProducer.php`, linhas 47-58):
```php
public function publish(array $message, ?string $key = null): void
{
    $payload = json_encode($message);
    // RD_KAFKA_PARTITION_UA = particionamento automático baseado na key
    $this->topic->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);
    $this->producer->poll(0);
}
```

#### **Ratchet (PHP WebSocket)**

**Por que foi escolhida**:
- **Nativo PHP**: Consistência de stack (toda equipe conhece PHP)
- **Event Loop**: Baseado em ReactPHP; non-blocking I/O
- **Integração simples**: Funciona com JWT existente

**Trade-offs**:
| Ganho | Limitação |
|-------|-----------|
| Curva de aprendizado zero para devs PHP | Menor performance que Go/Rust |
| Ecosystem unificado | Modelo single-thread (event loop) |

**Alternativas consideradas**:
- **Socket.io (Node.js)**: Exigiria runtime adicional
- **Go (gorilla/websocket)**: Performance superior, mas stack heterogêneo
- **Pusher/Ably**: Custo operacional; vendor lock-in

---

## 4. Implementação

### 4.1 Mapeamento de Diretórios e Arquivos

```
chat4all/
├── api-gateway/
│   └── public/index.php          # Ponto de entrada REST → gRPC
├── services/api-service/
│   └── src/
│       ├── server.php            # Servidor gRPC principal
│       ├── Grpc/
│       │   ├── AuthService.php   # Autenticação e JWT
│       │   ├── MessageService.php # Envio/listagem de mensagens
│       │   └── ConversationService.php # Gerenciamento de conversas
│       ├── Database/
│       │   └── Database.php      # Camada de persistência
│       └── Service/
│           └── KafkaProducer.php # Publicação de eventos
├── workers/
│   ├── router-worker/
│   │   ├── consumer.php          # Entry point do worker
│   │   └── src/
│   │       ├── KafkaConsumer.php # Consumidor Kafka
│   │       └── MessageProcessor.php # Lógica de roteamento
│   └── websocket-worker/
│       ├── server.php            # Servidor WebSocket
│       └── src/
│           └── StatusNotificationHandler.php # Gerencia conexões WS
├── shared/proto/
│   ├── auth.proto                # Contrato de autenticação
│   ├── message.proto             # Contrato de mensagens
│   └── conversation.proto        # Contrato de conversas
├── frontend/src/app/
│   └── app.component.ts          # Cliente Angular (autenticação + chat)
└── scripts/
    └── init-db.sql               # Schema PostgreSQL
```

### 4.2 Componentes Críticos

#### 4.2.1 API Gateway - Ponto de Entrada (`api-gateway/public/index.php`)

**Responsabilidade**: Traduzir requisições REST do frontend para chamadas gRPC internas.

**Trechos relevantes** (linhas 21-35):
```php
// Clients gRPC
$authClient = new Auth\AuthServiceClient(
    getenv('AUTH_SERVICE_HOST') . ':' . getenv('AUTH_SERVICE_PORT'),
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);

$messageClient = new Message\MessageServiceClient(
    getenv('MESSAGE_SERVICE_HOST') . ':' . getenv('MESSAGE_SERVICE_PORT'),
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);

$conversationClient = new Conversation\ConversationServiceClient(
    getenv('CONVERSATION_SERVICE_HOST') . ':' . getenv('CONVERSATION_SERVICE_PORT'),
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);
```

**Conceito teórico**: O Gateway implementa o **Facade Pattern** (GoF), fornecendo interface unificada para o subsistema de microsserviços. O uso de `ChannelCredentials::createInsecure()` indica que a comunicação intra-cluster confia no isolamento de rede Docker (em produção, usar mTLS).

**Linhas 91-117 - Autenticação via gRPC**:
```php
case '/v1/auth/login':
    if ($requestMethod === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $request = new Auth\LoginRequest();
        $request->setEmail($data['email'] ?? '');
        $request->setPhone($data['phone'] ?? '');
        $request->setPassword($data['password'] ?? '');
        
        list($response, $status) = $authClient->Login($request)->wait();
        // ...
    }
```

#### 4.2.2 Servidor gRPC (`services/api-service/src/server.php`)

**Responsabilidade**: Servidor gRPC que gerencia conexões e despacha chamadas para os serviços.

**Trechos relevantes** (linhas 35-44):
```php
$service = new MessageService($db, $kafka, $logger);
$authService = new AuthService($db, $logger, $jwtSecret);
$conversationService = new ConversationService($db, $logger);

$server = new Server();
$server->addHttp2Port('0.0.0.0:50051');
$server->start();

$logger->info("Starting gRPC server on 0.0.0.0:50051");
```

**Conceito teórico**: O servidor implementa o **Dispatcher Pattern**, mapeando métodos gRPC para handlers específicos. O loop em linhas 44-139 demonstra o modelo **request-response** síncrono do gRPC:

```php
while (true) {
    $event = $server->requestCall();
    // ... parse method e dispatch para serviço correto
    $response = $service->SendMessage($request);
    $call->startBatch([
        Grpc\OP_SEND_MESSAGE => ['message' => $responsePayload],
        Grpc\OP_SEND_STATUS_FROM_SERVER => [...]
    ]);
}
```

#### 4.2.3 AuthService - Identificação de Usuários (`services/api-service/src/Grpc/AuthService.php`)

**Responsabilidade**: Autenticar usuários e emitir tokens JWT.

**Trechos relevantes** (linhas 74-111):
```php
public function Login(LoginRequest $request): LoginResponse
{
    $response = new LoginResponse();
    
    try {
        $identifier = $email ?: $phone;
        $user = $this->database->getUserByEmailOrPhone($identifier);
        
        if (!$user || !password_verify($password, $user['password_hash'])) {
            throw new \Exception("Invalid credentials");
        }
        
        // Generate JWT
        $payload = [
            'iss' => 'chat4all-api',
            'sub' => $user['user_id'],
            'iat' => time(),
            'exp' => time() + 3600, // 1 hour
            'username' => $user['username'],
            'email' => $user['email']
        ];
        
        $token = JWT::encode($payload, $this->jwtSecret, 'HS256');
        // ...
    }
}
```

**Conceito teórico**: 
- **JWT (RFC 7519)**: Token auto-contido que evita lookup de sessão no servidor (stateless)
- **password_verify**: Comparação segura contra timing attacks (constant-time comparison)
- **HS256**: HMAC-SHA256 para assinatura simétrica; adequado para arquitetura single-issuer

#### 4.2.4 MessageService - Roteamento de Mensagens (`services/api-service/src/Grpc/MessageService.php`)

**Responsabilidade**: Persistir mensagens e publicar no Kafka para processamento assíncrono.

**Trechos relevantes** (linhas 37-80):
```php
public function SendMessage(SendMessageRequest $request): SendMessageResponse
{
    $messageId = Uuid::uuid4()->toString();
    
    $payload = [
        'message_id' => $messageId,
        'conversation_id' => $conversationId,
        'from_user_id' => $fromUserId,
        'content' => $content,
        'status' => 'SENT',
        'created_at' => $timestamp
    ];
    
    // Save to database first
    $this->database->insertMessage($payload);
    
    // Send to Kafka
    $this->kafkaProducer->publish(
        $payload,
        $conversationId // Key (partition by conversation)
    );
    // ...
}
```

**Conceito teórico**:
- **UUID v4**: Identificador universalmente único, evita colisões em sistemas distribuídos
- **Outbox Pattern (implícito)**: Persiste primeiro, depois publica no Kafka; garante consistência
- **Partition Key = conversation_id**: Mensagens da mesma conversa vão para a mesma partição, garantindo **ordenação FIFO** dentro da conversa

#### 4.2.5 WebSocket Handler - Notificações em Tempo Real (`workers/websocket-worker/src/StatusNotificationHandler.php`)

**Responsabilidade**: Manter conexões WebSocket e autenticar via JWT.

**Trechos relevantes** (linhas 71-87):
```php
public function onOpen(ConnectionInterface $conn): void
{
    $this->connections->attach($conn);

    $this->logger->info('Nova conexão WebSocket', [
        'resourceId' => $conn->resourceId,
        'totalConnections' => $this->connections->count(),
    ]);

    // Enviar mensagem solicitando autenticação
    $conn->send(json_encode([
        'type' => 'auth_required',
        'message' => 'Envie seu token JWT para autenticação',
    ]));
}
```

**Linhas 210-232 - Autenticação WebSocket**:
```php
protected function handleAuth(ConnectionInterface $conn, array $data): void
{
    $decoded = JWT::decode(
        $data['token'],
        new Key($this->config['jwt_secret'], 'HS256')
    );

    $userId = $decoded->sub ?? $decoded->user_id ?? null;

    // Registrar conexão para o usuário
    $resourceId = $conn->resourceId;
    $this->connectionUsers[$resourceId] = $userId;

    if (!isset($this->userConnections[$userId])) {
        $this->userConnections[$userId] = [];
    }
    $this->userConnections[$userId][] = $resourceId;
}
```

**Conceito teórico**:
- **Full-duplex Communication (RFC 6455)**: WebSocket permite servidor "push" sem request do cliente
- **Connection Multiplexing**: `SplObjectStorage` mapeia múltiplas conexões por usuário (multi-device)
- **Stateful**: Diferente do HTTP, o servidor mantém estado de conexão — trade-off necessário para notificações em tempo real

---

## 5. Análise Crítica

### 5.1 Avaliação de Conformidade

| Sub-requisito RF01 | Status | Evidência |
|--------------------|--------|-----------|
| Arquitetura cliente-servidor | ✅ **Atendido** | Frontend Angular (cliente) + API Gateway/Services (servidor) |
| Gerenciar conexões dos clientes | ✅ **Atendido** | WebSocket Worker com `SplObjectStorage` para tracking |
| Identificar usuários | ✅ **Atendido** | JWT com `user_id` no payload; validação em cada request |
| Rotear mensagens | ✅ **Atendido** | Kafka particionado por `conversation_id` + router-worker |
| Rotear arquivos | ✅ **Atendido** | Campo `file_id` em mensagens; MinIO para storage (RF05) |
| Cliente: autenticar-se | ✅ **Atendido** | `/v1/auth/login` retorna JWT; frontend persiste em localStorage |
| Cliente: iniciar conversas privadas | ✅ **Atendido** | `/v1/conversations/private` cria conversa entre dois usuários |
| Cliente: interagir com grupos | ✅ **Atendido** | `CreateGroup` em ConversationService; type='group' |

### 5.2 Limitações Identificadas

#### Limitação 1: gRPC Credentials Inseguras

**Problema**: O API Gateway usa `ChannelCredentials::createInsecure()`, sem criptografia TLS na comunicação interna.

**Impacto**: Em ambientes compartilhados ou multi-tenant, tráfego gRPC pode ser interceptado (violação de confidencialidade).

**Recomendação**: Implementar mTLS (mutual TLS) para comunicação intra-cluster:
```php
$credentials = Grpc\ChannelCredentials::createSsl(
    file_get_contents('/certs/ca.pem'),
    file_get_contents('/certs/client.key'),
    file_get_contents('/certs/client.pem')
);
```

#### Limitação 2: Ausência de Rate Limiting

**Problema**: Não há mecanismo de **rate limiting** no API Gateway ou WebSocket Worker.

**Impacto**: Vulnerabilidade a ataques de **Denial of Service (DoS)** ou abuso de recursos.

**Recomendação**: Implementar rate limiting por IP/user_id usando Redis:
```php
$rateLimiter = new SlidingWindowRateLimiter($redis, 'user:' . $userId, 100, 60);
if (!$rateLimiter->attempt()) {
    return new Response(429, 'Too Many Requests');
}
```

#### Limitação 3: Kafka Consumer Single-Partition

**Problema**: O `KafkaConsumer.php` consome apenas da partição 0 (`$this->topic->consumeStart(0, RD_KAFKA_OFFSET_STORED)`).

**Evidência** (`workers/router-worker/src/KafkaConsumer.php`, linha 109):
```php
$this->topic->consumeStart(0, RD_KAFKA_OFFSET_STORED);
```

**Impacto**: Apenas 1/5 das mensagens são consumidas (tópico tem 5 partições conforme RNF04). Isso **viola a escalabilidade horizontal**.

**Recomendação**: Migrar para High-Level Consumer API (KafkaConsumerGroup) para auto-assignment de partições:
```php
$conf->set('group.id', $groupId);
$consumer = new \RdKafka\KafkaConsumer($conf);
$consumer->subscribe(['messages']);
```

#### Limitação 4: WebSocket sem Horizontal Scaling

**Problema**: O WebSocket Worker mantém conexões em memória local (`$this->userConnections`). Múltiplas instâncias não compartilham estado.

**Impacto**: Se `user_A` conecta na instância 1 e uma notificação é gerada na instância 2, `user_A` não recebe.

**Recomendação**: Usar Redis Pub/Sub como "backplane" entre instâncias:
```php
// Ao receber evento de status:
$redis->publish('ws:broadcast', json_encode(['user_id' => $userId, 'event' => $event]));

// Em cada instância WebSocket:
$redis->subscribe(['ws:broadcast'], function($message) {
    // Verificar se user está conectado localmente e enviar
});
```

### 5.3 Sugestões de Otimização

1. **Connection Pooling para PostgreSQL**: Usar PgBouncer para reduzir overhead de conexões.

2. **Compressão gRPC**: Habilitar compressão gzip para reduzir bandwidth:
   ```php
   $options['grpc.default_compression_algorithm'] = GRPC_COMPRESS_GZIP;
   ```

3. **Batch Processing no Router Worker**: Consumir mensagens em lotes para aumentar throughput:
   ```php
   $messages = $consumer->consumeBatch(100, 1000); // 100 msgs, 1s timeout
   foreach ($messages as $msg) { ... }
   ```

4. **Health Checks Ativos**: Implementar `/healthz` e `/readyz` endpoints seguindo padrão Kubernetes para auto-healing.

---

## Referências Teóricas

- **RFC 6455**: The WebSocket Protocol
- **RFC 7519**: JSON Web Token (JWT)
- **Gamma et al.**: Design Patterns (Facade, Observer)
- **Martin Fowler**: Patterns of Enterprise Application Architecture (Gateway, Event Sourcing)
- **Brewer (2000)**: CAP Theorem - Consistency, Availability, Partition Tolerance
- **Kafka Documentation**: Consumer Groups and Partition Assignment
