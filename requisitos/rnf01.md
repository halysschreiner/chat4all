# RNF01 - Comunicação via Socket

---

## 1. Resumo do Requisito

> **RNF01 - Comunicação via Socket**:
> - Implementação utilizando sockets TCP para comunicação entre componentes.
> - WebSocket para comunicação em tempo real com clientes.

### Dependências com Outros Requisitos

| Requisito | Tipo de Dependência | Descrição |
|-----------|---------------------|-----------|
| **RNF03** | Implementação | gRPC usa HTTP/2 sobre TCP sockets |
| **RF08** | Implementação | WebSocket para notificações em tempo real |
| **RNF02** | Complementar | Concorrência para múltiplas conexões socket |

### Conceito Teórico

Sockets são a **abstração fundamental** de comunicação em rede, operando na camada de transporte (Layer 4) do modelo OSI. O Chat4All utiliza dois tipos:

1. **TCP Sockets**: Conexões confiáveis e ordenadas para gRPC (HTTP/2)
2. **WebSocket**: Protocolo full-duplex sobre TCP para real-time

---

## 2. Fundamentos Teóricos

### 2.1 Modelo OSI e TCP/IP

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

### 2.2 WebSocket vs HTTP

| Característica | HTTP | WebSocket |
|---------------|------|-----------|
| **Direção** | Request-Response | Full-duplex |
| **Conexão** | Nova a cada request | Persistente |
| **Overhead** | Headers em cada request | Handshake único |
| **Use case** | CRUD, API tradicional | Real-time, push notifications |

### 2.3 Upgrade HTTP → WebSocket

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

---

## 3. Implementação

### 3.1 API Gateway - Sockets HTTP/1.1

```php
// api-gateway/public/index.php
// PHP com Apache/Nginx usa sockets gerenciados pelo web server
// Cada request HTTP é uma conexão TCP nova

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');
```

### 3.2 gRPC Clients - Sockets TCP Persistentes

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

**Vantagens do HTTP/2**:
- Multiplexação: múltiplos requests na mesma conexão TCP
- Header compression (HPACK)
- Server push
- Binary framing (vs text HTTP/1.1)

### 3.3 WebSocket Server

```php
// workers/websocket-worker/server.php
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;

$loop = Loop::get();

// Criação do servidor WebSocket
$wsServer = new WsServer($wsHandler);
$httpServer = new HttpServer($wsServer);

// Socket TCP escutando na porta 8081
$socket = new \React\Socket\SocketServer('0.0.0.0:8081', [], $loop);
$server = new IoServer($httpServer, $socket, $loop);

$loop->run();  // Event loop processa conexões
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| TCP Sockets | ✅ | gRPC usa HTTP/2 sobre TCP |
| WebSocket | ✅ | Ratchet em `websocket-worker` |

### 4.2 Pontos Fortes

1. **HTTP/2 para gRPC**: Multiplexação reduz latência
2. **WebSocket persistente**: Elimina overhead de polling
3. **Ratchet maduro**: Biblioteca estável com ReactPHP

### 4.3 Limitações Identificadas

#### Limitação 1: TLS Não Configurado

**Problema**: Conexões em plaintext (`createInsecure()`).

```php
// Produção requer TLS
$authClient = new Auth\AuthServiceClient(
    'api-service:50051',
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]  // ⚠️
);
```

**Solução**:
```php
$credentials = Grpc\ChannelCredentials::createSsl(
    file_get_contents('/certs/ca.pem'),
    file_get_contents('/certs/client.key'),
    file_get_contents('/certs/client.pem')
);
```

#### Limitação 2: WebSocket Sem WSS

**Problema**: WebSocket não usa TLS (wss://).

**Solução**: Proxy reverso com TLS termination (Nginx/Traefik).

---

## 5. Referências Teóricas

- **RFC 793** - Transmission Control Protocol
- **RFC 6455** - The WebSocket Protocol
- **RFC 7540** - HTTP/2
- **Stevens, W. R.** - *Unix Network Programming* (Sockets TCP/IP)
