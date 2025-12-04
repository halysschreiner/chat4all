# RNF10, RNF11, RNF12, RNF13 - Containerização, UI, Documentação e Stack

---

## 1. Resumo dos Requisitos

### RNF10 - Containerização
> - Todos os serviços executam em containers Docker.
> - Docker Compose para orquestração de múltiplos containers.
> - Health checks configurados para cada serviço.
> - Inicialização automática de todos os serviços com script (`docker-compose up`).

### RNF11 - Interface de Usuário
> - Interface web desenvolvida em Angular 17 (SPA).
> - Interface de terminal (CLI) também satisfaz os requisitos mínimos.
> - Indicadores visuais de status de mensagem (✓, ✓✓, ✓✓ azul).

### RNF12 - Documentação
> - README com endpoints, exemplos de uso e instruções de execução.
> - Documentação OpenAPI com endpoints de upload e campos das APIs.
> - Documentação dos fluxos de entrega e leitura no relatório técnico.

### RNF13 - Stack Tecnológica
> | Componente | Tecnologia | Versão |
> |------------|------------|--------|
> | Backend | PHP | 8.3 |
> | Frontend | Angular | 17 |
> | RPC | gRPC | - |
> | Banco | PostgreSQL | 16 |
> | Cache | Redis | 7 |
> | Object Storage | MinIO | Latest |
> | Message Broker | Apache Kafka | 7.5.0 |
> | Monitoramento | Prometheus | Latest |
> | Dashboards | Grafana | Latest |
> | Containers | Docker | - |
> | WebSocket | Ratchet (PHP) | - |

---

## 2. Fundamentos Teóricos

### 2.1 Containerização e Docker

```
┌─────────────────────────────────────────────────────────────┐
│                VMs vs CONTAINERS                            │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  VIRTUAL MACHINES             CONTAINERS (Docker)           │
│  ┌────────────────┐           ┌────────────────┐           │
│  │    App 1       │           │    App 1       │           │
│  ├────────────────┤           ├────────────────┤           │
│  │  Guest OS      │           │   Bins/Libs    │           │
│  ├────────────────┤           └────────┬───────┘           │
│  │  Hypervisor    │                    │                   │
│  ├────────────────┤           ┌────────┴───────┐           │
│  │    Host OS     │           │  Docker Engine │           │
│  ├────────────────┤           ├────────────────┤           │
│  │   Hardware     │           │    Host OS     │           │
│  └────────────────┘           ├────────────────┤           │
│                               │   Hardware     │           │
│  ⚠️ Overhead de GB            └────────────────┘           │
│  ⚠️ Boot lento (minutos)      ✅ Overhead de MB            │
│                               ✅ Boot rápido (segundos)    │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Single Page Application (SPA)

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

### 2.3 Documentação como Código

```
┌─────────────────────────────────────────────────────────────┐
│                 DOCUMENTATION AS CODE                       │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Princípios:                                                │
│  • Versionada com o código (Git)                           │
│  • Gerada automaticamente quando possível                  │
│  • Próxima ao código que documenta                         │
│  • Testável (links, exemplos)                              │
│                                                             │
│  Tipos:                                                     │
│  ┌─────────────────┐  ┌─────────────────┐                  │
│  │   README.md     │  │  OpenAPI/Swagger│                  │
│  │   (Getting      │  │  (API Reference)│                  │
│  │    Started)     │  │                 │                  │
│  └─────────────────┘  └─────────────────┘                  │
│  ┌─────────────────┐  ┌─────────────────┐                  │
│  │  ADRs           │  │  Inline Docs    │                  │
│  │  (Architecture  │  │  (PHPDoc,       │                  │
│  │   Decisions)    │  │   JSDoc)        │                  │
│  └─────────────────┘  └─────────────────┘                  │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Implementação no Chat4All

### 3.1 RNF10 - Containerização

#### 3.1.1 Docker Compose Completo (`docker-compose.yml`)

**Estrutura de serviços (328 linhas)**:
```yaml
services:
  # === INFRAESTRUTURA ===
  postgres:
    image: postgres:16-alpine
    container_name: chat4all-postgres
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U chat4all_user"]
      interval: 10s
      timeout: 5s
      retries: 5

  redis:
    image: redis:7-alpine
    container_name: chat4all-redis
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 10s

  kafka:
    image: confluentinc/cp-kafka:7.5.0
    depends_on:
      zookeeper:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "kafka-topics", "--bootstrap-server", "localhost:9092", "--list"]

  minio:
    image: minio/minio:latest
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:9000/minio/health/live"]

  # === APLICAÇÃO ===
  api-gateway:
    build: ./api-gateway
    depends_on:
      - api-service

  api-service:
    build: ./services/api-service
    depends_on:
      postgres:
        condition: service_healthy
      kafka:
        condition: service_started

  router-worker:
    build: ./workers/router-worker
    # container_name comentado para scaling

  websocket-worker:
    build: ./workers/websocket-worker
    restart: unless-stopped
    healthcheck:
      test: ["CMD", "nc", "-z", "localhost", "8081"]

  # === CONNECTORS ===
  whatsapp-connector:
    build: ./connectors/whatsapp-mock

  instagram-connector:
    build: ./connectors/instagram-mock

  # === FRONTEND ===
  web:
    build: ./frontend
    depends_on:
      - api-gateway

  # === MONITORAMENTO ===
  prometheus:
    image: prom/prometheus:latest

  grafana:
    image: grafana/grafana:latest

networks:
  chat4all-network:
    driver: bridge

volumes:
  postgres_data:
  redis_data:
  minio_data:
  prometheus-data:
  grafana-data:
```

#### 3.1.2 Dockerfile Multi-Stage (Frontend)

```dockerfile
# Stage 1: Build the Angular app
FROM node:20-alpine as build
WORKDIR /app
COPY frontend/package.json frontend/package-lock.json* ./
RUN npm install
COPY frontend/ .
RUN npm run build -- --configuration production

# Stage 2: Serve with Nginx
FROM nginx:alpine
COPY --from=build /app/dist/chat4all-frontend /usr/share/nginx/html

# SPA routing
RUN echo 'server { \
    listen 80; \
    root /usr/share/nginx/html; \
    location / { \
        try_files $uri $uri/ /index.html; \
    } \
}' > /etc/nginx/conf.d/default.conf

EXPOSE 80
CMD ["nginx", "-g", "daemon off;"]
```

#### 3.1.3 Scripts de Inicialização

**start.sh**:
```bash
#!/bin/bash
echo "🚀 Iniciando Chat4All..."

# Build e start
docker-compose up -d --build

# Aguardar health checks
echo "⏳ Aguardando serviços ficarem healthy..."
sleep 30

# Verificar status
docker-compose ps

echo "✅ Chat4All iniciado com sucesso!"
echo "📱 Frontend: http://localhost:4200"
echo "🔌 API: http://localhost:8000"
echo "📊 Grafana: http://localhost:3001"
```

### 3.2 RNF11 - Interface Angular

#### 3.2.1 Estrutura do Projeto

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

#### 3.2.2 WebSocket Service (`websocket.service.ts`)

**Linhas 1-60**:
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

#### 3.2.3 Indicadores de Status de Mensagem

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

#### 3.2.4 Chat Component (`chat.component.ts`)

**Linhas 1-50**:
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

### 3.3 RNF12 - Documentação

#### 3.3.1 README Principal (`README.md`)

```markdown
# Chat4All - Sistema de Chat Distribuído

## 🚀 Quick Start

### Pré-requisitos
- Docker 20.10+
- Docker Compose 2.0+

### Iniciar o Sistema
```bash
./scripts/start.sh
```

### Acessar
- Frontend: http://localhost:4200
- API: http://localhost:8000
- Grafana: http://localhost:3001

## 📚 Endpoints da API

### Autenticação
```bash
# Registrar
POST /v1/auth/register
{
  "username": "john",
  "email": "john@example.com",
  "password": "secret123"
}

# Login
POST /v1/auth/login
{
  "email": "john@example.com",
  "password": "secret123"
}
```

### Mensagens
```bash
# Enviar mensagem
POST /v1/messages
Authorization: Bearer <token>
{
  "conversation_id": "uuid",
  "content": "Hello!"
}

# Listar mensagens
GET /v1/conversations/{id}/messages
```
```

#### 3.3.2 Documentação da API (`docs/API_DOCUMENTATION.md`)

**1306 linhas** cobrindo:
- Todos os endpoints REST
- Exemplos de request/response
- Códigos de erro
- Autenticação JWT
- Upload de arquivos

#### 3.3.3 Documentações Adicionais

```
docs/
├── API_DOCUMENTATION.md      # 1306 linhas - endpoints completos
├── CONNECTORS_IMPLEMENTATION.md  # Connectors mock
├── DEMO_SCRIPT.md            # Script de demonstração
├── EXAMPLES.md               # Exemplos de uso
├── FAULT_TOLERANCE.md        # Tolerância a falhas
├── FILE_UPLOAD_SYSTEM.md     # Sistema de upload
├── MESSAGE_STATUS_IMPLEMENTATION.md  # Fluxo de status
├── SCALING.md                # Escalabilidade
├── WEB_INTERFACE.md          # Interface web
└── WEBSOCKET_GUIDE.md        # WebSocket API
```

### 3.4 RNF13 - Stack Tecnológica

#### 3.4.1 Tabela de Conformidade

| Componente | Requisito | Implementado | Evidência |
|------------|-----------|--------------|-----------|
| Backend | PHP 8.3 | ✅ | `api-service/Dockerfile: FROM php:8.3` |
| Frontend | Angular 17 | ✅ | `package.json: "@angular/core": "^17.0.0"` |
| RPC | gRPC | ✅ | `shared/proto/*.proto`, grpc extension |
| Banco | PostgreSQL 16 | ✅ | `docker-compose.yml: postgres:16-alpine` |
| Cache | Redis 7 | ✅ | `docker-compose.yml: redis:7-alpine` |
| Object Storage | MinIO | ✅ | `docker-compose.yml: minio/minio:latest` |
| Message Broker | Kafka 7.5.0 | ✅ | `docker-compose.yml: cp-kafka:7.5.0` |
| Monitoramento | Prometheus | ✅ | `docker-compose.yml: prom/prometheus:latest` |
| Dashboards | Grafana | ✅ | `docker-compose.yml: grafana/grafana:latest` |
| Containers | Docker | ✅ | Todos os serviços containerizados |
| WebSocket | Ratchet | ✅ | `websocket-worker/composer.json` |

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| **RNF10**: Docker | ✅ | Dockerfiles em cada serviço |
| **RNF10**: Docker Compose | ✅ | `docker-compose.yml` 328 linhas |
| **RNF10**: Health checks | ✅ | `healthcheck:` em cada serviço |
| **RNF10**: Inicialização automática | ✅ | `scripts/start.sh` |
| **RNF11**: Angular 17 | ✅ | `package.json` |
| **RNF11**: Indicadores ✓✓ | ✅ | CSS classes por status |
| **RNF12**: README | ✅ | `README.md` + `docs/` |
| **RNF12**: OpenAPI | ⚠️ | Parcial (Markdown, não OpenAPI.yaml) |
| **RNF12**: Fluxos documentados | ✅ | `MESSAGE_STATUS_IMPLEMENTATION.md` |
| **RNF13**: Stack completa | ✅ | Todas tecnologias conforme requisito |

### 4.2 Pontos Fortes

1. **Multi-stage builds**: Frontend compila em Node, serve com Nginx mínimo
2. **Health checks completos**: Todos serviços críticos monitorados
3. **Documentação extensiva**: 10+ arquivos .md detalhados
4. **WebSocket reativo**: RxJS para gerenciamento de estado

### 4.3 Limitações Identificadas

#### Limitação 1: Sem OpenAPI/Swagger Formal

**Problema**: Documentação em Markdown, não gerada automaticamente.

**Solução**: Adicionar OpenAPI spec:
```yaml
# openapi.yaml
openapi: 3.0.0
info:
  title: Chat4All API
  version: 1.0.0
paths:
  /v1/auth/login:
    post:
      summary: Login do usuário
      requestBody:
        content:
          application/json:
            schema:
              type: object
              properties:
                email:
                  type: string
                password:
                  type: string
```

#### Limitação 2: Frontend Sem Testes

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

#### Limitação 3: Environment Hardcoded

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

#### Limitação 4: Sem CI/CD

**Problema**: Sem pipeline de build/deploy automatizado.

**Solução**: GitHub Actions:
```yaml
# .github/workflows/ci.yml
name: CI
on: [push, pull_request]
jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v3
      - name: Build containers
        run: docker-compose build
      - name: Run tests
        run: docker-compose run api-service composer test
```

### 4.4 Perguntas Socráticas para Aprofundamento

1. **Sobre Containerização**:
   - "O que acontece se o health check falhar 5 vezes consecutivas?"
   - "Como você faria rolling update sem downtime?"
   - "Qual a diferença entre `depends_on` e `condition: service_healthy`?"

2. **Sobre SPA**:
   - "Como você lidaria com SEO em uma SPA?"
   - "O que é lazy loading de módulos Angular e por que usaria?"
   - "Como prevenir memory leaks com Observables?"

3. **Sobre Documentação**:
   - "Documentação viva ou estática? Qual a vantagem de OpenAPI?"
   - "Como garantir que a documentação está atualizada com o código?"

---

## 5. Referências Teóricas

- **Docker Documentation** - *Best practices for writing Dockerfiles*
- **Angular Architecture Guide** - *Official Angular Docs*
- **12-Factor App** - Heroku (Configuration, Dependencies)
- **OpenAPI Specification** - Swagger/OpenAPI 3.0
- **The Phoenix Project** - DevOps practices
