# RNF03 - Arquitetura de Microsserviços

---

## 1. Resumo do Requisito

> **RNF03 - Arquitetura de Microsserviços**:
> - Arquitetura baseada em microsserviços com comunicação gRPC.
> - Serviços independentes que podem escalar separadamente.
> - API Gateway como único ponto de entrada (padrão API Gateway Pattern).

### Dependências com Outros Requisitos

| Requisito | Tipo de Dependência | Descrição |
|-----------|---------------------|-----------|
| **RNF01** | Infraestrutura | gRPC usa TCP sockets |
| **RNF06** | Complementar | Microsserviços habilitam escalabilidade horizontal |
| **RNF04** | Integração | Kafka para comunicação assíncrona entre serviços |
| **RNF10** | Implementação | Cada microsserviço é um container Docker |

### Conceito Teórico

Microsserviços são um **estilo arquitetural** onde a aplicação é composta por serviços pequenos, independentemente deployáveis, comunicando via protocolos leves. O Chat4All implementa:

- **gRPC**: Comunicação síncrona eficiente (Protobuf binário)
- **API Gateway Pattern**: Ponto único de entrada para clientes
- **Decomposição por domínio**: AuthService, MessageService, ConversationService

---

## 2. Fundamentos Teóricos

### 2.1 Comparação REST vs gRPC

| Aspecto | REST (JSON) | gRPC (Protobuf) |
|---------|-------------|-----------------|
| **Serialização** | Texto (~30% overhead) | Binário (compacto) |
| **Tipagem** | Schema opcional | Schema obrigatório (.proto) |
| **Streaming** | Polling ou WebSocket | Bidirecional nativo |
| **Código** | Manual | Geração automática |
| **Performance** | Baseline | ~10x mais rápido |

### 2.2 Protobuf: Serialização Binária

```protobuf
// shared/proto/message.proto
message Message {
    string message_id = 1;      // Tag 1 = 1 byte header
    string conversation_id = 2; // Posição define layout binário
    string content = 3;
    string status = 4;
}
```

**Comparação de tamanho**:
```json
{"message_id": "abc123", "content": "Hello"}  // JSON: ~45 bytes
```
```
// Protobuf: ~20 bytes (tags + valores apenas)
```

### 2.3 API Gateway Pattern

```
┌─────────────────────────────────────────────────────────────┐
│                     CLIENTS (externos)                       │
│  Angular SPA │ Mobile App │ CLI │ Third-party               │
└──────────────────────────┬──────────────────────────────────┘
                           │ HTTP/REST (JSON)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    API GATEWAY (:8000)                       │
│  • Roteamento REST → gRPC                                   │
│  • CORS handling                                            │
│  • Rate limiting (futuro)                                   │
│  • Authentication forwarding                                 │
└──────────────────────────┬──────────────────────────────────┘
                           │ gRPC (HTTP/2, binário)
                           ▼
┌─────────────────────────────────────────────────────────────┐
│                    MICROSERVICES                             │
│  ┌─────────────┐  ┌───────────────┐  ┌─────────────────┐   │
│  │ AuthService │  │ MessageService│  │ConversationSvc  │   │
│  └─────────────┘  └───────────────┘  └─────────────────┘   │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Implementação

### 3.1 Diagrama de Componentes

```
┌─────────────────────────────────────────────────────────────┐
│                        CLIENTS                              │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌─────────┐        │
│  │ Angular │  │  CLI    │  │ Mobile  │  │ Outros  │        │
│  │   SPA   │  │ Client  │  │  App    │  │         │        │
│  └────┬────┘  └────┬────┘  └────┬────┘  └────┬────┘        │
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
└─────────────────────────────────────────────────────────────┘
```

### 3.2 Definição dos Serviços gRPC

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

### 3.3 API Gateway Pattern

```php
// api-gateway/public/index.php
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
| gRPC | ✅ | Protobuf definitions em `shared/proto/` |
| Serviços independentes | ✅ | Containers Docker separados |
| API Gateway | ✅ | `api-gateway` porta 8000 |

### 4.2 Pontos Fortes

1. **Protobuf Tipado**: Contratos claros entre serviços, impossível enviar dados inválidos
2. **Gateway Centralizado**: Único ponto de entrada simplifica CORS, auth, logging
3. **Escalabilidade por serviço**: Escalar apenas MessageService se necessário

### 4.3 Limitações Identificadas

#### Limitação 1: API Gateway Single-Instance

**Problema**: Gateway é ponto único de falha.

```yaml
# docker-compose.yml
api-gateway:
    container_name: chat4all-gateway  # Nome fixo = 1 instância
    ports:
      - "8000:80"  # Única porta exposta
```

**Solução**:
```yaml
# Com Traefik como load balancer
api-gateway:
    deploy:
      replicas: 3
    labels:
      - "traefik.enable=true"
      - "traefik.http.routers.gateway.rule=Host(`api.chat4all.com`)"
```

#### Limitação 2: PHP Síncrono no Gateway

**Problema**: Cada request HTTP bloqueia uma worker thread.

**Impacto**: Throughput limitado por workers PHP-FPM.

**Alternativa**: Swoole ou RoadRunner para PHP assíncrono.

---

## 5. Referências Teóricas

- **Newman, Sam** - *Building Microservices* (2015)
- **Google** - *gRPC: A high-performance, open source universal RPC framework*
- **Richardson, Chris** - *Microservices Patterns* (API Gateway Pattern)
- **Fowler, Martin** - *Microservices* (martinfowler.com)
