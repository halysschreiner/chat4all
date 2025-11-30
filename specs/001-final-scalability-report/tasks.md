# Tasks: Trabalho Final - Escalabilidade e Relatório

**Input**: Design documents from `/specs/001-final-scalability-report/`  
**Prerequisites**: plan.md ✅, spec.md ✅, research.md ✅, data-model.md ✅, contracts/ ✅

**Tests**: Not explicitly requested - test scripts included for demonstration purposes only.

**Organization**: Tasks grouped by user story (9 stories: US1-US9) with priorities P1, P2, P3.

## Format: `[ID] [P?] [Story?] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Maps to user stories from spec.md (US1-US9)
- All paths are relative to repository root

## Path Conventions

Based on plan.md structure:
- **Backend API**: `services/api-service/src/`
- **Workers**: `workers/`
- **Connectors**: `connectors/`
- **Frontend**: `frontend/src/app/`
- **Scripts**: `finalTest/scripts/`
- **Grafana**: `grafana/dashboards/`

---

## Phase 1: Setup (Shared Infrastructure)

**Purpose**: Dependencies and project structure for new components

- [X] T001 Add AWS SDK and Ratchet dependencies to `services/api-service/composer.json`
- [X] T002 Add prometheus_client_php dependency to `services/api-service/composer.json`
- [X] T003 [P] Create websocket-worker project structure in `workers/websocket-worker/`
- [X] T004 [P] Create `workers/websocket-worker/Dockerfile`
- [X] T005 [P] Create `workers/websocket-worker/composer.json` with Ratchet and RdKafka dependencies
- [X] T006 Add websocket-worker service to `docker-compose.yml` (port 8081)
- [X] T007 [P] Create Kafka topics configuration: `status-updates`, `whatsapp.messages`, `instagram.messages`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Database migrations and core services that ALL user stories depend on

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] T008 Create SQL migration for `files` table in `scripts/migrations/001_create_files_table.sql`
- [X] T009 [P] Create SQL migration for `delivery_callbacks` table in `scripts/migrations/003_create_delivery_callbacks_table.sql`
- [X] T010 Apply migrations to PostgreSQL via `scripts/init-db.sql` update
- [X] T011 [P] Create `services/api-service/src/Service/MinioService.php` for S3 operations
- [X] T012 [P] Create `services/api-service/src/Service/NotificationService.php` for WebSocket/Redis pub-sub
- [X] T013 Create Redis connection helper in `services/api-service/src/Service/RedisService.php`
- [X] T014 [P] Create metrics helper in `services/api-service/src/Service/MetricsService.php`

**Checkpoint**: Foundation ready - database has new tables, core services initialized

---

## Phase 3: User Story 1 - Upload e Download de Arquivos Grandes (Priority: P1) 🎯 MVP

**Goal**: Users can upload files up to 2GB via multipart upload and download via presigned URLs

**Independent Test**: `curl` upload 100MB file, verify in MinIO, get presigned URL, download succeeds

### Implementation for User Story 1

- [X] T015 [P] [US1] Create File entity class in `services/api-service/src/Entity/File.php`
- [X] T016 [P] [US1] Create FileRepository in `services/api-service/src/Repository/FileRepository.php`
- [X] T017 [US1] Implement FileService with multipart upload logic in `services/api-service/src/Service/FileService.php`
- [X] T018 [US1] Create FileController with upload endpoints in `services/api-service/src/Controller/FileController.php`
- [X] T019 [US1] Add routes: POST `/v1/files/upload/initiate`, PUT `/v1/files/upload/{uploadId}/part/{partNumber}`, POST `/v1/files/upload/{uploadId}/complete` in `services/api-service/public/index.php`
- [X] T020 [US1] Implement GET `/v1/files/{fileId}` for metadata in `services/api-service/src/Controller/FileController.php`
- [X] T021 [US1] Implement GET `/v1/files/{fileId}/download` for presigned URL generation
- [X] T022 [US1] Add file size validation (max 2GB) and checksum verification
- [X] T023 [P] [US1] Create test script `finalTest/scripts/test-file-upload.sh`

**Checkpoint**: Upload/download functional - test with 100MB file

---

## Phase 4: User Story 2 - Mensagens com Anexos (Priority: P1)

**Goal**: Users can send messages with file attachments (type: "file", file_id)

**Independent Test**: Create message with type:"file" and valid file_id, verify message links to file

### Implementation for User Story 2

- [X] T024 [US2] Modify MessageController to accept `file_id` in POST `/v1/messages` in `services/api-service/src/Controller/MessageController.php`
- [X] T025 [US2] Update MessageService to validate file_id exists and belongs to user in `services/api-service/src/Service/MessageService.php`
- [X] T026 [US2] Add file metadata to message response (filename, size, download_url)
- [X] T027 [US2] Update Kafka message schema to include file_id in `workers/router-worker/src/MessageProcessor.php`
- [X] T028 [P] [US2] Create test script `finalTest/scripts/test-message-with-file.sh`

**Checkpoint**: Messages with attachments working - file_id correctly linked

---

## Phase 5: User Story 3 - Entrega de Mensagens via Connectors Mock (Priority: P1)

**Goal**: Messages are delivered to WhatsApp/Instagram mock connectors via Kafka

**Independent Test**: Send message, see "[WhatsApp] Entregue a usuário X" in connector logs

### Implementation for User Story 3

- [X] T029 [US3] Update router-worker to route messages to platform-specific Kafka topics in `workers/router-worker/src/MessageProcessor.php`
- [X] T030 [US3] Add platform detection logic (based on conversation metadata or user settings)
- [X] T031 [P] [US3] Implement CallbackSender in `connectors/whatsapp-mock/src/CallbackSender.php`
- [X] T032 [P] [US3] Implement CallbackSender in `connectors/instagram-mock/src/CallbackSender.php`
- [X] T033 [US3] Update WhatsApp connector consumer to call CallbackSender after processing in `connectors/whatsapp-mock/src/MessageProcessor.php`
- [X] T034 [US3] Update Instagram connector consumer to call CallbackSender after processing in `connectors/instagram-mock/src/MessageProcessor.php`
- [X] T035 [US3] Add simulated delay (1-3s DELIVERED, 3-8s READ) in callback senders
- [X] T036 [P] [US3] Create connector README with concepts explanation in `connectors/README.md`

**Checkpoint**: Connectors consuming messages and logging delivery

---

## Phase 6: User Story 4 - Controle de Status de Mensagem (Priority: P2)

**Goal**: Message status transitions (SENT→DELIVERED→READ) with WebSocket notifications

**Independent Test**: Send message, observe WebSocket receives status_update events

### Implementation for User Story 4

- [X] T037 [US4] Create CallbackController in `services/api-service/src/Controller/CallbackController.php`
- [X] T038 [US4] Add routes: POST `/v1/callbacks/whatsapp`, POST `/v1/callbacks/instagram` in `services/api-service/public/index.php`
- [X] T039 [US4] Implement callback processing: validate, update DB status, publish to Kafka status-updates
- [X] T040 [US4] Create DeliveryCallback entity in `services/api-service/src/Entity/DeliveryCallback.php`
- [X] T041 [P] [US4] Create WebSocket server in `workers/websocket-worker/src/WebSocketServer.php` (exists as StatusNotificationHandler)
- [X] T042 [US4] Implement Redis pub-sub consumer in WebSocket worker `workers/websocket-worker/src/RedisPubSubConsumer.php` (exists as RedisSubscriber)
- [X] T043 [US4] Create Kafka consumer for status-updates topic in `workers/websocket-worker/src/KafkaStatusConsumer.php`
- [X] T044 [US4] Implement connection management (subscribe/unsubscribe) in WebSocket server (in StatusNotificationHandler)
- [X] T045 [US4] Broadcast status updates to subscribed clients (in StatusNotificationHandler)
- [X] T046 [P] [US4] Create Angular WebSocketService in `frontend/src/app/services/websocket.service.ts`
- [X] T047 [US4] Integrate WebSocketService with ChatService in `frontend/src/app/services/chat.service.ts`
- [X] T048 [US4] Update chat component to show status indicators (✓, ✓✓, blue ✓✓) in `frontend/src/app/components/chat/`
- [X] T049 [P] [US4] Create test script `finalTest/scripts/test-websocket.sh`

**Checkpoint**: Status updates flow from connector → API → WebSocket → Frontend

---

## Phase 7: User Story 5 - Escalabilidade Horizontal de Workers (Priority: P2)

**Goal**: Multiple worker instances increase throughput, Kafka distributes load

**Independent Test**: Scale to 3 workers, verify all consume messages, throughput increases

### Implementation for User Story 5

- [X] T050 [US5] Verify router-worker uses consistent consumer group ID in `workers/router-worker/consumer.php`
- [X] T051 [US5] Verify connector consumer group IDs are correctly configured
- [X] T052 [US5] Add Kafka partitions (3+) for messages topic to enable parallelism
- [X] T053 [P] [US5] Document scaling commands in `docs/SCALING.md`
- [X] T054 [P] [US5] Create scaling test script `finalTest/scripts/test-horizontal-scaling.sh`

**Checkpoint**: `docker-compose --scale router-worker=3` works, load distributed

---

## Phase 8: User Story 6 - Tolerância a Falhas (Priority: P2)

**Goal**: System recovers from worker failures without message loss

**Independent Test**: Kill worker during load, verify no messages lost, recovery < 30s

### Implementation for User Story 6

- [X] T055 [US6] Ensure Kafka manual commit (not auto-commit) in router-worker `workers/router-worker/src/KafkaConsumer.php`
- [X] T056 [US6] Ensure Kafka manual commit in connectors
- [X] T057 [US6] Add graceful shutdown handler in workers (commit before exit)
- [X] T058 [US6] Configure Kafka session timeout for faster rebalance (session.timeout.ms=10000)
- [X] T059 [P] [US6] Create failover test script `finalTest/scripts/test-failover.sh`
- [X] T060 [P] [US6] Document failover behavior in `docs/FAULT_TOLERANCE.md`

**Checkpoint**: Worker failure → rebalance → no message loss

---

## Phase 9: User Story 7 - Testes de Carga e Métricas (Priority: P2)

**Goal**: k6 load tests with metrics collection (throughput, latency, errors)

**Independent Test**: Run k6, see metrics in console and stored results

### Implementation for User Story 7

- [X] T061 [US7] Update k6 load test script with file upload scenarios in `finalTest/scripts/k6-load-test.js`
- [X] T062 [US7] Add k6 scenarios: message sending, file upload, status checking
- [X] T063 [US7] Configure k6 to output JSON results to `finalTest/results/`
- [X] T064 [P] [US7] Create metrics exporter endpoint `/metrics` in `services/api-service/public/index.php`
- [X] T065 [US7] Implement Prometheus metrics: messages_processed_total, files_uploaded_total, latency histograms
- [X] T066 [P] [US7] Create run script `finalTest/scripts/run-load-tests.sh` with different configurations

**Checkpoint**: k6 runs successfully, metrics collected

---

## Phase 10: User Story 8 - Monitoramento em Tempo Real (Priority: P3)

**Goal**: Grafana dashboards showing real-time system metrics

**Independent Test**: Access Grafana, see updating graphs during load test

### Implementation for User Story 8

- [X] T067 [US8] Update Prometheus config to scrape api-service metrics in `prometheus/prometheus.yml`
- [X] T068 [US8] Add scrape config for websocket-worker metrics
- [X] T069 [P] [US8] Create Grafana dashboard JSON in `grafana/dashboards/chat4all-complete.json`
- [X] T070 [US8] Add panels: messages/second, latency percentiles, active WebSocket connections
- [X] T071 [US8] Add panels: Kafka consumer lag, error rates, file uploads
- [X] T072 [US8] Configure dashboard auto-refresh (5 seconds)
- [X] T073 [P] [US8] Add alerting rules for error rate > 5% in `prometheus/alert.rules.yml`

**Checkpoint**: Grafana shows live metrics, alerts fire on errors

---

## Phase 11: User Story 9 - Demonstração Prática Completa (Priority: P3)

**Goal**: Complete demo script showing all distributed systems concepts

**Independent Test**: Run demo script, all steps succeed, screenshot-ready

### Implementation for User Story 9

- [X] T074 [US9] Create comprehensive demo script `finalTest/scripts/full-demo.sh`
- [X] T075 [US9] Demo step 1: User registration and authentication
- [X] T076 [US9] Demo step 2: Create conversation
- [X] T077 [US9] Demo step 3: Send text message, observe status updates via WebSocket
- [X] T078 [US9] Demo step 4: Upload 100MB file
- [X] T079 [US9] Demo step 5: Send message with file attachment
- [X] T080 [US9] Demo step 6: Scale workers and show load distribution
- [X] T081 [US9] Demo step 7: Simulate failure and recovery
- [X] T082 [US9] Demo step 8: Show Grafana dashboards
- [X] T083 [P] [US9] Create demo video script/storyboard in `docs/DEMO_SCRIPT.md`

**Checkpoint**: Full demo runs end-to-end in < 5 minutes

---

## Phase 12: Polish & Cross-Cutting Concerns

**Purpose**: Documentation, cleanup, final validation

- [X] T084 [P] Update `README.md` with new features and quick start
- [X] T085 [P] Update `docs/API_DOCUMENTATION.md` with new endpoints
- [X] T086 [P] Create `docs/WEBSOCKET_GUIDE.md` with connection examples
- [X] T087 Add educational comments explaining SD concepts in all new files
- [X] T088 [P] Update `finalReport/README.md` with test results and screenshots
- [X] T089 Run `quickstart.md` validation - all steps must work
- [X] T090 Final docker-compose test: `docker-compose down -v && docker-compose up -d`

---

## Dependencies & Execution Order

### Phase Dependencies

```
Phase 1 (Setup)
      │
      ▼
Phase 2 (Foundational) ─── BLOCKS ALL USER STORIES
      │
      ├──────────────────────────────────────────┐
      │                                          │
      ▼                                          ▼
Phase 3 (US1: Files)                    Phase 5 (US3: Connectors)
      │                                          │
      ▼                                          │
Phase 4 (US2: Messages+Files)                    │
      │                                          │
      └──────────────┬───────────────────────────┘
                     │
                     ▼
            Phase 6 (US4: Status + WebSocket)
                     │
      ┌──────────────┼──────────────┬──────────────┐
      │              │              │              │
      ▼              ▼              ▼              ▼
Phase 7 (US5)  Phase 8 (US6)  Phase 9 (US7)  Phase 10 (US8)
(Scaling)      (Failover)    (Load Tests)   (Monitoring)
      │              │              │              │
      └──────────────┴──────────────┴──────────────┘
                     │
                     ▼
            Phase 11 (US9: Full Demo)
                     │
                     ▼
            Phase 12 (Polish)
```

### User Story Dependencies

| Story | Can Start After | Notes |
|-------|-----------------|-------|
| US1 (Files) | Phase 2 | No dependencies |
| US2 (Messages+Files) | US1 complete | Needs FileService |
| US3 (Connectors) | Phase 2 | Parallel with US1 |
| US4 (Status+WS) | US2, US3 complete | Needs callbacks from connectors |
| US5 (Scaling) | US4 complete | Needs working message flow |
| US6 (Failover) | US4 complete | Needs working message flow |
| US7 (Load Tests) | US4 complete | Needs working message flow |
| US8 (Monitoring) | US7 complete | Needs metrics exposed |
| US9 (Demo) | US1-US8 complete | Integrates everything |

### Parallel Opportunities Per Phase

**Phase 1 Setup**:
- T003, T004, T005 (websocket-worker structure) → parallel
- T007 (Kafka topics) → parallel

**Phase 2 Foundational**:
- T008, T009 (migrations) → parallel
- T011, T012, T014 (services) → parallel

**Phase 3 US1**:
- T015, T016 (File entity/repo) → parallel
- T023 (test script) → parallel after T021

**Phase 5 US3**:
- T031, T032 (CallbackSenders) → parallel

**Phase 6 US4**:
- T041 (WebSocket server), T046 (Angular service) → parallel

**Phase 9-10**:
- US7 and US8 can run in parallel once US4 is complete

---

## Summary

| Phase | Tasks | Priority Stories |
|-------|-------|------------------|
| Setup | T001-T007 | - |
| Foundational | T008-T014 | - |
| US1: Files | T015-T023 | P1 |
| US2: Messages+Files | T024-T028 | P1 |
| US3: Connectors | T029-T036 | P1 |
| US4: Status+WS | T037-T049 | P2 |
| US5: Scaling | T050-T054 | P2 |
| US6: Failover | T055-T060 | P2 |
| US7: Load Tests | T061-T066 | P2 |
| US8: Monitoring | T067-T073 | P3 |
| US9: Demo | T074-T083 | P3 |
| Polish | T084-T090 | - |

**Total**: 90 tasks  
**MVP (P1 stories)**: T001-T036 (36 tasks)  
**Full Feature (P1+P2)**: T001-T066 (66 tasks)  
**Complete with Demo**: All 90 tasks

---

## Implementation Strategy

### MVP First (User Stories 1-3 Only)

1. Complete Phase 1: Setup (T001-T007)
2. Complete Phase 2: Foundational (T008-T014)
3. Complete Phase 3: US1 Files (T015-T023)
4. Complete Phase 4: US2 Messages+Files (T024-T028)
5. Complete Phase 5: US3 Connectors (T029-T036)
6. **STOP**: Test file upload, message with files, connector delivery
7. This is a **working MVP** demonstrating Object Storage + Kafka connectors

### Add Real-Time Features (User Story 4)

8. Complete Phase 6: US4 Status+WebSocket (T037-T049)
9. **STOP**: Test WebSocket status updates end-to-end
10. Frontend now shows real-time status indicators

### Add Observability (User Stories 5-8)

11. Complete Phases 7-10 in parallel (T050-T073)
12. **STOP**: Run load tests, verify Grafana dashboards

### Final Demo

13. Complete Phase 11: US9 Full Demo (T074-T083)
14. Complete Phase 12: Polish (T084-T090)
15. **DONE**: Ready for academic presentation

---

## Notes

- [P] tasks can run in parallel (different files)
- [USn] maps task to user story for traceability
- Each user story checkpoint = independently testable increment
- Commit after each task or logical group
- Educational comments required per Constitution
