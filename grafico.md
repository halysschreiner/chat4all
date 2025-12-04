# Chat4All - Diagrama de Arquitetura

## 🏗️ Arquitetura Geral do Sistema

O diagrama abaixo representa a arquitetura distribuída do Chat4All, um sistema de mensagens instantâneas com escalabilidade horizontal, tolerância a falhas e comunicação baseada em eventos.

```mermaid
flowchart TB
    subgraph Clients["👤 Clientes"]
        direction LR
        Web["🌐 Frontend Angular<br/>:4200"]
        Mobile["📱 Clientes Mobile<br/>(futuro)"]
    end

    subgraph Gateway["🚪 Gateway Layer"]
        APIGateway["⚡ API Gateway<br/>PHP 8.3 + Nginx<br/>:8000<br/><i>REST → gRPC</i>"]
    end

    subgraph Application["📦 Application Layer"]
        direction TB
        APIService["🔧 API Service<br/>PHP 8.3 gRPC<br/>:8080 / :50051"]
        WSWorker["🔌 WebSocket Worker<br/>PHP + Ratchet<br/>:8081<br/><i>Real-time Updates</i>"]
    end

    subgraph MessageBroker["📨 Message Broker"]
        Kafka["📬 Apache Kafka<br/>:9092 / :9093<br/>5 partições"]
        
        subgraph Topics["📋 Tópicos"]
            T1["messages"]
            T2["whatsapp.messages"]
            T3["instagram.messages"]
            T4["status-updates"]
        end
    end

    subgraph Workers["⚙️ Processing Workers (Escaláveis)"]
        direction LR
        RW1["🔄 Router<br/>Worker 1"]
        RW2["🔄 Router<br/>Worker 2"]
        RWN["🔄 Router<br/>Worker N"]
    end

    subgraph Connectors["🔗 External Connectors (Escaláveis)"]
        direction LR
        subgraph WhatsApp["WhatsApp Mock"]
            WA1["📱 WA 1"]
            WA2["📱 WA 2"]
            WAN["📱 WA N"]
        end
        subgraph Instagram["Instagram Mock"]
            IG1["📸 IG 1"]
            IG2["📸 IG 2"]
            IGN["📸 IG N"]
        end
    end

    subgraph DataLayer["💾 Data Layer"]
        direction LR
        Postgres[("🐘 PostgreSQL 16<br/>:5432<br/><i>Dados Transacionais</i>")]
        Redis[("⚡ Redis 7<br/>:6379<br/><i>Cache & Sessions</i>")]
        MinIO[("📁 MinIO S3<br/>:9001 / :9002<br/><i>Object Storage</i>")]
    end

    subgraph Monitoring["📊 Monitoring Stack"]
        direction LR
        Prometheus["📈 Prometheus<br/>:9090"]
        Grafana["📊 Grafana<br/>:3001"]
        Exporter["📡 Metrics<br/>Exporter<br/>:8001"]
    end

    subgraph Infra["🐳 Infraestrutura"]
        Zookeeper["🦓 Zookeeper<br/>:2181"]
        DockerNetwork["🌐 chat4all-network"]
    end

    %% Client connections
    Web -->|HTTP/REST| APIGateway
    Web <-->|WebSocket| WSWorker
    Mobile -.->|HTTP/REST| APIGateway

    %% Gateway to Application
    APIGateway -->|gRPC| APIService

    %% Application to Data
    APIService --> Postgres
    APIService --> Redis
    APIService --> MinIO
    WSWorker --> Redis

    %% Application to Kafka
    APIService -->|Produce| Kafka
    Kafka --> T1
    Kafka --> T2
    Kafka --> T3
    Kafka --> T4

    %% Kafka to Workers
    T1 -->|Consume| RW1
    T1 -->|Consume| RW2
    T1 -->|Consume| RWN

    %% Workers update status
    RW1 & RW2 & RWN -->|Status Update| Postgres

    %% Kafka to Connectors
    T2 -->|Consume| WA1
    T2 -->|Consume| WA2
    T2 -->|Consume| WAN
    T3 -->|Consume| IG1
    T3 -->|Consume| IG2
    T3 -->|Consume| IGN

    %% Connectors callbacks
    WA1 & WA2 & WAN -.->|Callback<br/>DELIVERED/READ| APIService
    IG1 & IG2 & IGN -.->|Callback<br/>DELIVERED/READ| APIService

    %% Status updates to WebSocket
    T4 -->|Consume| WSWorker

    %% Monitoring
    APIService -.->|Metrics| Prometheus
    RW1 & RW2 & RWN -.->|Metrics| Prometheus
    WSWorker -.->|Metrics| Prometheus
    Exporter -.->|Metrics| Prometheus
    Prometheus --> Grafana

    %% Infrastructure
    Kafka --> Zookeeper

    %% Styling
    classDef client fill:#e1f5fe,stroke:#01579b
    classDef gateway fill:#fff3e0,stroke:#e65100
    classDef app fill:#e8f5e9,stroke:#2e7d32
    classDef broker fill:#fce4ec,stroke:#c2185b
    classDef worker fill:#f3e5f5,stroke:#7b1fa2
    classDef connector fill:#fff8e1,stroke:#f57f17
    classDef data fill:#e0f2f1,stroke:#00695c
    classDef monitor fill:#fafafa,stroke:#424242
    
    class Web,Mobile client
    class APIGateway gateway
    class APIService,WSWorker app
    class Kafka,T1,T2,T3,T4 broker
    class RW1,RW2,RWN worker
    class WA1,WA2,WAN,IG1,IG2,IGN connector
    class Postgres,Redis,MinIO data
    class Prometheus,Grafana,Exporter monitor
```

---

## 📋 Legenda dos Componentes

| Camada | Componente | Porta | Tecnologia | Função |
|--------|------------|-------|------------|--------|
| **Cliente** | Frontend Angular | 4200 | Angular 17 | Interface web responsiva |
| **Gateway** | API Gateway | 8000 | PHP 8.3 + Nginx | Adaptador REST ↔ gRPC |
| **Aplicação** | API Service | 8080/50051 | PHP 8.3 gRPC | Serviços de negócio |
| **Aplicação** | WebSocket Worker | 8081 | PHP + Ratchet | Notificações real-time |
| **Broker** | Apache Kafka | 9092/9093 | Kafka 7.5 | Message streaming |
| **Workers** | Router Workers | - | PHP 8.3 | Processamento assíncrono |
| **Connectors** | WhatsApp/Instagram | - | PHP 8.3 | Integração externa (mock) |
| **Dados** | PostgreSQL | 5432 | PostgreSQL 16 | Dados transacionais |
| **Dados** | Redis | 6379 | Redis 7 | Cache e sessões |
| **Dados** | MinIO | 9001/9002 | MinIO S3 | Armazenamento de arquivos |
| **Monitoramento** | Prometheus | 9090 | Prometheus | Coleta de métricas |
| **Monitoramento** | Grafana | 3001 | Grafana | Dashboards |

---

## 🔄 Fluxo de Mensagens

```mermaid
sequenceDiagram
    autonumber
    participant C as 👤 Cliente
    participant GW as 🚪 Gateway
    participant API as 🔧 API Service
    participant K as 📬 Kafka
    participant RW as ⚙️ Router Worker
    participant WA as 📱 WhatsApp
    participant WS as 🔌 WebSocket
    participant DB as 🐘 PostgreSQL

    C->>GW: POST /v1/messages
    GW->>API: gRPC SendMessage()
    API->>DB: INSERT message (status=SENT)
    API->>K: Produce → messages
    API-->>GW: Message created
    GW-->>C: 201 Created

    K->>RW: Consume messages
    RW->>DB: UPDATE status=PROCESSING
    RW->>K: Produce → whatsapp.messages

    K->>WA: Consume whatsapp.messages
    Note over WA: Simula envio (100-500ms)
    WA-->>API: Callback DELIVERED
    API->>DB: UPDATE status=DELIVERED
    API->>K: Produce → status-updates

    K->>WS: Consume status-updates
    WS-->>C: WebSocket: status=DELIVERED

    Note over WA: Aguarda 5-15s
    WA-->>API: Callback READ
    API->>DB: UPDATE status=READ
    API->>K: Produce → status-updates

    K->>WS: Consume status-updates
    WS-->>C: WebSocket: status=READ
```

---

## 🚀 Escalabilidade Horizontal

```mermaid
graph LR
    subgraph Antes["❌ Antes (Não Escalável)"]
        A1["container_name: fixo"]
        A2["ports: fixos"]
    end

    subgraph Depois["✅ Depois (Escalável)"]
        D1["# container_name comentado"]
        D2["# ports comentados"]
        D3["Consumer Groups"]
    end

    subgraph Comando["🔧 Comandos de Scaling"]
        C1["docker-compose up -d<br/>--scale router-worker=5"]
        C2["docker-compose up -d<br/>--scale whatsapp-connector=3"]
    end

    Antes --> Depois
    Depois --> Comando
```

### Componentes Escaláveis

| Componente | Min | Max Testado | Consumer Group |
|------------|-----|-------------|----------------|
| Router Workers | 1 | 5 | `router-worker-group` |
| WhatsApp Connector | 1 | 3 | `whatsapp-connector-group` |
| Instagram Connector | 1 | 3 | `instagram-connector-group` |

---

## 📊 Stack de Monitoramento

```mermaid
graph TB
    subgraph Serviços["🔧 Serviços Monitorados"]
        S1["API Service<br/>/metrics"]
        S2["Router Workers<br/>/metrics"]
        S3["WebSocket Worker<br/>/metrics"]
        S4["Metrics Exporter<br/>:8001"]
    end

    subgraph Coleta["📈 Coleta"]
        Prom["Prometheus<br/>:9090<br/>scrape interval: 15s"]
    end

    subgraph Visual["📊 Visualização"]
        Graf["Grafana<br/>:3001<br/>refresh: 5s"]
    end

    S1 & S2 & S3 & S4 -->|/metrics| Prom
    Prom --> Graf

    subgraph Metricas["📋 11 Métricas Expostas"]
        M1["messages_total"]
        M2["messages_by_status"]
        M3["http_requests_total"]
        M4["kafka_messages_processed"]
        M5["active_connections"]
    end

    Graf --> Metricas
```

---

## 🏛️ Padrões Arquiteturais Utilizados

| Padrão | Aplicação no Chat4All |
|--------|----------------------|
| **API Gateway** | Gateway único para todos os clientes |
| **Microservices** | Serviços independentes e desacoplados |
| **Event-Driven** | Comunicação assíncrona via Kafka |
| **CQRS** | Separação de leitura/escrita |
| **Consumer Groups** | Balanceamento automático de carga |
| **Polyglot Persistence** | PostgreSQL + Redis + MinIO |
| **Circuit Breaker** | Tolerância a falhas nos connectors |

---

## 🐳 Containers Docker

```
┌─────────────────────────────────────────────────────────────────────┐
│                        chat4all-network                              │
├─────────────────────────────────────────────────────────────────────┤
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ chat4all-web │  │chat4all-gate │  │ chat4all-api │              │
│  │    :4200     │  │    :8000     │  │  :8080/:50051│              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │chat4all-ws   │  │ chat4all-    │  │ chat4all-    │              │
│  │   :8081      │  │  postgres    │  │    redis     │              │
│  └──────────────┘  │    :5432     │  │    :6379     │              │
│                    └──────────────┘  └──────────────┘              │
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ chat4all-    │  │ chat4all-    │  │ chat4all-    │              │
│  │    minio     │  │    kafka     │  │  zookeeper   │              │
│  │ :9001/:9002  │  │ :9092/:9093  │  │    :2181     │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ router-      │  │  whatsapp-   │  │  instagram-  │              │
│  │  worker (N)  │  │connector (N) │  │connector (N) │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
│                                                                      │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ chat4all-    │  │ chat4all-    │  │ chat4all-    │              │
│  │  prometheus  │  │   grafana    │  │   metrics    │              │
│  │    :9090     │  │    :3001     │  │    :8001     │              │
│  └──────────────┘  └──────────────┘  └──────────────┘              │
│                                                                      │
└─────────────────────────────────────────────────────────────────────┘
```

---

> **Nota:** Os diagramas Mermaid são renderizados automaticamente em plataformas compatíveis como GitHub, GitLab, VS Code (com extensão) e diversas ferramentas de documentação.
