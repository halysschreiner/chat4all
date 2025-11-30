# Implementation Plan: Trabalho Final - Escalabilidade e Relatório

**Branch**: `001-final-scalability-report` | **Date**: 2025-11-29 | **Spec**: [spec.md](./spec.md)
**Input**: Feature specification from `/specs/001-final-scalability-report/spec.md`

**User Requirements**: Sistema deve usar **WebSocket** ao invés de polling para notificações em tempo real.

## Summary

Implementar funcionalidades finais do Chat4All para Semanas 5-8 do curso de Sistemas Distribuídos da UFG:
- **Object Storage**: Upload/download de arquivos até 2GB via MinIO com resumable upload
- **Connectors Mock**: WhatsApp e Instagram simulados consumindo de tópicos Kafka específicos
- **WebSocket**: Notificações em tempo real para status de mensagens (SENT→DELIVERED→READ)
- **Escalabilidade**: Múltiplas instâncias de workers com demonstração de throughput
- **Monitoramento**: Dashboards Grafana com métricas Prometheus
- **Tolerância a Falhas**: Scripts de failover com recuperação automática

## Technical Context

**Language/Version**: PHP 8.3 (Backend), TypeScript/Angular 17 (Frontend)  
**Primary Dependencies**: Slim Framework 4, RdKafka, Ratchet (WebSocket), AWS SDK (MinIO S3), Angular  
**Storage**: PostgreSQL 16, Redis 7, MinIO (S3-compatible)  
**Message Broker**: Apache Kafka 7.5.0  
**Testing**: PHPUnit, k6 (carga), Jest/Jasmine (Angular)  
**Target Platform**: Docker containers (Linux)  
**Project Type**: Web application (backend + frontend + workers + connectors)  
**Performance Goals**: 100 msg/s com 3 workers, latência < 2s para entrega  
**Constraints**: Upload até 2GB, failover < 30s, zero perda de mensagens  
**Scale/Scope**: Ambiente local/desenvolvimento, demonstração acadêmica

## Constitution Check

*GATE: Must pass before Phase 0 research. Re-check after Phase 1 design.*

| Principle | Status | Evidence |
|-----------|--------|----------|
| **I. Código Didático e Legível** | ✅ PASS | Código com comentários explicando conceitos de SD |
| **II. Arquitetura de Microsserviços Documentada** | ✅ PASS | Cada serviço tem README, comunicação via gRPC/REST/Kafka |
| **III. Demonstrabilidade Prática** | ✅ PASS | docker-compose up funciona, scripts de demo existirão |
| **IV. Tolerância a Falhas Observável** | ✅ PASS | Scripts de failover com recuperação documentada |
| **V. Escalabilidade Horizontal Comprovada** | ✅ PASS | docker-compose --scale, métricas de throughput |

**Re-check after Phase 1**: All principles maintained. WebSocket addition improves demonstrability (real-time feedback).

## Project Structure

### Documentation (this feature)

```text
specs/001-final-scalability-report/
├── plan.md              # This file
├── research.md          # Phase 0 output
├── data-model.md        # Phase 1 output
├── quickstart.md        # Phase 1 output
├── contracts/           # Phase 1 output (OpenAPI specs)
│   ├── files-api.yaml
│   ├── callbacks-api.yaml
│   └── websocket-api.yaml
└── tasks.md             # Phase 2 output
```

### Source Code (repository root)

```text
# Backend API Service
services/api-service/
├── src/
│   ├── Controller/
│   │   ├── FileController.php      # Upload/download endpoints
│   │   ├── CallbackController.php  # Webhook callbacks dos connectors
│   │   └── WebSocketController.php # WebSocket handler
│   ├── Service/
│   │   ├── FileService.php         # Object Storage operations
│   │   ├── WebSocketService.php    # Connection management
│   │   └── NotificationService.php # Push notifications via WebSocket
│   └── WebSocket/
│       ├── Server.php              # Ratchet WebSocket server
│       └── MessageHandler.php      # WebSocket message processor
└── tests/

# Workers
workers/
├── router-worker/                  # Existing - routes messages
│   └── src/
│       └── KafkaNotifier.php       # Publishes status changes to Kafka
└── websocket-worker/               # NEW - broadcasts to clients
    ├── Dockerfile
    ├── composer.json
    └── src/
        ├── WebSocketServer.php
        └── KafkaConsumer.php       # Consumes status-updates topic

# Connectors Mock
connectors/
├── whatsapp-mock/
│   └── src/
│       └── CallbackSender.php      # Sends delivery/read callbacks
└── instagram-mock/
    └── src/
        └── CallbackSender.php

# Frontend Angular
frontend/
└── src/app/
    ├── services/
    │   ├── websocket.service.ts    # NEW - WebSocket client
    │   └── chat.service.ts         # Modified - use WebSocket
    └── components/
        └── chat/                   # Status indicators (✓, ✓✓, blue ✓✓)

# Tests & Scripts
finalTest/
├── scripts/
│   ├── test-file-upload.sh
│   ├── test-websocket.sh
│   └── test-failover.sh
└── results/

# Monitoring
grafana/
└── dashboards/
    └── chat4all-dashboard.json     # Updated with new metrics
```

**Structure Decision**: Web application with microservices architecture. Backend (PHP/Slim), Frontend (Angular), Workers (PHP/Kafka), dedicated WebSocket worker for real-time notifications. Connectors remain as separate consumers.

## Complexity Tracking

> No violations to justify - design follows constitution principles.
