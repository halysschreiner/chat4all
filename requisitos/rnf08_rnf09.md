# RNF08 e RNF09 - Testes de Carga e Observabilidade

---

## 1. Resumo dos Requisitos

### RNF08 - Testes de Carga
> - Utilizar ferramentas como k6, Locust ou Gatling para simular múltiplos usuários.
> - Gerar métricas: mensagens/segundo, latência média, taxa de erros.
> - Thresholds:
>   - Latência P95 < 500ms.
>   - Latência P99 < 1000ms.
>   - Taxa de erro < 5%.
> - Armazenar resultados e gráficos.

### RNF09 - Monitoramento e Observabilidade
> - Integrar Prometheus para coleta de métricas.
> - Integrar Grafana para dashboards e visualização.
> - Métricas expostas pelos serviços:
>   - `messages_processed_total`, `messages_per_second`, `latency_ms` (p50, p95, p99)
>   - `errors_total`, `cpu_usage_percent`, `memory_usage_mb`
>   - `active_workers`, `http_requests_total`, `kafka_consumer_lag`
> - Dashboards em tempo real com refresh de 5 segundos.

### Importância Teórica

**Testes de carga** validam que o sistema atende SLAs (Service Level Agreements):
- Latência garante UX aceitável
- Taxa de erro define confiabilidade
- Throughput valida capacidade

**Observabilidade** (diferente de monitoramento) permite entender *por que* o sistema se comporta de certa forma:
- **Logs**: O que aconteceu
- **Metrics**: Quanto aconteceu
- **Traces**: Como aconteceu (fluxo através de serviços)

---

## 2. Fundamentos Teóricos

### 2.1 Métricas de Performance

```
┌─────────────────────────────────────────────────────────────┐
│                    DISTRIBUIÇÃO DE LATÊNCIA                 │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  Requests                                                   │
│     │                                                       │
│  50%├─────────────────────────┐                            │
│     │                         │ P50 = 50ms                  │
│     │                         │ (metade dos requests)       │
│  25%├─────────┐               │                            │
│     │         │               │                            │
│   5%│   ┌─────┤               │    ┌──── P95 = 200ms       │
│   1%│   │     │               │    │  ┌─ P99 = 500ms       │
│     └───┴─────┴───────────────┴────┴──┴─────▶ Latência     │
│       10   50  100   150  200  250  500   1000  ms         │
│                                                             │
│  ⚠️ P99 é onde os problemas aparecem (tail latency)        │
│  ⚠️ Média esconde outliers - prefira percentis             │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 SLI, SLO, SLA

| Termo | Definição | Exemplo no Chat4All |
|-------|-----------|---------------------|
| **SLI** (Indicator) | Métrica medida | Latência HTTP |
| **SLO** (Objective) | Meta interna | P95 < 500ms |
| **SLA** (Agreement) | Contrato com cliente | 99.9% uptime |

### 2.3 Observabilidade: Três Pilares

```
┌─────────────────────────────────────────────────────────────┐
│              TRÊS PILARES DA OBSERVABILIDADE                │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  ┌─────────────────┐  ┌─────────────────┐  ┌─────────────┐ │
│  │      LOGS       │  │    METRICS      │  │   TRACES    │ │
│  ├─────────────────┤  ├─────────────────┤  ├─────────────┤ │
│  │ • Eventos       │  │ • Agregações    │  │ • Req flow  │ │
│  │ • Contexto      │  │ • Séries temp.  │  │ • Latência  │ │
│  │ • Debug         │  │ • Alertas       │  │ • Deps      │ │
│  │                 │  │                 │  │             │ │
│  │ Monolog         │  │ Prometheus      │  │ Jaeger      │ │
│  │ ELK Stack       │  │ Grafana         │  │ Zipkin      │ │
│  └─────────────────┘  └─────────────────┘  └─────────────┘ │
│                                                             │
│  Chat4All implementa: ✅ Logs  ✅ Metrics  ❌ Traces        │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Implementação no Chat4All

### 3.1 RNF08 - Testes de Carga (k6)

#### 3.1.1 Configuração do k6 (`k6-load-test.js`)

**Linhas 45-90 (Cenários)**:
```javascript
export const options = {
    scenarios: {
        // Cenário 1: Teste de mensagens básico
        message_flow: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '30s', target: 10 },   // Ramp up to 10 users
                { duration: '1m', target: 50 },    // Ramp up to 50 users
                { duration: '2m', target: 100 },   // Ramp up to 100 users
                { duration: '2m', target: 100 },   // Stay at 100 users
                { duration: '1m', target: 200 },   // Peak load
                { duration: '1m', target: 200 },   // Sustain peak
                { duration: '30s', target: 0 },    // Ramp down
            ],
            exec: 'messageFlow',
        },
        
        // Cenário 2: Upload de arquivos
        file_upload: {
            executor: 'constant-vus',
            vus: 10,
            duration: '5m',
            startTime: '30s',
            exec: 'fileUploadFlow',
        },
        
        // Cenário 3: Verificação de status (constant arrival rate)
        status_checking: {
            executor: 'constant-arrival-rate',
            rate: 20,           // 20 req/s
            timeUnit: '1s',
            duration: '5m',
            preAllocatedVUs: 20,
            maxVUs: 50,
            startTime: '1m',
            exec: 'statusCheckFlow',
        },
    },
    
    // Thresholds (SLOs)
    thresholds: {
        http_req_duration: ['p(95)<500', 'p(99)<1000'],  // ✅ RNF08
        http_req_failed: ['rate<0.05'],                   // ✅ RNF08: < 5% erros
        messages_success: ['count>1000'],
        file_upload_latency: ['p(95)<10000'],
        message_latency: ['p(95)<500'],
    },
};
```

#### 3.1.2 Métricas Customizadas

**Linhas 17-42**:
```javascript
// Custom metrics - Mensagens
const messagesSent = new Counter('messages_sent');
const messagesSuccess = new Counter('messages_success');
const messagesFailed = new Counter('messages_failed');
const messageLatency = new Trend('message_latency');

// Custom metrics - Arquivos
const filesUploaded = new Counter('files_uploaded');
const filesUploadSuccess = new Counter('files_upload_success');
const fileUploadLatency = new Trend('file_upload_latency');
const fileUploadThroughput = new Trend('file_upload_throughput_mbps');

// Custom metrics - Status
const statusChecks = new Counter('status_checks');
const statusDelivered = new Counter('status_delivered');
const statusRead = new Counter('status_read');

// Custom metrics - WebSocket
const wsConnections = new Gauge('ws_active_connections');
const wsMessages = new Counter('ws_messages_received');
const wsErrors = new Counter('ws_errors');
```

#### 3.1.3 Fluxo de Teste de Mensagens

```javascript
export function messageFlow() {
    group('Message Flow', function() {
        // 1. Autenticar
        let authRes = authenticate();
        if (!authRes.success) {
            authFailures.add(1);
            return;
        }
        
        // 2. Enviar mensagem
        let startTime = Date.now();
        let msgRes = sendMessage(authRes.token, authRes.conversationId);
        let latency = Date.now() - startTime;
        
        messageLatency.add(latency);
        messagesSent.add(1);
        
        // 3. Validar resposta
        let success = check(msgRes, {
            'message sent': (r) => r.status === 200,
            'has message_id': (r) => r.json().message_id !== undefined,
        });
        
        if (success) {
            messagesSuccess.add(1);
        } else {
            messagesFailed.add(1);
        }
        
        sleep(1); // Think time
    });
}
```

#### 3.1.4 Script de Execução (`run-k6-test.sh`)

```bash
#!/bin/bash

# Configuração
export API_BASE_URL="http://localhost:8000"
export WS_URL="ws://localhost:8081"

# Executar k6 com output para JSON e InfluxDB
k6 run \
    --out json=results/k6-output.json \
    --out influxdb=http://localhost:8086/k6 \
    scripts/k6-load-test.js

# Gerar relatório HTML
k6 run \
    --out html=results/k6-report.html \
    scripts/k6-load-test.js
```

### 3.2 RNF09 - Prometheus e Grafana

#### 3.2.1 Configuração do Prometheus (`prometheus.yml`)

**Linhas 33-155 (Scrape configs)**:
```yaml
scrape_configs:
  # API Service - Métricas HTTP
  - job_name: 'api-service'
    static_configs:
      - targets: ['api-service:8080']
        labels:
          service: 'api-service'
          component: 'backend'
    metrics_path: /metrics
    scrape_interval: 10s

  # WebSocket Worker - Conexões ativas
  - job_name: 'websocket-worker'
    static_configs:
      - targets: ['websocket-worker:8081']
        labels:
          service: 'websocket-worker'
          component: 'realtime'
    scrape_interval: 10s

  # Router Worker - Processamento Kafka
  - job_name: 'router-worker'
    static_configs:
      - targets: ['router-worker:9100']
        labels:
          service: 'router-worker'
          component: 'worker'
    scrape_interval: 15s

  # Kafka Exporter - Consumer lag
  - job_name: 'kafka-exporter'
    static_configs:
      - targets: ['kafka-exporter:9308']
        labels:
          service: 'kafka'
          component: 'messaging'
    scrape_interval: 30s

  # PostgreSQL Exporter - Conexões, queries
  - job_name: 'postgres-exporter'
    static_configs:
      - targets: ['postgres-exporter:9187']
        labels:
          service: 'postgres'
          component: 'database'
    scrape_interval: 30s

  # Redis Exporter - Memória, comandos
  - job_name: 'redis-exporter'
    static_configs:
      - targets: ['redis-exporter:9121']
        labels:
          service: 'redis'
          component: 'cache'
    scrape_interval: 15s

  # cAdvisor - CPU/memória por container
  - job_name: 'cadvisor'
    static_configs:
      - targets: ['cadvisor:8080']
        labels:
          service: 'cadvisor'
          component: 'containers'
    scrape_interval: 15s
```

#### 3.2.2 Regras de Alerta (`alert.rules.yml`)

**Linhas 23-95 (Alertas)**:
```yaml
groups:
  - name: availability
    rules:
      # API Service indisponível
      - alert: APIServiceDown
        expr: up{job="api-service"} == 0
        for: 1m
        labels:
          severity: critical
        annotations:
          summary: "API Service está indisponível"

      # WebSocket Worker indisponível
      - alert: WebSocketWorkerDown
        expr: up{job="websocket-worker"} == 0
        for: 1m
        labels:
          severity: critical

      # Router Worker indisponível (warning porque há múltiplos)
      - alert: RouterWorkerDown
        expr: up{job="router-worker"} == 0
        for: 1m
        labels:
          severity: warning

  - name: performance
    rules:
      # Alta latência (P95 > 500ms) - RNF08
      - alert: HighHTTPLatency
        expr: histogram_quantile(0.95, sum(rate(chat4all_http_request_duration_seconds_bucket[5m])) by (le)) > 0.5
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "P95 latência HTTP > 500ms"
          description: "Valor atual: {{ $value }}s"

      # Alta taxa de erros (> 5%) - RNF08
      - alert: HighErrorRate
        expr: sum(rate(chat4all_http_requests_total{status=~"5.."}[5m])) / sum(rate(chat4all_http_requests_total[5m])) > 0.05
        for: 5m
        labels:
          severity: warning
        annotations:
          summary: "Taxa de erros HTTP > 5%"
```

#### 3.2.3 Dashboard Grafana (`chat4all-complete.json`)

```json
{
  "dashboard": {
    "title": "Chat4All - Complete Overview",
    "refresh": "5s",
    "panels": [
      {
        "title": "Mensagens por Segundo",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(chat4all_messages_total[1m])",
            "legendFormat": "{{instance}}"
          }
        ]
      },
      {
        "title": "Latência P95",
        "type": "singlestat",
        "targets": [
          {
            "expr": "histogram_quantile(0.95, sum(rate(chat4all_http_duration_bucket[5m])) by (le))"
          }
        ],
        "thresholds": "500,1000",
        "colors": ["green", "yellow", "red"]
      },
      {
        "title": "Taxa de Erros",
        "type": "gauge",
        "targets": [
          {
            "expr": "sum(rate(chat4all_http_requests_total{status=~\"5..\"}[5m])) / sum(rate(chat4all_http_requests_total[5m])) * 100"
          }
        ],
        "thresholds": [
          { "value": 0, "color": "green" },
          { "value": 5, "color": "red" }
        ]
      },
      {
        "title": "Kafka Consumer Lag",
        "type": "graph",
        "targets": [
          {
            "expr": "kafka_consumer_group_lag{group=\"router-worker-group\"}",
            "legendFormat": "{{partition}}"
          }
        ]
      },
      {
        "title": "Workers Ativos",
        "type": "singlestat",
        "targets": [
          {
            "expr": "count(up{job=\"router-worker\"} == 1)"
          }
        ]
      },
      {
        "title": "CPU por Container",
        "type": "graph",
        "targets": [
          {
            "expr": "rate(container_cpu_usage_seconds_total{name=~\"chat4all.*\"}[1m]) * 100",
            "legendFormat": "{{name}}"
          }
        ]
      },
      {
        "title": "Memória por Container",
        "type": "graph",
        "targets": [
          {
            "expr": "container_memory_usage_bytes{name=~\"chat4all.*\"} / 1024 / 1024",
            "legendFormat": "{{name}}"
          }
        ]
      },
      {
        "title": "Conexões WebSocket Ativas",
        "type": "singlestat",
        "targets": [
          {
            "expr": "chat4all_websocket_connections_active"
          }
        ]
      }
    ]
  }
}
```

#### 3.2.4 Docker Compose - Stack de Monitoramento

```yaml
# Prometheus
prometheus:
  image: prom/prometheus:latest
  container_name: chat4all-prometheus
  ports:
    - "9090:9090"
  volumes:
    - ./prometheus/prometheus.yml:/etc/prometheus/prometheus.yml
    - ./prometheus/alert.rules.yml:/etc/prometheus/alert.rules.yml
    - prometheus-data:/prometheus
  command:
    - '--config.file=/etc/prometheus/prometheus.yml'
    - '--storage.tsdb.path=/prometheus'

# Grafana
grafana:
  image: grafana/grafana:latest
  container_name: chat4all-grafana
  ports:
    - "3001:3000"
  environment:
    - GF_SECURITY_ADMIN_PASSWORD=admin
  volumes:
    - ./grafana/provisioning:/etc/grafana/provisioning
    - ./grafana/dashboards:/etc/grafana/provisioning/dashboards
    - grafana-data:/var/lib/grafana
  depends_on:
    - prometheus
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Sub-requisito | Status | Evidência |
|---------------|--------|-----------|
| **RNF08**: k6/Locust/Gatling | ✅ | k6 em `k6-load-test.js` |
| **RNF08**: Métricas msg/s, latência, erros | ✅ | Custom metrics no k6 |
| **RNF08**: P95 < 500ms | ✅ | `thresholds: p(95)<500` |
| **RNF08**: P99 < 1000ms | ✅ | `thresholds: p(99)<1000` |
| **RNF08**: Erro < 5% | ✅ | `thresholds: rate<0.05` |
| **RNF08**: Armazenar resultados | ✅ | `--out json=results/` |
| **RNF09**: Prometheus | ✅ | `prometheus/prometheus.yml` |
| **RNF09**: Grafana | ✅ | Dashboards em `grafana/dashboards/` |
| **RNF09**: messages_processed_total | ⚠️ | Parcial (via k6 metrics) |
| **RNF09**: kafka_consumer_lag | ✅ | Kafka Exporter |
| **RNF09**: Refresh 5s | ✅ | `"refresh": "5s"` |

### 4.2 Métricas Expostas

| Métrica Requisitada | Fonte | Status |
|---------------------|-------|--------|
| `messages_processed_total` | API Service `/metrics` | ⚠️ Precisa implementar |
| `messages_per_second` | Prometheus rate() | ✅ Calculado |
| `latency_ms` (p50, p95, p99) | k6 + Prometheus histogram | ✅ |
| `errors_total` | HTTP status codes | ✅ |
| `cpu_usage_percent` | cAdvisor | ✅ |
| `memory_usage_mb` | cAdvisor | ✅ |
| `active_workers` | Prometheus up{} | ✅ |
| `http_requests_total` | API Service | ⚠️ Precisa implementar |
| `kafka_consumer_lag` | Kafka Exporter | ✅ |

### 4.3 Pontos Fortes

1. **k6 moderno**: Scripts JavaScript, thresholds nativos
2. **Múltiplos cenários**: message_flow, file_upload, status_checking
3. **Alertas proativos**: HighHTTPLatency, HighErrorRate
4. **cAdvisor**: Métricas de containers sem instrumentação manual

### 4.4 Limitações Identificadas

#### Limitação 1: Serviços Sem Métricas Expostas

**Problema**: API Service não expõe `/metrics` endpoint.

```php
// api-service não tem Prometheus client implementado
// Métricas só vêm via k6 (externo)
```

**Solução**: Adicionar prometheus_php_client:
```php
use Prometheus\CollectorRegistry;
use Prometheus\RenderTextFormat;

$registry = new CollectorRegistry(new InMemory());

// Counter de mensagens
$messagesCounter = $registry->getOrRegisterCounter(
    'chat4all',
    'messages_processed_total',
    'Total de mensagens processadas',
    ['status']
);

// Rota /metrics
$app->get('/metrics', function($req, $res) use ($registry) {
    $renderer = new RenderTextFormat();
    return $res->withBody($renderer->render($registry->getMetricFamilySamples()));
});
```

#### Limitação 2: Sem Distributed Tracing

**Problema**: Não é possível rastrear request através de múltiplos serviços.

**Solução**: OpenTelemetry ou Jaeger:
```php
// Propagação de trace context via headers
$traceId = $_SERVER['HTTP_X_TRACE_ID'] ?? uniqid();
$span = $tracer->startSpan('SendMessage', ['traceId' => $traceId]);
// ...
$span->finish();
```

#### Limitação 3: Testes Focados em Happy Path

**Problema**: k6 testa principalmente cenários de sucesso.

**Solução**: Adicionar cenários de falha:
```javascript
export function chaosScenario() {
    // Simular falhas
    if (Math.random() < 0.1) {
        // 10% de requests para endpoint inexistente
        http.get(`${BASE_URL}/v1/nonexistent`);
    }
    
    // Token expirado
    if (Math.random() < 0.05) {
        sendMessageWithInvalidToken();
    }
}
```

### 4.5 Perguntas Socráticas para Aprofundamento

1. **Sobre Testes de Carga**:
   - "P95 de 500ms é aceitável? Para qual tipo de usuário? Mobile em 3G?"
   - "100 VUs simultâneos representa qual carga real? Quantos usuários ativos?"
   - "Como você testaria a capacidade máxima do sistema?"

2. **Sobre Observabilidade**:
   - "Logs estruturados ou texto livre? Por quê?"
   - "Qual a diferença entre alertar em P95 vs média?"
   - "Consumer lag de quanto é aceitável? 100? 1000? 10000?"

3. **Sobre SLOs**:
   - "Quem define os thresholds? Negócio ou engenharia?"
   - "O que acontece se SLO for violado? Alerta? Escalation?"

---

## 5. Referências Teóricas

- **Google SRE Book** - *Service Level Objectives* (Capítulo 4)
- **Brendan Gregg** - *Systems Performance* (Latency analysis)
- **Cindy Sridharan** - *Distributed Systems Observability*
- **k6 Documentation** - Load testing best practices
- **Prometheus Documentation** - Recording rules, alerting
