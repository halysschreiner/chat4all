# Chat4All - Guia de WebSocket

Este documento descreve a implementação e uso do WebSocket no sistema Chat4All para notificações em tempo real.

## 📋 Visão Geral

O WebSocket no Chat4All é utilizado para notificar os clientes sobre atualizações de status de mensagens em tempo real, eliminando a necessidade de polling.

### Arquitetura

```
┌─────────────┐     ┌──────────────────┐     ┌─────────────────┐
│   Frontend  │────▶│ WebSocket Worker │◀────│ Kafka Consumer  │
│  (Angular)  │     │   (Ratchet)      │     │ (status-updates)│
└─────────────┘     └──────────────────┘     └─────────────────┘
        │                    │                        │
        │ ws://host:8082     │                        │
        └────────────────────┘                        │
                                                      │
┌─────────────┐     ┌──────────────────┐              │
│  Connector  │────▶│   API Service    │──────────────┘
│  (Callback) │     │   (Publisher)    │
└─────────────┘     └──────────────────┘
```

### Fluxo de Status

1. **Mensagem enviada** → Connector recebe
2. **Connector processa** → Envia callback com status DELIVERED/READ
3. **API Service** → Atualiza banco e publica no Kafka `status-updates`
4. **WebSocket Worker** → Consome evento e faz broadcast
5. **Frontend** → Recebe e atualiza UI em tempo real

## 🔌 Conexão

### URL de Conexão

```
ws://localhost:8082
```

Em produção:
```
wss://chat4all.example.com/ws
```

### Exemplo JavaScript (Vanilla)

```javascript
// Conectar ao WebSocket
const ws = new WebSocket('ws://localhost:8082');

// Handler de conexão aberta
ws.onopen = () => {
    console.log('WebSocket conectado');
    
    // Autenticar com token JWT
    ws.send(JSON.stringify({
        type: 'auth',
        token: 'seu_jwt_token_aqui'
    }));
};

// Handler de mensagens
ws.onmessage = (event) => {
    const data = JSON.parse(event.data);
    
    switch (data.type) {
        case 'auth_success':
            console.log('Autenticado com sucesso');
            break;
            
        case 'auth_error':
            console.error('Erro de autenticação:', data.message);
            break;
            
        case 'status_update':
            handleStatusUpdate(data.payload);
            break;
            
        case 'new_message':
            handleNewMessage(data.payload);
            break;
    }
};

// Handler de erros
ws.onerror = (error) => {
    console.error('Erro no WebSocket:', error);
};

// Handler de desconexão
ws.onclose = (event) => {
    console.log('WebSocket desconectado:', event.code, event.reason);
    
    // Reconectar automaticamente após 3 segundos
    setTimeout(() => {
        connect();
    }, 3000);
};

// Processar atualização de status
function handleStatusUpdate(payload) {
    const { message_id, status, updated_at } = payload;
    
    // Atualizar UI
    const messageElement = document.querySelector(`[data-message-id="${message_id}"]`);
    if (messageElement) {
        const statusIndicator = messageElement.querySelector('.status-indicator');
        statusIndicator.className = `status-indicator status-${status.toLowerCase()}`;
    }
    
    console.log(`Mensagem ${message_id} agora está ${status}`);
}
```

### Exemplo Angular (TypeScript)

```typescript
// services/websocket.service.ts
import { Injectable } from '@angular/core';
import { BehaviorSubject, Observable } from 'rxjs';

interface StatusUpdate {
  message_id: string;
  status: 'SENT' | 'DELIVERED' | 'READ';
  updated_at: string;
}

@Injectable({
  providedIn: 'root'
})
export class WebSocketService {
  private ws: WebSocket | null = null;
  private statusUpdates$ = new BehaviorSubject<StatusUpdate | null>(null);
  private connectionStatus$ = new BehaviorSubject<boolean>(false);
  
  constructor() {}
  
  connect(token: string): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      return;
    }
    
    this.ws = new WebSocket('ws://localhost:8082');
    
    this.ws.onopen = () => {
      console.log('[WS] Conectado');
      this.connectionStatus$.next(true);
      
      // Autenticar
      this.send({
        type: 'auth',
        token: token
      });
    };
    
    this.ws.onmessage = (event) => {
      const data = JSON.parse(event.data);
      this.handleMessage(data);
    };
    
    this.ws.onclose = () => {
      console.log('[WS] Desconectado');
      this.connectionStatus$.next(false);
      
      // Reconectar após 3 segundos
      setTimeout(() => {
        this.connect(token);
      }, 3000);
    };
    
    this.ws.onerror = (error) => {
      console.error('[WS] Erro:', error);
    };
  }
  
  disconnect(): void {
    if (this.ws) {
      this.ws.close();
      this.ws = null;
    }
  }
  
  getStatusUpdates(): Observable<StatusUpdate | null> {
    return this.statusUpdates$.asObservable();
  }
  
  getConnectionStatus(): Observable<boolean> {
    return this.connectionStatus$.asObservable();
  }
  
  private send(data: any): void {
    if (this.ws?.readyState === WebSocket.OPEN) {
      this.ws.send(JSON.stringify(data));
    }
  }
  
  private handleMessage(data: any): void {
    switch (data.type) {
      case 'auth_success':
        console.log('[WS] Autenticado');
        break;
        
      case 'auth_error':
        console.error('[WS] Erro de auth:', data.message);
        break;
        
      case 'status_update':
        this.statusUpdates$.next(data.payload);
        break;
    }
  }
}
```

### Uso no Componente Angular

```typescript
// components/chat/chat.component.ts
import { Component, OnInit, OnDestroy } from '@angular/core';
import { WebSocketService } from '../../services/websocket.service';
import { AuthService } from '../../services/auth.service';
import { Subscription } from 'rxjs';

@Component({
  selector: 'app-chat',
  templateUrl: './chat.component.html'
})
export class ChatComponent implements OnInit, OnDestroy {
  private subscription: Subscription | null = null;
  
  constructor(
    private wsService: WebSocketService,
    private authService: AuthService
  ) {}
  
  ngOnInit(): void {
    // Conectar WebSocket com token
    const token = this.authService.getToken();
    if (token) {
      this.wsService.connect(token);
    }
    
    // Inscrever para atualizações de status
    this.subscription = this.wsService.getStatusUpdates()
      .subscribe(update => {
        if (update) {
          this.updateMessageStatus(update.message_id, update.status);
        }
      });
  }
  
  ngOnDestroy(): void {
    this.subscription?.unsubscribe();
    this.wsService.disconnect();
  }
  
  updateMessageStatus(messageId: string, status: string): void {
    // Atualizar o status da mensagem na lista
    const message = this.messages.find(m => m.id === messageId);
    if (message) {
      message.status = status;
    }
  }
}
```

## 📨 Protocolo de Mensagens

### Mensagens do Cliente → Servidor

#### Autenticação
```json
{
  "type": "auth",
  "token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

#### Subscribe (opcional - automático após auth)
```json
{
  "type": "subscribe",
  "conversation_id": "uuid-da-conversa"
}
```

#### Unsubscribe
```json
{
  "type": "unsubscribe",
  "conversation_id": "uuid-da-conversa"
}
```

#### Ping (keep-alive)
```json
{
  "type": "ping"
}
```

### Mensagens do Servidor → Cliente

#### Sucesso de Autenticação
```json
{
  "type": "auth_success",
  "user_id": "uuid-do-usuario"
}
```

#### Erro de Autenticação
```json
{
  "type": "auth_error",
  "message": "Token inválido ou expirado"
}
```

#### Atualização de Status
```json
{
  "type": "status_update",
  "payload": {
    "message_id": "uuid-da-mensagem",
    "conversation_id": "uuid-da-conversa",
    "status": "DELIVERED",
    "updated_at": "2025-01-15T10:30:00Z"
  }
}
```

#### Nova Mensagem (opcional)
```json
{
  "type": "new_message",
  "payload": {
    "id": "uuid-da-mensagem",
    "conversation_id": "uuid-da-conversa",
    "sender_id": "uuid-do-remetente",
    "content": "Olá!",
    "type": "text",
    "created_at": "2025-01-15T10:30:00Z"
  }
}
```

#### Pong (resposta ao ping)
```json
{
  "type": "pong"
}
```

## 🎨 Indicadores de Status na UI

### CSS para Status

```css
/* Indicadores de status de mensagem */
.status-indicator {
  display: inline-flex;
  align-items: center;
  font-size: 0.75rem;
  margin-left: 4px;
}

/* ✓ Enviado (SENT) */
.status-sent::after {
  content: '✓';
  color: #9ca3af;
}

/* ✓✓ Entregue (DELIVERED) */
.status-delivered::after {
  content: '✓✓';
  color: #9ca3af;
}

/* ✓✓ Lido (READ) - azul */
.status-read::after {
  content: '✓✓';
  color: #3b82f6;
}

/* Enviando... */
.status-pending::after {
  content: '⏳';
  color: #fbbf24;
}

/* Erro */
.status-failed::after {
  content: '⚠️';
  color: #ef4444;
}
```

### HTML Template

```html
<div class="message" [class.sent]="message.is_mine">
  <div class="message-content">
    {{ message.content }}
  </div>
  <div class="message-footer">
    <span class="message-time">{{ message.created_at | date:'HH:mm' }}</span>
    <span *ngIf="message.is_mine" 
          class="status-indicator"
          [ngClass]="'status-' + message.status.toLowerCase()">
    </span>
  </div>
</div>
```

## 🔧 Configuração do Servidor

### Docker Compose

```yaml
websocket-worker:
  build:
    context: ./workers/websocket-worker
    dockerfile: Dockerfile
  ports:
    - "8082:8082"
  environment:
    - KAFKA_BROKER=kafka:9093
    - KAFKA_GROUP_ID=websocket-worker-group
    - KAFKA_TOPIC=status-updates
    - WEBSOCKET_PORT=8082
    - JWT_SECRET=${JWT_SECRET}
  depends_on:
    kafka:
      condition: service_healthy
  networks:
    - chat4all-network
```

### Variáveis de Ambiente

| Variável | Descrição | Padrão |
|----------|-----------|--------|
| `WEBSOCKET_PORT` | Porta do servidor WebSocket | 8082 |
| `KAFKA_BROKER` | Endereço do Kafka | kafka:9093 |
| `KAFKA_GROUP_ID` | Consumer group ID | websocket-worker-group |
| `KAFKA_TOPIC` | Tópico para status updates | status-updates |
| `JWT_SECRET` | Segredo para validar tokens | - |

## 🧪 Testes

### Teste Manual com wscat

```bash
# Instalar wscat
npm install -g wscat

# Conectar
wscat -c ws://localhost:8082

# Autenticar (copie um token válido)
{"type":"auth","token":"seu_token_aqui"}

# Aguardar mensagens
# Envie uma mensagem pelo sistema e observe o status_update
```

### Teste com Script

```bash
# Executar script de teste WebSocket
./finalTest/scripts/test-websocket.sh
```

### Verificar Conexões Ativas

```bash
# Ver logs do WebSocket worker
docker-compose logs -f websocket-worker

# Métricas de conexões
curl http://localhost:8082/metrics | grep websocket_connections
```

## 📊 Métricas

O WebSocket worker expõe métricas Prometheus na porta 8082/metrics:

| Métrica | Tipo | Descrição |
|---------|------|-----------|
| `websocket_connections_total` | Counter | Total de conexões |
| `websocket_connections_active` | Gauge | Conexões ativas |
| `websocket_messages_received_total` | Counter | Mensagens recebidas |
| `websocket_messages_sent_total` | Counter | Mensagens enviadas |
| `websocket_auth_success_total` | Counter | Autenticações bem-sucedidas |
| `websocket_auth_failure_total` | Counter | Autenticações falhas |

## 🔒 Segurança

### Autenticação JWT

Todas as conexões WebSocket devem ser autenticadas com um token JWT válido:

1. Cliente conecta ao WebSocket
2. Cliente envia mensagem `auth` com token
3. Servidor valida token e extrai `user_id`
4. Se válido, servidor envia `auth_success`
5. Se inválido, servidor envia `auth_error` e desconecta

### Rate Limiting

- Máximo de 100 mensagens por minuto por cliente
- Máximo de 10 conexões simultâneas por usuário
- Conexões inativas por mais de 5 minutos são encerradas

### CORS

Em produção, configure origens permitidas:

```php
// Em produção, validar origem
$allowedOrigins = ['https://chat4all.example.com'];
```

## 🐛 Troubleshooting

### Conexão Recusada

```bash
# Verificar se o worker está rodando
docker-compose ps websocket-worker

# Verificar logs
docker-compose logs websocket-worker

# Verificar porta
netstat -tuln | grep 8082
```

### Token Inválido

```bash
# Verificar se o token não expirou
# Decodificar JWT em https://jwt.io/

# Gerar novo token
curl -X POST http://localhost:8080/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "user@example.com", "password": "password"}'
```

### Sem Atualizações de Status

```bash
# Verificar tópico Kafka
docker-compose exec kafka kafka-console-consumer \
  --bootstrap-server localhost:9092 \
  --topic status-updates \
  --from-beginning

# Verificar consumer group
docker-compose exec kafka kafka-consumer-groups \
  --bootstrap-server localhost:9092 \
  --describe --group websocket-worker-group
```

### Alta Latência

1. Verificar latência do Kafka
2. Verificar carga do WebSocket worker
3. Considerar escalar horizontalmente

## 📚 Referências

- [RFC 6455 - WebSocket Protocol](https://tools.ietf.org/html/rfc6455)
- [Ratchet PHP WebSocket Library](http://socketo.me/)
- [Angular WebSocket Guide](https://angular.io/guide/websocket)
