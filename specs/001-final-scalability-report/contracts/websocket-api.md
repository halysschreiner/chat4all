# WebSocket API: Chat4All Real-Time Notifications

**Protocol**: WebSocket (RFC 6455)  
**Server**: `ws://localhost:8081/ws`  
**Authentication**: JWT token via query parameter

## Conceitos de Sistemas Distribuídos Demonstrados

- **Push Notifications**: Servidor envia atualizações sem polling
- **Pub/Sub Pattern**: Clientes assinam conversas específicas
- **Stateful Connections**: Servidor mantém estado de conexões ativas
- **Scalability**: Múltiplos WebSocket workers via Redis Pub/Sub

## Connection

### URL Format

```
ws://localhost:8081/ws?token={jwt_token}
```

### Connection Flow

```
Client                          Server
   |                               |
   |-- WebSocket Handshake ------->|
   |                               | Validate JWT
   |<-- Connection Accepted -------|
   |                               |
   |-- Subscribe Message --------->| Add to conversation channels
   |<-- Subscribe Confirmation ----|
   |                               |
   |<-- Status Updates ------------|  (ongoing)
   |<-- New Messages --------------|  (ongoing)
   |                               |
   |-- Ping --------------------->|
   |<-- Pong ---------------------|
   |                               |
   |-- Close -------------------->|
   |<-- Close Confirmation -------|
```

## Message Types

### Client → Server

#### Subscribe to Conversations

Subscribe to receive updates for specific conversations.

```json
{
  "type": "subscribe",
  "payload": {
    "conversationIds": ["uuid-1", "uuid-2", "uuid-3"]
  }
}
```

**Response**:
```json
{
  "type": "subscribed",
  "payload": {
    "conversationIds": ["uuid-1", "uuid-2", "uuid-3"],
    "timestamp": "2025-11-29T10:00:00Z"
  }
}
```

#### Unsubscribe from Conversations

```json
{
  "type": "unsubscribe",
  "payload": {
    "conversationIds": ["uuid-1"]
  }
}
```

#### Ping (Keep-Alive)

```json
{
  "type": "ping",
  "payload": {
    "timestamp": "2025-11-29T10:00:00Z"
  }
}
```

**Response**:
```json
{
  "type": "pong",
  "payload": {
    "timestamp": "2025-11-29T10:00:00Z"
  }
}
```

### Server → Client

#### Message Status Update

Sent when a message status changes (SENT → DELIVERED → READ).

```json
{
  "type": "status_update",
  "payload": {
    "messageId": "uuid",
    "conversationId": "uuid",
    "oldStatus": "SENT",
    "newStatus": "DELIVERED",
    "updatedAt": "2025-11-29T10:00:05Z",
    "platform": "whatsapp"
  }
}
```

#### New Message Notification

Sent when a new message is received in a subscribed conversation.

```json
{
  "type": "new_message",
  "payload": {
    "messageId": "uuid",
    "conversationId": "uuid",
    "fromUserId": "uuid",
    "fromUsername": "João",
    "messageType": "text",
    "content": "Olá!",
    "fileId": null,
    "createdAt": "2025-11-29T10:00:00Z"
  }
}
```

#### Typing Indicator (Optional)

```json
{
  "type": "typing",
  "payload": {
    "conversationId": "uuid",
    "userId": "uuid",
    "username": "João",
    "isTyping": true
  }
}
```

#### Error Message

```json
{
  "type": "error",
  "payload": {
    "code": "UNAUTHORIZED",
    "message": "Token expired",
    "timestamp": "2025-11-29T10:00:00Z"
  }
}
```

## Error Codes

| Code | Description | Action |
|------|-------------|--------|
| `UNAUTHORIZED` | Token inválido ou expirado | Reconectar com novo token |
| `INVALID_MESSAGE` | Formato de mensagem inválido | Corrigir formato |
| `CONVERSATION_NOT_FOUND` | Conversa não existe | Verificar ID |
| `NOT_MEMBER` | Usuário não é membro da conversa | Verificar permissão |
| `RATE_LIMIT` | Muitas mensagens por segundo | Reduzir frequência |

## Connection States

```
                    ┌─────────────────┐
                    │   CONNECTING    │
                    └────────┬────────┘
                             │ onopen
                             ▼
                    ┌─────────────────┐
             ┌─────>│   CONNECTED     │<─────┐
             │      └────────┬────────┘      │
             │               │               │
       reconnect          onclose        onerror
             │               │               │
             │      ┌────────▼────────┐      │
             └──────│  DISCONNECTED   │──────┘
                    └─────────────────┘
```

## Angular Client Example

```typescript
// websocket.service.ts

import { Injectable } from '@angular/core';
import { webSocket, WebSocketSubject } from 'rxjs/webSocket';
import { Observable, timer, Subject } from 'rxjs';
import { retryWhen, delay, takeUntil } from 'rxjs/operators';

export interface StatusUpdate {
  messageId: string;
  conversationId: string;
  oldStatus: string;
  newStatus: string;
  updatedAt: string;
  platform: string;
}

@Injectable({ providedIn: 'root' })
export class WebSocketService {
  private socket$: WebSocketSubject<any> | null = null;
  private destroy$ = new Subject<void>();
  private reconnectInterval = 3000;

  connect(token: string): Observable<any> {
    if (!this.socket$ || this.socket$.closed) {
      this.socket$ = webSocket({
        url: `ws://localhost:8081/ws?token=${token}`,
        openObserver: {
          next: () => console.log('[WebSocket] Connected')
        },
        closeObserver: {
          next: () => console.log('[WebSocket] Disconnected')
        }
      });
    }

    return this.socket$.pipe(
      retryWhen(errors =>
        errors.pipe(
          delay(this.reconnectInterval),
          takeUntil(this.destroy$)
        )
      )
    );
  }

  subscribe(conversationIds: string[]): void {
    this.socket$?.next({
      type: 'subscribe',
      payload: { conversationIds }
    });
  }

  unsubscribe(conversationIds: string[]): void {
    this.socket$?.next({
      type: 'unsubscribe',
      payload: { conversationIds }
    });
  }

  disconnect(): void {
    this.destroy$.next();
    this.socket$?.complete();
    this.socket$ = null;
  }

  // Filter for status updates only
  onStatusUpdate(): Observable<StatusUpdate> {
    return new Observable(observer => {
      this.socket$?.subscribe({
        next: (message) => {
          if (message.type === 'status_update') {
            observer.next(message.payload);
          }
        },
        error: (err) => observer.error(err)
      });
    });
  }
}
```

## PHP Server Example (Ratchet)

```php
<?php
// WebSocketServer.php

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;

class WebSocketServer implements MessageComponentInterface
{
    protected $clients;
    protected $subscriptions;  // conversation_id => [connections]
    protected $userConnections; // user_id => [connections]

    public function __construct()
    {
        $this->clients = new \SplObjectStorage;
        $this->subscriptions = [];
        $this->userConnections = [];
    }

    public function onOpen(ConnectionInterface $conn)
    {
        // Validate JWT from query string
        $token = $this->getTokenFromQuery($conn);
        $user = $this->validateToken($token);
        
        if (!$user) {
            $conn->send(json_encode([
                'type' => 'error',
                'payload' => ['code' => 'UNAUTHORIZED']
            ]));
            $conn->close();
            return;
        }

        $conn->userId = $user['user_id'];
        $this->clients->attach($conn);
        $this->userConnections[$user['user_id']][] = $conn;
        
        echo "[WS] User {$user['user_id']} connected\n";
    }

    public function onMessage(ConnectionInterface $from, $msg)
    {
        $data = json_decode($msg, true);
        
        switch ($data['type'] ?? '') {
            case 'subscribe':
                $this->handleSubscribe($from, $data['payload']);
                break;
            case 'unsubscribe':
                $this->handleUnsubscribe($from, $data['payload']);
                break;
            case 'ping':
                $from->send(json_encode(['type' => 'pong', 'payload' => $data['payload']]));
                break;
        }
    }

    public function broadcastStatusUpdate(array $statusUpdate)
    {
        $conversationId = $statusUpdate['conversationId'];
        
        if (isset($this->subscriptions[$conversationId])) {
            $message = json_encode([
                'type' => 'status_update',
                'payload' => $statusUpdate
            ]);
            
            foreach ($this->subscriptions[$conversationId] as $conn) {
                $conn->send($message);
            }
        }
    }

    // ... implementation details
}
```

## Metrics Exposed

| Metric | Type | Description |
|--------|------|-------------|
| `websocket_connections_total` | Counter | Total connections established |
| `websocket_active_connections` | Gauge | Current active connections |
| `websocket_messages_sent_total` | Counter | Messages sent to clients |
| `websocket_messages_received_total` | Counter | Messages received from clients |
| `websocket_subscriptions_total` | Counter | Total subscription events |
