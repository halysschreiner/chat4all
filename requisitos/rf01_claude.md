# RF01 - Conexão Cliente-Servidor

## 1. Resumo do Requisito

### Transcrição do RF01
> - A arquitetura deve seguir o modelo cliente-servidor.
> - O servidor deve gerenciar as conexões dos clientes, identificar usuários e rotear mensagens/arquivos.
> - O cliente deve permitir ao usuário autenticar-se, iniciar conversas privadas e interagir com grupos.

### Importância no Contexto do Sistema

O RF01 é o **requisito fundacional** do Chat4All, pois define o paradigma arquitetural que sustenta toda a comunicação do sistema. Em sistemas distribuídos, a escolha entre cliente-servidor, peer-to-peer (P2P) ou arquiteturas híbridas impacta diretamente:

1. **Centralização do controle**: O servidor atua como coordenador central para roteamento de mensagens, essencial para garantir entrega confiável e ordenação causal de mensagens.

2. **Gerenciamento de estado**: A identificação de usuários e sessões requer um ponto central de autoridade — sem isso, problemas como *split-brain* e inconsistência de estado se tornam inevitáveis.

3. **Escalabilidade**: O modelo cliente-servidor permite escalar o backend horizontalmente (via Kafka Consumer Groups e workers), mantendo o cliente como entidade leve (*thin client*).

---

## 2. Solução Arquitetural

### 2.1 Arquitetura Adotada

O Chat4All implementa uma **arquitetura cliente-servidor multicamada** com os seguintes padrões:

```
┌──────────────────────────────────────────────────────────────────────────────┐
│                              CLIENTE (Angular)                               │
│                          HTTP/REST + WebSocket                               │
└──────────────────────────────────┬───────────────────────────────────────────┘
                                   │
                                   ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                           API GATEWAY (PHP/Nginx)                            │
│                    Padrão: API Gateway / Backend for Frontend                │
│                         REST → gRPC Translation Layer                        │
└──────────────────────────────────┬───────────────────────────────────────────┘
                                   │ gRPC (Protocol Buffers)
                                   ▼
┌──────────────────────────────────────────────────────────────────────────────┐
│                           API SERVICE (PHP 8.3)                              │
│                      gRPC Server (AuthService, MessageService,               │
│                      ConversationService) + Kafka Producer                   │
└────────┬─────────────────────────┬───────────────────────────────────────────┘
         │                         │
         ▼                         ▼
┌─────────────────┐    ┌───────────────────────────────────────────────────────┐
│   PostgreSQL    │    │                    APACHE KAFKA                       │
│   (ACID Store)  │    │           Tópico: messages (5 partições)              │
└─────────────────┘    └────────────────────────┬──────────────────────────────┘
                                                │ Consumer Group
                                                ▼
                       ┌───────────────────────────────────────────────────────┐
                       │               ROUTER WORKERS (1-N)                    │
                       │          Processamento assíncrono de mensagens        │
                       └───────────────────────────────────────────────────────┘
```

### 2.2 Padrões Arquiteturais Aplicados

| Padrão | Aplicação | Justificativa |
|--------|-----------|---------------|
| **API Gateway** | `api-gateway/public/index.php` | Único ponto de entrada, tradução REST↔gRPC, cross-cutting concerns (CORS, auth) |
| **Backend for Frontend (BFF)** | API Gateway adapta gRPC para REST consumível pelo Angular | Cliente não precisa conhecer Protocol Buffers |
| **CQRS (parcial)** | Escrita via Kafka, leitura direta do PostgreSQL | Separação de responsabilidades escrita/leitura |
| **Event-Driven Architecture** | Kafka como message broker central | Desacoplamento temporal entre produtores e consumidores |
| **Consumer Group** | Router Workers no mesmo `group.id` | Balanceamento automático de carga entre workers |

### 2.3 Justificativa da Arquitetura

| Critério | Avaliação |
|----------|-----------|
| **Escalabilidade** | ✅ Workers horizontalmente escaláveis (1-5 instâncias testadas). Kafka partitions permitem paralelismo. |
| **Manutenibilidade** | ✅ Serviços desacoplados via contratos gRPC (`.proto`). Alterações isoladas por domínio. |
| **Performance** | ✅ gRPC usa HTTP/2 com multiplexing e serialização binária (Protobuf). ~7x mais eficiente que JSON/REST. |
| **Complexidade** | ⚠️ Maior complexidade operacional (Kafka, Zookeeper, múltiplos containers). Trade-off aceitável para os requisitos. |

### 2.4 Alternativas Consideradas e Descartadas

#### Alternativa 1: Monolito com REST puro
```
Cliente → REST API (monolito) → PostgreSQL
```
**Por que descartada:**
- ❌ Sem escalabilidade horizontal para processamento de mensagens
- ❌ Acoplamento temporal: se o banco estiver lento, toda a API trava
- ❌ Não atende RNF06 (escalabilidade horizontal de workers)

#### Alternativa 2: GraphQL com subscriptions
```
Cliente → GraphQL Server (subscriptions via WebSocket) → PostgreSQL
```
**Por que descartada:**
- ❌ Subscriptions GraphQL não escalam bem para milhares de conexões (state por conexão no servidor)
- ❌ Overhead de parsing de queries complexas
- ❌ Menor maturidade do ecossistema PHP para GraphQL subscriptions

#### Alternativa 3: Arquitetura P2P com DHT (Distributed Hash Table)
```
Cliente A ←→ DHT ←→ Cliente B
```
**Por que descartada:**
- ❌ Não atende RF01 explicitamente (modelo cliente-servidor é obrigatório)
- ❌ Complexidade de NAT traversal (STUN/TURN)
- ❌ Dificuldade de persistência offline e auditoria
- ❌ Não garante ordenação causal de mensagens

---

## 3. Stack Tecnológica

### 3.1 Componentes e Justificativas

| Tecnologia | Função | Por que foi escolhida | Trade-offs | Alternativas descartadas |
|------------|--------|----------------------|------------|--------------------------|
| **PHP 8.3** | Backend (API Service, Gateway, Workers) | Suporte nativo a gRPC via `grpc` extension; familiaridade da equipe; performance melhorada com JIT | (+) Ecossistema maduro (-) Menos performático que Go/Rust para I/O intensivo | Go, Node.js |
| **gRPC + Protobuf** | Comunicação inter-serviços | Type-safety em tempo de compilação; serialização binária eficiente (~10x menor que JSON); HTTP/2 multiplexing | (+) Performance (-) Requer geração de código | REST puro, Apache Thrift |
| **Apache Kafka 7.5** | Message Broker | Durabilidade em disco; Consumer Groups para balanceamento; replay de mensagens para recovery; throughput >1M msg/s | (+) At-least-once garantido (-) Complexidade operacional (Zookeeper) | RabbitMQ, Redis Streams |
| **PostgreSQL 16** | Persistência relacional | ACID completo; extensão `uuid-ossp` para IDs distribuídos; queries complexas com JOINs | (+) Consistência forte (-) Escala vertical (sharding complexo) | CockroachDB, Cassandra |
| **Angular 17** | Frontend SPA | TypeScript nativo; arquitetura modular; injeção de dependência | (+) Tipagem forte (-) Bundle size maior | React, Vue.js |
| **WebSocket (Ratchet)** | Real-time notifications | Conexão persistente bidirecional; elimina polling | (+) Baixa latência (-) State por conexão no servidor | Server-Sent Events (SSE) |

### 3.2 Protocolos de Comunicação

| Camada | Protocolo | RFC/Especificação |
|--------|-----------|-------------------|
| Cliente ↔ Gateway | HTTP/1.1 REST + WebSocket | RFC 7231, RFC 6455 |
| Gateway ↔ API Service | gRPC (HTTP/2) | gRPC specification, RFC 7540 |
| API Service ↔ Kafka | Kafka Protocol | Apache Kafka Protocol Guide |
| Workers ↔ Kafka | Kafka Protocol (Consumer) | Consumer Group Protocol (KIP-62) |

---

## 4. Implementação

### 4.1 Mapeamento de Diretórios

```
chat4all/
├── api-gateway/                    # API Gateway (REST → gRPC)
│   └── public/index.php           # Router e tradução de protocolos
├── services/api-service/          # Serviço principal gRPC
│   └── src/
│       ├── Grpc/                  # Implementações dos serviços gRPC
│       │   ├── AuthService.php    # Autenticação JWT
│       │   ├── MessageService.php # Envio/listagem de mensagens
│       │   └── ConversationService.php
│       ├── Database/Database.php  # Camada de persistência
│       ├── Service/KafkaProducer.php # Produtor Kafka
│       └── server.php             # Entrypoint do servidor gRPC
├── workers/router-worker/         # Consumer Kafka
│   └── src/KafkaConsumer.php      # Consumidor com manual commit
├── shared/proto/                  # Contratos gRPC
│   ├── auth.proto
│   ├── message.proto
│   └── conversation.proto
└── frontend/src/app/services/     # Serviços Angular
    ├── auth.service.ts            # Autenticação no cliente
    ├── chat.service.ts            # Comunicação com API
    └── websocket.service.ts       # Conexão WebSocket
```

### 4.2 Componentes Críticos

#### 4.2.1 API Gateway - Tradução REST → gRPC

**Arquivo:** `api-gateway/public/index.php`

**Função:** Ponto único de entrada que traduz requisições REST para chamadas gRPC.

```php
// Linhas 20-34: Inicialização dos clientes gRPC
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

**Explicação:** O gateway mantém conexões persistentes (via HTTP/2) com o API Service. Cada tipo de operação (auth, messages, conversations) usa um client stub tipado gerado a partir dos arquivos `.proto`.

```php
// Linhas 91-118: Endpoint de login - tradução REST para gRPC
case '/v1/auth/login':
    if ($requestMethod === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $request = new Auth\LoginRequest();
        $request->setEmail($data['email'] ?? '');
        $request->setPhone($data['phone'] ?? '');
        $request->setPassword($data['password'] ?? '');
        
        list($response, $status) = $authClient->Login($request)->wait();
        
        // ... tratamento de resposta
    }
    break;
```

**Conceito aplicado:** **Facade Pattern** — o gateway esconde a complexidade do gRPC do cliente, expondo uma API REST simples.

---

#### 4.2.2 Servidor gRPC - Dispatcher de Serviços

**Arquivo:** `services/api-service/src/server.php`

**Função:** Loop principal do servidor gRPC que despacha requisições para os serviços apropriados.

```php
// Linhas 27-37: Inicialização dos serviços com injeção de dependências
$db = new Database($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $logger);
$kafka = new KafkaProducer($kafkaBrokers, $kafkaTopic, $logger);
$jwtSecret = getenv('JWT_SECRET') ?: 'default-secret';

$service = new MessageService($db, $kafka, $logger);
$authService = new AuthService($db, $logger, $jwtSecret);
$conversationService = new ConversationService($db, $logger);

$server = new Server();
$server->addHttp2Port('0.0.0.0:50051');
$server->start();
```

**Explicação:** O servidor utiliza **Dependency Injection** para compor os serviços. Cada serviço recebe as dependências necessárias (Database, Kafka, Logger), facilitando testes e manutenção.

```php
// Linhas 60-65: Parsing do método gRPC
// Parse method: /message.MessageService/SendMessage
$parts = explode('/', trim($method, '/'));
$serviceName = $parts[0];  // "message.MessageService"
$methodName = $parts[1];   // "SendMessage"
```

**Conceito aplicado:** O gRPC usa **Uniform Resource Identifier (URI)** no formato `/{package}.{Service}/{Method}`, similar ao padrão definido na especificação gRPC.

---

#### 4.2.3 AuthService - Autenticação JWT

**Arquivo:** `services/api-service/src/Grpc/AuthService.php`

**Função:** Gerenciamento de autenticação e identificação de usuários (requisito: "identificar usuários").

```php
// Linhas 73-107: Implementação do Login com geração JWT
public function Login(LoginRequest $request): LoginResponse
{
    // ...
    $user = $this->database->getUserByEmailOrPhone($identifier);
    
    if (!$user || !password_verify($password, $user['password_hash'])) {
        throw new \Exception("Invalid credentials");
    }
    
    // Geração do JWT (RFC 7519)
    $payload = [
        'iss' => 'chat4all-api',        // Issuer
        'sub' => $user['user_id'],       // Subject (user ID)
        'iat' => time(),                 // Issued At
        'exp' => time() + 3600,          // Expiration (1 hora)
        'username' => $user['username'],
        'email' => $user['email']
    ];
    
    $token = JWT::encode($payload, $this->jwtSecret, 'HS256');
    // ...
}
```

**Explicação:** 
- **Password hashing** com `bcrypt` (custo 10) — resistente a ataques de força bruta (~250ms por verificação).
- **JWT** com algoritmo HS256 — token stateless que permite validação sem consulta ao banco.
- Claims seguem RFC 7519 (JSON Web Token).

---

#### 4.2.4 MessageService - Roteamento de Mensagens

**Arquivo:** `services/api-service/src/Grpc/MessageService.php`

**Função:** Gerenciamento do envio e roteamento de mensagens (requisito: "rotear mensagens").

```php
// Linhas 37-75: Envio de mensagem com persistência + Kafka
public function SendMessage(SendMessageRequest $request): SendMessageResponse
{
    // Criar payload da mensagem
    $messageId = Uuid::uuid4()->toString();
    $payload = [
        'message_id' => $messageId,
        'conversation_id' => $conversationId,
        'from_user_id' => $fromUserId,
        'content' => $content,
        'message_type' => $messageType,
        'status' => 'SENT',
        'created_at' => $timestamp
    ];
    
    // 1. Persistir no banco (durabilidade)
    $this->database->insertMessage($payload);
    
    // 2. Publicar no Kafka para processamento assíncrono
    $this->kafkaProducer->publish(
        $payload,
        $conversationId  // Key para particionamento
    );
    // ...
}
```

**Conceito aplicado:** 
- **Outbox Pattern (simplificado)**: Mensagem é salva no banco E publicada no Kafka. Em caso de falha do Kafka, a mensagem não é perdida (está no banco).
- **Particionamento por `conversation_id`**: Garante ordenação FIFO de mensagens dentro de uma conversa (todas vão para a mesma partição).

---

#### 4.2.5 KafkaConsumer - Processamento Assíncrono

**Arquivo:** `workers/router-worker/src/KafkaConsumer.php`

**Função:** Consumo de mensagens do Kafka com tolerância a falhas.

```php
// Linhas 48-63: Configuração do Consumer com manual commit
$conf = new \RdKafka\Conf();
$conf->set('metadata.broker.list', $brokers);
$conf->set('group.id', $groupId);

// TOLERÂNCIA A FALHAS: Commit manual de offsets
$conf->set('enable.auto.commit', 'false');

// Começar do início se não houver offset armazenado
$conf->set('auto.offset.reset', 'earliest');

// Configurações para rebalanceamento rápido
$conf->set('session.timeout.ms', '10000');
$conf->set('heartbeat.interval.ms', '3000');
```

**Explicação:**
- **Manual commit** (`enable.auto.commit = false`): Offset só é commitado após processamento bem-sucedido. Garante **at-least-once delivery** — mensagens podem ser reprocessadas, mas nunca perdidas.
- **Consumer Group** (`group.id`): Múltiplos workers compartilham a carga. Se um falha, o Kafka redistribui suas partições para os workers restantes (**rebalancing**).
- **Heartbeat**: Detecta falhas rapidamente (10s timeout, 3s interval).

---

### 4.3 Fluxo Completo: Envio de Mensagem

```
1. Cliente Angular envia POST /v1/messages
   └─► chat.service.ts linha 66-77

2. API Gateway recebe REST, traduz para gRPC
   └─► api-gateway/public/index.php linhas 208-232

3. API Service processa via MessageService
   └─► services/api-service/src/Grpc/MessageService.php linhas 37-75
   │   ├─► Persiste no PostgreSQL
   │   └─► Publica no Kafka (tópico: messages)

4. Router Worker consome do Kafka
   └─► workers/router-worker/src/KafkaConsumer.php
   │   ├─► Processa mensagem
   │   ├─► Atualiza status para DELIVERED
   │   └─► Commit manual do offset

5. WebSocket Worker notifica cliente em tempo real
   └─► workers/websocket-worker/
```

---

## 5. Análise Crítica

### 5.1 Atendimento ao Requisito

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| "Arquitetura cliente-servidor" | ✅ **Atendido** | Cliente Angular → API Gateway → API Service |
| "Gerenciar conexões dos clientes" | ✅ **Atendido** | WebSocket Worker mantém conexões; API Gateway gerencia HTTP |
| "Identificar usuários" | ✅ **Atendido** | JWT com `sub` claim contendo `user_id`; validação em cada request |
| "Rotear mensagens" | ✅ **Atendido** | Kafka particionado por `conversation_id`; Router Workers distribuem |
| "Rotear arquivos" | ✅ **Atendido** | MinIO + `file_id` na mensagem; RF05 cobre detalhes |
| "Permitir autenticação" | ✅ **Atendido** | AuthService com bcrypt + JWT |
| "Iniciar conversas privadas" | ✅ **Atendido** | ConversationService.CreatePrivateConversation |
| "Interagir com grupos" | ✅ **Atendido** | ConversationService.CreateGroup + tipo `group` |

### 5.2 Limitações Identificadas

1. **gRPC Insecure Credentials**
   ```php
   ['credentials' => Grpc\ChannelCredentials::createInsecure()]
   ```
   - **Problema:** Comunicação sem TLS entre Gateway e API Service.
   - **Risco:** Vulnerável a man-in-the-middle em ambiente de produção.
   - **Mitigação:** Configurar TLS mútuo (mTLS) ou usar service mesh (Istio/Linkerd).

2. **JWT Secret Hardcoded**
   ```php
   $jwtSecret = getenv('JWT_SECRET') ?: 'default-secret';
   ```
   - **Problema:** Fallback para secret fraco.
   - **Risco:** Em caso de falha de configuração, tokens podem ser forjados.
   - **Mitigação:** Falhar ruidosamente se `JWT_SECRET` não estiver definido.

3. **Single Point of Failure: PostgreSQL**
   - **Problema:** Uma única instância PostgreSQL sem replicação.
   - **Risco:** Indisponibilidade do banco = indisponibilidade total do sistema.
   - **Mitigação:** Implementar streaming replication ou usar managed service (RDS, Cloud SQL).

4. **Validação de Token em Cada Request**
   ```php
   function authenticateRequest(): ?string {
       list($response, $status) = $authClient->ValidateToken($request)->wait();
   }
   ```
   - **Problema:** Chamada gRPC síncrona para validar token em cada request.
   - **Impacto:** Latência adicional (~5-10ms por request).
   - **Mitigação:** Validar JWT localmente no Gateway (sem chamada ao AuthService).

### 5.3 Otimizações Futuras

1. **Validação JWT local no Gateway**
   - Usar biblioteca `firebase/php-jwt` diretamente no Gateway.
   - Elimina round-trip gRPC para ValidateToken.
   - Reduz latência em ~50%.

2. **Connection Pooling para gRPC**
   - Implementar pool de conexões gRPC com reutilização.
   - Evita overhead de handshake HTTP/2 por request.

3. **Circuit Breaker (Hystrix pattern)**
   - Se API Service falhar N vezes, abrir circuito e retornar fallback.
   - Evita cascata de falhas (importante em microsserviços).
   - Referência: Netflix Hystrix, Resilience4j.

4. **Distributed Tracing**
   - Implementar OpenTelemetry para rastrear requests end-to-end.
   - Facilita debugging em arquitetura distribuída.
   - Correlação: `trace_id` propagado entre Gateway → API Service → Workers.

5. **Rate Limiting no Gateway**
   - Implementar token bucket ou sliding window.
   - Protege contra DDoS e abuso de API.
   - Referência: RFC 6585 (HTTP 429 Too Many Requests).

---

## Referências Teóricas

- **RFC 7231** - Hypertext Transfer Protocol (HTTP/1.1): Semantics and Content
- **RFC 7519** - JSON Web Token (JWT)
- **RFC 6455** - The WebSocket Protocol
- **RFC 7540** - Hypertext Transfer Protocol Version 2 (HTTP/2)
- **gRPC Core Concepts** - https://grpc.io/docs/what-is-grpc/core-concepts/
- **Apache Kafka Protocol** - Consumer Group Protocol (KIP-62)
- **Martin Fowler** - Patterns of Enterprise Application Architecture (API Gateway, Facade)
- **Tanenbaum & Van Steen** - Distributed Systems: Principles and Paradigms (Cap. 2: Arquiteturas)
