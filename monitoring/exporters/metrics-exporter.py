#!/usr/bin/env python3
"""
Chat4All Metrics Exporter
Generates sample metrics for Prometheus scraping
"""

from http.server import HTTPServer, BaseHTTPRequestHandler
import random
import time

class MetricsHandler(BaseHTTPRequestHandler):
    def do_GET(self):
        if self.path == '/metrics':
            metrics = self.generate_metrics()
            self.send_response(200)
            self.send_header('Content-Type', 'text/plain; version=0.0.4')
            self.end_headers()
            self.wfile.write(metrics.encode())
        elif self.path == '/health':
            self.send_response(200)
            self.send_header('Content-Type', 'text/plain')
            self.end_headers()
            self.wfile.write(b'OK')
        else:
            self.send_response(404)
            self.end_headers()
    
    def generate_metrics(self):
        current_time = int(time.time())
        
        # Base values with some randomness
        messages_processed = 15000 + random.randint(-1000, 1000)
        messages_per_sec = 68 + random.randint(-10, 10)
        latency_ms = 10 + random.uniform(-2, 5)
        errors_total = random.randint(0, 5)
        cpu_usage = 35 + random.uniform(-10, 20)
        memory_mb = 512 + random.randint(-50, 100)
        
        metrics = f"""# HELP messages_processed_total Total number of messages processed
# TYPE messages_processed_total counter
messages_processed_total{{service="router-worker"}} {messages_processed}
messages_processed_total{{service="api-service"}} {messages_processed - 500}
messages_processed_total{{service="whatsapp-connector"}} {messages_processed // 2}
messages_processed_total{{service="instagram-connector"}} {messages_processed // 2}

# HELP messages_per_second Current message processing rate
# TYPE messages_per_second gauge
messages_per_second{{service="router-worker"}} {messages_per_sec}
messages_per_second{{service="api-service"}} {messages_per_sec - 2}

# HELP latency_ms Average latency in milliseconds  
# TYPE latency_ms gauge
latency_ms{{service="router-worker",percentile="p50"}} {latency_ms:.2f}
latency_ms{{service="router-worker",percentile="p95"}} {latency_ms * 3:.2f}
latency_ms{{service="router-worker",percentile="p99"}} {latency_ms * 5:.2f}
latency_ms{{service="api-gateway",percentile="p50"}} {latency_ms * 1.5:.2f}
latency_ms{{service="api-gateway",percentile="p95"}} {latency_ms * 4:.2f}
latency_ms{{service="api-gateway",percentile="p99"}} {latency_ms * 6:.2f}

# HELP errors_total Total number of errors
# TYPE errors_total counter
errors_total{{service="router-worker",type="processing"}} {errors_total}
errors_total{{service="api-service",type="authentication"}} {errors_total + 1}
errors_total{{service="api-service",type="validation"}} {errors_total + 2}

# HELP cpu_usage_percent CPU usage percentage
# TYPE cpu_usage_percent gauge
cpu_usage_percent{{service="router-worker"}} {cpu_usage:.2f}
cpu_usage_percent{{service="api-service"}} {cpu_usage - 5:.2f}
cpu_usage_percent{{service="api-gateway"}} {cpu_usage - 10:.2f}

# HELP memory_usage_mb Memory usage in megabytes
# TYPE memory_usage_mb gauge
memory_usage_mb{{service="router-worker"}} {memory_mb}
memory_usage_mb{{service="api-service"}} {memory_mb + 100}
memory_usage_mb{{service="api-gateway"}} {memory_mb - 100}

# HELP http_requests_total Total HTTP requests
# TYPE http_requests_total counter
http_requests_total{{service="api-gateway",method="POST",endpoint="/v1/messages"}} {messages_processed}
http_requests_total{{service="api-gateway",method="POST",endpoint="/v1/auth/login"}} {messages_processed // 10}
http_requests_total{{service="api-gateway",method="GET",endpoint="/v1/conversations"}} {messages_processed // 5}

# HELP kafka_consumer_lag Consumer group lag
# TYPE kafka_consumer_lag gauge
kafka_consumer_lag{{topic="messages",group="router-worker-group"}} {random.randint(0, 100)}

# HELP active_workers Number of active worker instances
# TYPE active_workers gauge
active_workers{{service="router-worker"}} {random.choice([1, 2, 3, 4, 5])}
active_workers{{service="whatsapp-connector"}} {random.choice([1, 2, 3])}
active_workers{{service="instagram-connector"}} {random.choice([1, 2, 3])}
"""
        return metrics
    
    def log_message(self, format, *args):
        # Suppress access logs
        pass

def run_server(port=8000):
    server = HTTPServer(('0.0.0.0', port), MetricsHandler)
    print(f"""
╔════════════════════════════════════════════════════════╗
║        Chat4All Metrics Exporter Started              ║
╚════════════════════════════════════════════════════════╝

Listening on: http://0.0.0.0:{port}
Endpoints:
  - /metrics : Prometheus metrics
  - /health  : Health check

Press Ctrl+C to stop
""")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down...")
        server.shutdown()

if __name__ == '__main__':
    run_server()
