# RNF13 - Stack Tecnológica

---

## 1. Resumo do Requisito

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

### Importância Teórica

A escolha da stack tecnológica é uma **decisão arquitetural crítica**. Cada tecnologia traz trade-offs de performance, manutenibilidade, curva de aprendizado e ecossistema. Decisões erradas aqui propagam por todo o ciclo de vida do projeto.

---

## 2. Fundamentos Teóricos

### 2.1 Critérios de Escolha de Stack

```
┌─────────────────────────────────────────────────────────────┐
│            CRITÉRIOS DE SELEÇÃO DE TECNOLOGIA               │
├─────────────────────────────────────────────────────────────┤
│                                                             │
│  1. REQUISITOS FUNCIONAIS                                   │
│     • A tecnologia resolve o problema?                      │
│     • Suporta os casos de uso esperados?                   │
│                                                             │
│  2. REQUISITOS NÃO-FUNCIONAIS                               │
│     • Performance adequada?                                 │
│     • Escalabilidade horizontal/vertical?                  │
│     • Segurança (CVEs, patches)?                           │
│                                                             │
│  3. FATORES HUMANOS                                         │
│     • Equipe conhece a tecnologia?                         │
│     • Curva de aprendizado aceitável?                      │
│     • Comunidade ativa? Documentação?                      │
│                                                             │
│  4. FATORES OPERACIONAIS                                    │
│     • Custo de infraestrutura?                             │
│     • Facilidade de deploy/monitoramento?                  │
│     • Vendor lock-in?                                      │
│                                                             │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Justificativas por Componente

| Tecnologia | Justificativa | Alternativas Consideradas |
|------------|---------------|---------------------------|
| **PHP 8.3** | Requisito da disciplina, JIT compiler, typed properties | Node.js, Go, Python |
| **Angular 17** | Framework robusto, TypeScript nativo, CLI poderoso | React, Vue, Svelte |
| **gRPC** | Performance 10x REST, tipagem forte, streaming | REST, GraphQL |
| **PostgreSQL** | ACID, extensões (UUID, JSON), maturidade | MySQL, MongoDB |
| **Redis** | Cache em memória, pub/sub, data structures | Memcached, KeyDB |
| **MinIO** | S3-compatible, self-hosted, sem vendor lock-in | AWS S3, local filesystem |
| **Kafka** | Durabilidade, replay, particionamento | RabbitMQ, Redis Streams |
| **Prometheus** | Pull-based, PromQL, CNCF standard | InfluxDB, Datadog |
| **Grafana** | Visualização flexível, alertas | Kibana, custom dashboards |

---

## 3. Implementação no Chat4All

### 3.1 Tabela de Conformidade

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

### 3.2 Versões em docker-compose.yml

```yaml
services:
  postgres:
    image: postgres:16-alpine     # PostgreSQL 16

  redis:
    image: redis:7-alpine         # Redis 7

  kafka:
    image: confluentinc/cp-kafka:7.5.0  # Kafka 7.5.0

  minio:
    image: minio/minio:latest     # MinIO latest

  prometheus:
    image: prom/prometheus:latest # Prometheus latest

  grafana:
    image: grafana/grafana:latest # Grafana latest
```

### 3.3 Dependências PHP (`composer.json`)

```json
{
  "require": {
    "php": "^8.3",
    "grpc/grpc": "^1.57",
    "google/protobuf": "^3.24",
    "cboden/ratchet": "^0.4",
    "react/event-loop": "^1.4",
    "predis/predis": "^2.2"
  }
}
```

### 3.4 Dependências Angular (`package.json`)

```json
{
  "dependencies": {
    "@angular/core": "^17.0.0",
    "@angular/common": "^17.0.0",
    "@angular/router": "^17.0.0",
    "@angular/forms": "^17.0.0",
    "rxjs": "~7.8.0"
  }
}
```

---

## 4. Análise Crítica

### 4.1 Conformidade com Requisitos

| Componente | Status | Observação |
|------------|--------|------------|
| PHP 8.3 | ✅ | Versão exata conforme requisito |
| Angular 17 | ✅ | Versão exata conforme requisito |
| gRPC | ✅ | Implementado com Protobuf |
| PostgreSQL 16 | ✅ | Versão exata conforme requisito |
| Redis 7 | ✅ | Versão exata conforme requisito |
| MinIO | ✅ | Latest (sem versão específica no requisito) |
| Kafka 7.5.0 | ✅ | Versão exata conforme requisito |
| Prometheus | ✅ | Latest |
| Grafana | ✅ | Latest |
| Docker | ✅ | Todos serviços containerizados |
| Ratchet | ✅ | WebSocket PHP |

### 4.2 Pontos Fortes

1. **Stack moderna**: Todas versões recentes (2023-2024)
2. **Consistência**: Todas tecnologias têm propósito claro
3. **Observabilidade**: Prometheus + Grafana para métricas
4. **Sem vendor lock-in**: MinIO (S3-compatible), Kafka (open source)

### 4.3 Limitações Identificadas

#### Limitação 1: PHP para Alta Concorrência

**Problema**: PHP tradicional não é ideal para WebSocket de alta escala.

**Mitigação**: Ratchet com ReactPHP (event loop) resolve parcialmente.

**Alternativa**: Swoole para PHP verdadeiramente assíncrono.

#### Limitação 2: "Latest" Tags em Produção

**Problema**: Tags `latest` podem quebrar em updates.

```yaml
prometheus:
  image: prom/prometheus:latest  # Pode mudar a qualquer momento!
```

**Solução**: Fixar versões específicas:
```yaml
prometheus:
  image: prom/prometheus:v2.47.0
```

#### Limitação 3: Curva de Aprendizado

**Problema**: Stack complexa (11 tecnologias) exige conhecimento amplo.

**Mitigação**: Documentação extensiva e containers simplificam setup.

### 4.4 Perguntas Socráticas para Aprofundamento

1. "Por que PHP e não Go/Node.js para um sistema de mensageria?"
2. "Kafka vs RabbitMQ: qual o trade-off de persistência vs simplicidade?"
3. "MinIO é S3-compatible. O que acontece se migrar para AWS S3?"
4. "Angular 17 tem Signals. Você está usando? Por que sim/não?"
5. "Redis para cache E pub/sub. Isso é Single Responsibility Principle?"

---

## 5. Referências Teóricas

- **PHP Documentation** - php.net
- **Angular Documentation** - angular.io
- **gRPC Documentation** - grpc.io
- **PostgreSQL Documentation** - postgresql.org
- **Apache Kafka Documentation** - kafka.apache.org
- **Prometheus Documentation** - prometheus.io
- **12-Factor App** - Heroku (Technology agnostic principles)
