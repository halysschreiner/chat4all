# RNF11 - Interface de Usuário

---

## 1. Resumo do Requisito

> - Interface web desenvolvida em Angular 17 (SPA).
> - Interface de terminal (CLI) também satisfaz os requisitos mínimos.
> - Indicadores visuais de status de mensagem (✓, ✓✓, ✓✓ azul).

### Importância Teórica

A interface é o **ponto de contato** entre usuário e sistema distribuído. Em sistemas de chat, a UX deve refletir estados assíncronos (mensagem enviada vs entregue vs lida) de forma clara e imediata.

---

## 2. Fundamentos Teóricos

### 2.1 Single Page Application (SPA)

```
┌─────────────────────────────────────────────────────────────┐
│              ARQUITETURA SPA (Angular)                      │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Navegador                     Servidor                     │
│  ┌────────────────┐                                        │
│  │  Initial Load  │ ────────────────────────▶ Nginx        │
│  │  index.html +  │ ◀──────────────────────── (Static)     │
│  │  bundle.js     │                                        │
│  └────────────────┘                                        │
│         │                                                   │
│         │ Navegação                                         │
│         │ (client-side routing)                            │
│         ▼                                                   │
│  ┌────────────────┐                                        │
│  │  /chat         │ (NO request to server)                 │
│  │  /login        │ (Router handles locally)               │
│  └────────────────┘                                        │
│         │                                                   │
│         │ API calls (AJAX)                                 │
│         ▼                                                   │
│  ┌────────────────┐           ┌────────────────┐           │
│  │  HTTP Service  │ ─────────▶│ API Gateway    │           │
│  │  (HttpClient)  │ ◀──────── │ (JSON)         │           │
│  └────────────────┘           └────────────────┘           │
│                                                             │
│  ✅ UX fluída (sem page reloads)                           │
│  ✅ Separação frontend/backend                             │
│  ⚠️ SEO mais complexo                                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Padrões de Status de Mensagem

| Status | Ícone | Significado |
|--------|-------|-------------|
| PENDING | ⏳ | Aguardando envio |
| SENT | ✓ | Enviado ao servidor |
| DELIVERED | ✓✓ | Entregue ao destinatário |
| READ | ✓✓ (azul) | Lido pelo destinatário |
| FAILED | ✗ | Falha no envio |

---

## 3. Implementação no Chat4All

### 3.1 Estrutura do Projeto Angular

```
frontend/
├── package.json           # Angular 17
├── tsconfig.json
├── src/
│   ├── app/
│   │   ├── app.module.ts
│   │   ├── components/
│   │   │   ├── chat/      # Componente de chat
│   │   │   └── login/     # Componente de login
│   │   ├── services/
│   │   │   ├── auth.service.ts
│   │   │   ├── chat.service.ts
│   │   │   └── websocket.service.ts
│   │   └── guards/
```

### 3.2 WebSocket Service (`websocket.service.ts`)

```typescript
@Injectable({
  providedIn: 'root'
})
export class WebsocketService implements OnDestroy {
  private wsUrl = 'ws://localhost:8081';
  private socket: WebSocket | null = null;
  private reconnectAttempts = 0;
  private maxReconnectAttempts = 5;
  private reconnectDelay = 1000;
  
  // Subjects for different event types
  private connectionState$ = new BehaviorSubject<ConnectionState>('disconnected');
  private statusUpdates$ = new Subject<StatusUpdate>();
  
  constructor(private authService: AuthService) {
    // Auto-connect when user is authenticated
    this.authService.currentUser.pipe(
      takeUntil(this.destroy$),
      filter((user: any) => !!user && !!user.token)
    ).subscribe(() => {
      this.connect();
    });
  }
}
```

### 3.3 Indicadores de Status de Mensagem

**chat.component.html** (template):
```html
<div class="message" *ngFor="let msg of messages">
  <div class="content">{{ msg.content }}</div>
  <div class="status">
    <!-- Status indicators -->
    <span *ngIf="msg.status === 'PENDING'" class="status-icon pending">⏳</span>
    <span *ngIf="msg.status === 'SENT'" class="status-icon sent">✓</span>
    <span *ngIf="msg.status === 'DELIVERED'" class="status-icon delivered">✓✓</span>
    <span *ngIf="msg.status === 'READ'" class="status-icon read">✓✓</span>
    <span *ngIf="msg.status === 'FAILED'" class="status-icon failed">✗</span>
  </div>
</div>
```

**chat.component.css** (estilos):
```css
.status-icon {
  font-size: 12px;
  margin-left: 4px;
}

.status-icon.pending { color: #999; }
.status-icon.sent { color: #999; }
.status-icon.delivered { color: #999; }
.status-icon.read { color: #34b7f1; }  /* Azul como WhatsApp */
.status-icon.failed { color: #ff0000; }
```

### 3.4 Chat Component (`chat.component.ts`)

```typescript
@Component({
  selector: 'app-chat',
  templateUrl: './chat.component.html',
  styleUrls: ['./chat.component.css']
})
export class ChatComponent implements OnInit, AfterViewChecked, OnDestroy {
  conversations: any[] = [];
  selectedConversation: any = null;
  messages: any[] = [];
  newMessage = '';
  currentUser: any;
  
  // File upload
  selectedFile: File | null = null;
  isUploading = false;
  uploadProgress = 0;

  // WebSocket connection state
  connectionState: string = 'disconnected';
  
  constructor(
    private chatService: ChatService,
    private authService: AuthService,
    private router: Router
  ) {
    this.currentUser = this.authService.currentUserValue;
  }

  ngOnInit() {
    this.loadConversations();
    // Subscribe to WebSocket status updates
    this.chatService.statusUpdates$.subscribe(update => {
      this.updateMessageStatus(update.message_id, update.status);
    });
  }
}
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| Angular 17 SPA | ✅ | `package.json: "@angular/core": "^17.0.0"` |
| Indicadores ✓ (sent) | ✅ | CSS class `.sent` |
| Indicadores ✓✓ (delivered) | ✅ | CSS class `.delivered` |
| Indicadores ✓✓ azul (read) | ✅ | CSS class `.read` com `color: #34b7f1` |

### 4.2 Pontos Fortes

1. **WebSocket reativo**: RxJS para gerenciamento de estado
2. **Auto-reconnect**: Reconexão automática com backoff
3. **Feedback visual claro**: Ícones consistentes com padrão WhatsApp

### 4.3 Limitações Identificadas

#### Limitação 1: Frontend Sem Testes

**Problema**: Nenhum teste unitário Angular encontrado.

**Solução**: Adicionar testes Jasmine/Karma:
```typescript
// chat.component.spec.ts
describe('ChatComponent', () => {
  it('should display message status icons', () => {
    component.messages = [{ status: 'READ', content: 'test' }];
    fixture.detectChanges();
    const statusIcon = fixture.nativeElement.querySelector('.status-icon.read');
    expect(statusIcon.textContent).toContain('✓✓');
  });
});
```

#### Limitação 2: Environment Hardcoded

**Problema**: URLs fixos no código Angular.

```typescript
// websocket.service.ts
private wsUrl = 'ws://localhost:8081';  // Hardcoded!
```

**Solução**: Usar environment files:
```typescript
// environment.prod.ts
export const environment = {
  production: true,
  wsUrl: 'wss://api.chat4all.com/ws',
  apiUrl: 'https://api.chat4all.com'
};

// websocket.service.ts
private wsUrl = environment.wsUrl;
```

#### Limitação 3: Sem Lazy Loading

**Problema**: Todo o bundle carrega de uma vez.

**Solução**: Lazy load de módulos:
```typescript
const routes: Routes = [
  { path: 'chat', loadChildren: () => import('./chat/chat.module').then(m => m.ChatModule) }
];
```

### 4.4 Perguntas Socráticas para Aprofundamento

1. "Como você lidaria com SEO em uma SPA?"
2. "O que é lazy loading de módulos Angular e por que usaria?"
3. "Como prevenir memory leaks com Observables?"
4. "Se o WebSocket desconectar, o usuário sabe? Como você comunica isso?"

---

## 5. Referências Teóricas

- **Angular Architecture Guide** - *Official Angular Docs*
- **RxJS Documentation** - *Reactive Extensions for JavaScript*
- **Material Design Guidelines** - *Status indicators patterns*
