# Research: Trabalho Final - Escalabilidade e Relatório

**Feature**: 001-final-scalability-report  
**Date**: 2025-11-29  
**Status**: Complete

## Research Questions

### RQ-001: WebSocket Implementation in PHP

**Question**: How to implement WebSocket server in PHP that integrates with Kafka for real-time message status updates?

**Decision**: Use **Ratchet** library with dedicated WebSocket worker process

**Rationale**:
- Ratchet is the most mature WebSocket library for PHP
- Runs as standalone process, doesn't block HTTP requests
- Can integrate with Kafka via separate consumer loop
- Well-documented with educational examples

**Alternatives Considered**:
1. **Swoole**: More performant but requires PHP extension installation, complicates Docker setup
2. **ReactPHP only**: Lower-level, more code to write
3. **Separate Node.js service**: Would require additional language, against project philosophy

**Implementation Pattern**:
```php
// WebSocket Worker Architecture
// 1. Main process runs Ratchet WebSocket server
// 2. Separate thread/process consumes Kafka status-updates topic
// 3. When status change received, broadcast to connected clients

// Redis pub/sub bridges Kafka consumer → WebSocket broadcaster
// This avoids threading complexity in PHP
```

---

### RQ-002: MinIO Multipart Upload for Large Files

**Question**: How to implement resumable upload up to 2GB using MinIO S3-compatible API?

**Decision**: Use AWS SDK for PHP with S3 multipart upload API

**Rationale**:
- MinIO is 100% S3-compatible
- AWS SDK handles chunking, retry, resume automatically
- Well-documented, production-tested
- Part size of 100MB balances memory usage and network efficiency

**Alternatives Considered**:
1. **Direct MinIO client**: Less features, less documentation
2. **Custom chunking**: Reinventing the wheel, error-prone
3. **TUS protocol**: Overkill for this use case, requires additional server

**Implementation Details**:
- Minimum part size: 5MB (S3 requirement)
- Recommended part size: 100MB (good for 2GB files = 20 parts)
- Upload ID stored in Redis for resume capability
- Frontend tracks uploaded parts, sends remaining on resume

---

### RQ-003: Kafka Consumer Groups for Horizontal Scaling

**Question**: How do Kafka consumer groups enable horizontal scaling of workers?

**Decision**: Use single consumer group ID per worker type

**Rationale**:
- All router-workers share `router-worker-group` 
- Kafka automatically distributes partitions among group members
- When worker joins/leaves, automatic rebalance occurs
- Demonstrates distributed systems concept directly

**Configuration**:
```yaml
# router-worker
KAFKA_GROUP_ID: router-worker-group
KAFKA_TOPIC: messages

# whatsapp-connector  
KAFKA_GROUP_ID: whatsapp-connector-group
KAFKA_TOPIC: whatsapp.messages

# websocket-worker
KAFKA_GROUP_ID: websocket-worker-group
KAFKA_TOPIC: status-updates
```

**Scaling Command**:
```bash
docker-compose up -d --scale router-worker=3 --scale whatsapp-connector=2
```

---

### RQ-004: Message Status Flow with WebSocket Notifications

**Question**: How should message status transitions flow through the system with WebSocket notifications?

**Decision**: Event-driven architecture with dedicated Kafka topic for status updates

**Rationale**:
- Decouples status processing from notification delivery
- Allows independent scaling of notification system
- Clean separation of concerns
- Demonstrates event sourcing pattern

**Flow Diagram**:
```
User sends message
      │
      ▼
  API Service ──────► Kafka (messages topic)
      │                     │
      │                     ▼
      │              Router Worker
      │                     │
      │                     ▼
      │              Kafka (whatsapp.messages / instagram.messages)
      │                     │
      │                     ▼
      │              Connector Mock
      │                     │
      │                     ▼
      │              HTTP Callback to API
      │                     │
      ▼                     ▼
  DB Update ◄───────  Status: DELIVERED
      │
      ▼
  Kafka (status-updates topic)
      │
      ▼
  WebSocket Worker
      │
      ▼
  Broadcast to connected clients via WebSocket
```

---

### RQ-005: Connector Callback Implementation

**Question**: How should connectors send delivery/read callbacks to the API?

**Decision**: HTTP POST to `/v1/callbacks/{platform}` endpoint

**Rationale**:
- Simple REST interface, easy to test and debug
- Stateless, no session management needed
- Connectors can retry on failure
- Logs show clear callback flow

**Callback Payload**:
```json
{
  "message_id": "uuid",
  "status": "DELIVERED" | "READ",
  "timestamp": "ISO8601",
  "platform": "whatsapp" | "instagram",
  "external_id": "simulated-external-id"
}
```

**Simulated Timing** (for demo):
- DELIVERED: 1-3 seconds after message received
- READ: 3-8 seconds after DELIVERED (random)

---

### RQ-006: Prometheus Metrics for PHP Services

**Question**: How to expose Prometheus metrics from PHP services?

**Decision**: Use `promphp/prometheus_client_php` library with dedicated metrics endpoint

**Rationale**:
- Native PHP library, no external dependencies
- Stores metrics in Redis (shared across workers)
- Standard `/metrics` endpoint format
- Integrates with existing Prometheus setup

**Metrics to Expose**:
```
# Counter
messages_processed_total{status="sent|delivered|read|failed"}
files_uploaded_total
websocket_connections_total
callbacks_received_total{platform="whatsapp|instagram"}

# Gauge  
websocket_active_connections
kafka_consumer_lag{topic, group}

# Histogram
message_processing_duration_seconds
file_upload_duration_seconds
callback_processing_duration_seconds
```

---

### RQ-007: Angular WebSocket Service Pattern

**Question**: Best pattern for WebSocket integration in Angular with reconnection support?

**Decision**: RxJS-based WebSocket service with automatic reconnection

**Rationale**:
- Native RxJS WebSocket support
- Observable-based, integrates with Angular patterns
- Built-in retry logic with exponential backoff
- Type-safe message handling

**Service Pattern**:
```typescript
@Injectable({ providedIn: 'root' })
export class WebSocketService {
  private socket$: WebSocketSubject<any>;
  private reconnectInterval = 3000;
  
  connect(userId: string): Observable<StatusUpdate> {
    this.socket$ = webSocket({
      url: `ws://localhost:8081/ws?userId=${userId}`,
      openObserver: { next: () => console.log('WebSocket connected') },
      closeObserver: { next: () => this.reconnect() }
    });
    return this.socket$.pipe(
      retryWhen(errors => errors.pipe(delay(this.reconnectInterval)))
    );
  }
}
```

---

### RQ-008: Failover Testing Strategy

**Question**: How to demonstrate and test tolerance to failures?

**Decision**: Scripted failover scenarios with measurable metrics

**Scenarios**:
1. **Worker Failure**: `docker stop` one router-worker during load test
2. **Connector Failure**: Stop connector, verify messages queue in Kafka
3. **API Service Restart**: Restart api-service, verify no data loss
4. **Database Reconnection**: Temporarily block DB, verify reconnection

**Measurement**:
- Messages processed before/during/after failure
- Time to recovery (first message after failure)
- Message loss count (should be zero)
- Kafka consumer lag during/after failure

**Script Structure**:
```bash
#!/bin/bash
# test-failover.sh

echo "Starting load test..."
k6 run -d 60s load-test.js &

sleep 20
echo "Killing worker 1..."
docker stop chat4all-router-worker-1

sleep 10
echo "Checking metrics..."
# Verify no message loss

sleep 20
echo "Restarting worker 1..."
docker start chat4all-router-worker-1

# Collect final metrics
```

---

## Technology Decisions Summary

| Component | Technology | Justification |
|-----------|------------|---------------|
| WebSocket Server | Ratchet (PHP) | Mature, standalone, educational |
| Object Storage SDK | AWS SDK PHP | S3-compatible, multipart support |
| WebSocket Client | RxJS WebSocket | Native Angular, auto-reconnect |
| Metrics | prometheus_client_php | Standard format, Redis storage |
| Inter-service Notification | Redis Pub/Sub | Bridges Kafka → WebSocket |
| Load Testing | k6 | JavaScript-based, good metrics |

## Open Questions Resolved

- ✅ WebSocket vs Polling: **WebSocket** (user requirement)
- ✅ Worker communication: **Kafka + Redis Pub/Sub**
- ✅ File upload strategy: **Multipart with AWS SDK**
- ✅ Metrics exposure: **Prometheus client library**
- ✅ Failover testing: **Scripted scenarios with k6**

## References

1. [Ratchet WebSocket Documentation](http://socketo.me/)
2. [AWS SDK PHP S3 Multipart](https://docs.aws.amazon.com/sdk-for-php/v3/developer-guide/s3-multipart-upload.html)
3. [Kafka Consumer Groups](https://kafka.apache.org/documentation/#consumerconfigs)
4. [Prometheus PHP Client](https://github.com/promphp/prometheus_client_php)
5. [Angular RxJS WebSocket](https://rxjs.dev/api/webSocket/webSocket)
