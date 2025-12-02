// ===================================================================
// Chat4All - k6 Load Test Script
// 
// CONCEITO DE SISTEMAS DISTRIBUÍDOS:
// Este script realiza testes de carga para validar a escalabilidade
// e performance do sistema Chat4All, incluindo:
// - Envio de mensagens de texto
// - Upload de arquivos (multipart)
// - Verificação de status de mensagens
// - WebSocket connections
//
// Referência: Trabalho Final - Escalabilidade e Relatório (UFG)
// ===================================================================

import http from 'k6/http';
import { check, sleep, group } from 'k6';
import { Counter, Rate, Trend, Gauge } from 'k6/metrics';
import ws from 'k6/ws';

// Custom metrics - Mensagens
const messagesSent = new Counter('messages_sent');
const messagesSuccess = new Counter('messages_success');
const messagesFailed = new Counter('messages_failed');
const messageLatency = new Trend('message_latency');

// Custom metrics - Arquivos
const filesUploaded = new Counter('files_uploaded');
const filesUploadSuccess = new Counter('files_upload_success');
const filesUploadFailed = new Counter('files_upload_failed');
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

// Custom metrics - Autenticação
const authFailures = new Rate('auth_failures');
const registrationSuccess = new Counter('registration_success');

// Test configuration with multiple scenarios
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
            startTime: '30s',  // Começa após 30s
            exec: 'fileUploadFlow',
        },
        // Cenário 3: Verificação de status
        status_checking: {
            executor: 'constant-arrival-rate',
            rate: 20,           // 20 requisições por segundo
            timeUnit: '1s',
            duration: '5m',
            preAllocatedVUs: 20,
            maxVUs: 50,
            startTime: '1m',
            exec: 'statusCheckFlow',
        },
    },
    thresholds: {
        http_req_duration: ['p(95)<500', 'p(99)<1000'], // 95% under 500ms, 99% under 1s
        http_req_failed: ['rate<0.05'],                  // Error rate under 5%
        messages_success: ['count>1000'],                // At least 1000 successful messages
        file_upload_latency: ['p(95)<10000'],           // 95% file uploads under 10s
        message_latency: ['p(95)<500'],                  // 95% message latency under 500ms
    },
};

// Configuration
const BASE_URL = __ENV.API_BASE_URL || 'http://localhost:8080';
const WS_URL = __ENV.WS_URL || 'ws://localhost:8081';
const API_VERSION = 'v1';

// ===================================================================
// Helper Functions
// ===================================================================

function getRandomInt(min, max) {
    return Math.floor(Math.random() * (max - min + 1)) + min;
}

function generateUsername() {
    return `k6_user_${__VU}_${Date.now()}_${getRandomInt(1000, 9999)}`;
}

// ===================================================================
// Test scenario: User registration
// ===================================================================
function registerUser() {
    const username = generateUsername();
    const payload = JSON.stringify({
        username: username,
        email: `${username}@k6test.com`,
        password: 'K6Test123!@#',
        full_name: `K6 Test User ${__VU}`,
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
        },
    };

    const response = http.post(
        `${BASE_URL}/${API_VERSION}/auth/register`,
        payload,
        params
    );

    const success = check(response, {
        'registration successful': (r) => r.status === 201 || r.status === 200,
        'user_id present': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.user_id !== undefined;
            } catch (e) {
                return false;
            }
        },
    });

    if (success) {
        return {
            username: username,
            password: 'K6Test123!@#',
        };
    }

    return null;
}

// ===================================================================
// Test scenario: User authentication
// ===================================================================
function authenticateUser(credentials) {
    const payload = JSON.stringify({
        username: credentials.username,
        password: credentials.password,
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
        },
    };

    const response = http.post(
        `${BASE_URL}/${API_VERSION}/auth/login`,
        payload,
        params
    );

    const success = check(response, {
        'login successful': (r) => r.status === 200,
        'token received': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.token !== undefined && body.token !== null;
            } catch (e) {
                return false;
            }
        },
    });

    authFailures.add(!success);

    if (success) {
        try {
            const body = JSON.parse(response.body);
            return body.token;
        } catch (e) {
            return null;
        }
    }

    return null;
}

// ===================================================================
// Test scenario: Create conversation
// ===================================================================
function createConversation(token) {
    const payload = JSON.stringify({
        title: `K6 Load Test Conversation ${__VU}`,
        type: 'group',
        participant_ids: [1, 2],
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
        },
    };

    const response = http.post(
        `${BASE_URL}/${API_VERSION}/conversations`,
        payload,
        params
    );

    const success = check(response, {
        'conversation created': (r) => r.status === 201 || r.status === 200,
    });

    if (success) {
        try {
            const body = JSON.parse(response.body);
            return body.conversation_id;
        } catch (e) {
            return null;
        }
    }

    return null;
}

// ===================================================================
// Test scenario: Send message
// ===================================================================
function sendMessage(token, conversationId, messageNumber) {
    const payload = JSON.stringify({
        conversation_id: conversationId,
        content: `Load test message #${messageNumber} from VU ${__VU} at ${new Date().toISOString()}`,
        type: 'text',
    });

    const params = {
        headers: {
            'Content-Type': 'application/json',
            'Authorization': `Bearer ${token}`,
        },
    };

    const startTime = Date.now();
    const response = http.post(
        `${BASE_URL}/${API_VERSION}/messages`,
        payload,
        params
    );
    const latency = Date.now() - startTime;

    messageLatency.add(latency);
    messagesSent.add(1);

    const success = check(response, {
        'message sent successfully': (r) => r.status === 201 || r.status === 200,
        'message_id received': (r) => {
            try {
                const body = JSON.parse(r.body);
                return body.message_id !== undefined;
            } catch (e) {
                return false;
            }
        },
    });

    if (success) {
        messagesSuccess.add(1);
    } else {
        messagesFailed.add(1);
    }

    return success;
}

// ===================================================================
// Test scenario: Get messages
// ===================================================================
function getMessages(token, conversationId) {
    const params = {
        headers: {
            'Authorization': `Bearer ${token}`,
        },
    };

    const response = http.get(
        `${BASE_URL}/${API_VERSION}/conversations/${conversationId}/messages?limit=50`,
        params
    );

    check(response, {
        'messages retrieved': (r) => r.status === 200,
        'messages array present': (r) => {
            try {
                const body = JSON.parse(r.body);
                return Array.isArray(body.messages);
            } catch (e) {
                return false;
            }
        },
    });
}

// ===================================================================
// Main test scenario: Message Flow
// ===================================================================
export function messageFlow() {
    // 1. Register a new user
    const credentials = registerUser();
    if (!credentials) {
        console.error('Failed to register user');
        return;
    }

    sleep(1);

    // 2. Authenticate
    const token = authenticateUser(credentials);
    if (!token) {
        console.error('Failed to authenticate user');
        return;
    }

    sleep(1);

    // 3. Create a conversation
    const conversationId = createConversation(token);
    if (!conversationId) {
        console.error('Failed to create conversation');
        return;
    }

    sleep(1);

    // 4. Send multiple messages (simulate conversation)
    const numMessages = getRandomInt(5, 15);
    for (let i = 0; i < numMessages; i++) {
        sendMessage(token, conversationId, i + 1);
        sleep(getRandomInt(1, 3)); // Random delay between messages
    }

    // 5. Retrieve messages
    getMessages(token, conversationId);

    sleep(2);
}

// Default export for simple execution
export default function() {
    messageFlow();
}

// ===================================================================
// Scenario: File Upload Flow
// Tests multipart file upload functionality
// ===================================================================
export function fileUploadFlow() {
    // Setup: Register and authenticate
    const credentials = registerUser();
    if (!credentials) {
        filesUploadFailed.add(1);
        return;
    }
    
    const token = authenticateUser(credentials);
    if (!token) {
        filesUploadFailed.add(1);
        return;
    }

    // Create conversation for file sharing
    const conversationId = createConversation(token);
    if (!conversationId) {
        filesUploadFailed.add(1);
        return;
    }

    group('file_upload', () => {
        // Test small file upload (1KB)
        uploadFile(token, conversationId, 1024, 'small');
        sleep(1);

        // Test medium file upload (100KB)
        uploadFile(token, conversationId, 102400, 'medium');
        sleep(2);

        // Test larger file upload (1MB)
        uploadFile(token, conversationId, 1048576, 'large');
        sleep(3);
    });
}

// ===================================================================
// Scenario: Status Check Flow
// Tests message status retrieval
// ===================================================================
export function statusCheckFlow() {
    // Setup: Register and authenticate
    const credentials = registerUser();
    if (!credentials) return;
    
    const token = authenticateUser(credentials);
    if (!token) return;

    const conversationId = createConversation(token);
    if (!conversationId) return;

    // Send a message
    const messageId = sendMessageWithId(token, conversationId);
    if (!messageId) return;

    // Poll for status updates
    group('status_checking', () => {
        for (let i = 0; i < 5; i++) {
            checkMessageStatus(token, messageId);
            sleep(2);
        }
    });
}

// ===================================================================
// File Upload Helper
// ===================================================================
function uploadFile(token, conversationId, size, sizeLabel) {
    filesUploaded.add(1);
    
    // Generate random file content
    const fileContent = generateRandomBytes(size);
    const fileName = `test_file_${sizeLabel}_${Date.now()}.bin`;
    
    // Step 1: Initiate multipart upload
    const initiateResponse = http.post(
        `${BASE_URL}/${API_VERSION}/files/upload/initiate`,
        JSON.stringify({
            filename: fileName,
            content_type: 'application/octet-stream',
            size: size,
            conversation_id: conversationId,
        }),
        {
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
            },
        }
    );

    if (initiateResponse.status !== 200 && initiateResponse.status !== 201) {
        filesUploadFailed.add(1);
        console.error(`Failed to initiate upload: ${initiateResponse.status}`);
        return;
    }

    let uploadId, fileId;
    try {
        const body = JSON.parse(initiateResponse.body);
        uploadId = body.data?.upload_id || body.upload_id;
        fileId = body.data?.file_id || body.file_id;
    } catch (e) {
        filesUploadFailed.add(1);
        return;
    }

    // Step 2: Upload part
    const startTime = Date.now();
    const uploadResponse = http.put(
        `${BASE_URL}/${API_VERSION}/files/upload/${uploadId}/part/1`,
        fileContent,
        {
            headers: {
                'Content-Type': 'application/octet-stream',
                'Authorization': `Bearer ${token}`,
            },
        }
    );

    if (uploadResponse.status !== 200) {
        filesUploadFailed.add(1);
        return;
    }

    // Step 3: Complete upload
    const completeResponse = http.post(
        `${BASE_URL}/${API_VERSION}/files/upload/${uploadId}/complete`,
        JSON.stringify({
            parts: [{ part_number: 1, etag: 'dummy-etag' }],
        }),
        {
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
            },
        }
    );

    const latency = Date.now() - startTime;
    fileUploadLatency.add(latency);
    
    // Calculate throughput in MB/s
    const throughput = (size / 1024 / 1024) / (latency / 1000);
    fileUploadThroughput.add(throughput);

    const success = check(completeResponse, {
        'file upload completed': (r) => r.status === 200 || r.status === 201,
    });

    if (success) {
        filesUploadSuccess.add(1);
    } else {
        filesUploadFailed.add(1);
    }
}

function generateRandomBytes(size) {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let result = '';
    for (let i = 0; i < size; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return result;
}

// ===================================================================
// Message with ID Helper
// ===================================================================
function sendMessageWithId(token, conversationId) {
    const payload = JSON.stringify({
        conversation_id: conversationId,
        content: `Status check test message at ${new Date().toISOString()}`,
        type: 'text',
    });

    const response = http.post(
        `${BASE_URL}/${API_VERSION}/messages`,
        payload,
        {
            headers: {
                'Content-Type': 'application/json',
                'Authorization': `Bearer ${token}`,
            },
        }
    );

    if (response.status === 200 || response.status === 201) {
        try {
            const body = JSON.parse(response.body);
            return body.data?.id || body.data?.message_id || body.message_id;
        } catch (e) {
            return null;
        }
    }
    return null;
}

// ===================================================================
// Status Check Helper
// ===================================================================
function checkMessageStatus(token, messageId) {
    statusChecks.add(1);

    const response = http.get(
        `${BASE_URL}/${API_VERSION}/messages/${messageId}/status`,
        {
            headers: {
                'Authorization': `Bearer ${token}`,
            },
        }
    );

    if (response.status === 200) {
        try {
            const body = JSON.parse(response.body);
            const status = body.data?.status || body.status;
            
            if (status === 'DELIVERED') {
                statusDelivered.add(1);
            } else if (status === 'READ') {
                statusRead.add(1);
            }
        } catch (e) {
            // Ignore parsing errors
        }
    }
}

// ===================================================================
// Lifecycle hooks
// ===================================================================
export function setup() {
    console.log('Starting k6 load test for Chat4All');
    console.log(`API Base URL: ${BASE_URL}`);
    
    // Health check
    const healthResponse = http.get(`${BASE_URL}/health`);
    if (healthResponse.status !== 200) {
        console.error('API health check failed!');
        throw new Error('API is not available');
    }
    
    console.log('API health check passed');
    return {};
}

export function teardown(data) {
    console.log('Load test completed');
}

// ===================================================================
// Summary handler - Output to finalTest/results/
// ===================================================================
export function handleSummary(data) {
    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    
    return {
        'stdout': textSummary(data, { indent: ' ', enableColors: true }),
        [`../results/k6_results_${timestamp}.json`]: JSON.stringify(data, null, 2),
        [`../results/k6_summary_${timestamp}.html`]: htmlReport(data),
        '../results/k6_latest.json': JSON.stringify(data, null, 2),
    };
}

function textSummary(data, opts) {
    const indent = opts.indent || '';
    const colors = opts.enableColors;
    
    let summary = '\n';
    summary += `${indent}✓ Test completed\n`;
    summary += `${indent}Duration: ${data.state.testRunDurationMs / 1000}s\n`;
    summary += `${indent}VUs: ${data.metrics.vus.values.max}\n`;
    summary += `${indent}Iterations: ${data.metrics.iterations.values.count}\n`;
    summary += `${indent}Messages sent: ${data.metrics.messages_sent.values.count}\n`;
    summary += `${indent}Messages successful: ${data.metrics.messages_success.values.count}\n`;
    summary += `${indent}Messages failed: ${data.metrics.messages_failed.values.count}\n`;
    
    return summary;
}

function htmlReport(data) {
    return `
<!DOCTYPE html>
<html>
<head>
    <title>k6 Load Test Report - Chat4All</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f5f5f5; }
        .container { max-width: 1200px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 3px solid #4CAF50; padding-bottom: 10px; }
        h2 { color: #666; margin-top: 30px; }
        .metric { background: #f9f9f9; padding: 15px; margin: 10px 0; border-left: 4px solid #4CAF50; }
        .metric-name { font-weight: bold; color: #333; }
        .metric-value { color: #4CAF50; font-size: 24px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #ddd; }
        th { background-color: #4CAF50; color: white; }
        tr:hover { background-color: #f5f5f5; }
        .success { color: #4CAF50; }
        .warning { color: #ff9800; }
        .error { color: #f44336; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 k6 Load Test Report - Chat4All</h1>
        <p>Generated: ${new Date().toISOString()}</p>
        
        <h2>Test Summary</h2>
        <div class="metric">
            <div class="metric-name">Total Duration</div>
            <div class="metric-value">${(data.state.testRunDurationMs / 1000).toFixed(2)}s</div>
        </div>
        <div class="metric">
            <div class="metric-name">Max Virtual Users</div>
            <div class="metric-value">${data.metrics.vus.values.max}</div>
        </div>
        <div class="metric">
            <div class="metric-name">Total Iterations</div>
            <div class="metric-value">${data.metrics.iterations.values.count}</div>
        </div>
        
        <h2>Message Metrics</h2>
        <table>
            <tr>
                <th>Metric</th>
                <th>Value</th>
            </tr>
            <tr>
                <td>Messages Sent</td>
                <td class="success">${data.metrics.messages_sent.values.count}</td>
            </tr>
            <tr>
                <td>Messages Success</td>
                <td class="success">${data.metrics.messages_success.values.count}</td>
            </tr>
            <tr>
                <td>Messages Failed</td>
                <td class="${data.metrics.messages_failed.values.count > 0 ? 'error' : 'success'}">${data.metrics.messages_failed.values.count}</td>
            </tr>
        </table>
        
        <h2>Performance Metrics</h2>
        <table>
            <tr>
                <th>Metric</th>
                <th>Avg</th>
                <th>Min</th>
                <th>Max</th>
                <th>P95</th>
                <th>P99</th>
            </tr>
            <tr>
                <td>HTTP Request Duration (ms)</td>
                <td>${data.metrics.http_req_duration.values.avg.toFixed(2)}</td>
                <td>${data.metrics.http_req_duration.values.min.toFixed(2)}</td>
                <td>${data.metrics.http_req_duration.values.max.toFixed(2)}</td>
                <td>${data.metrics.http_req_duration.values['p(95)'].toFixed(2)}</td>
                <td>${data.metrics.http_req_duration.values['p(99)'].toFixed(2)}</td>
            </tr>
            <tr>
                <td>Message Latency (ms)</td>
                <td>${data.metrics.message_latency.values.avg.toFixed(2)}</td>
                <td>${data.metrics.message_latency.values.min.toFixed(2)}</td>
                <td>${data.metrics.message_latency.values.max.toFixed(2)}</td>
                <td>${data.metrics.message_latency.values['p(95)'].toFixed(2)}</td>
                <td>${data.metrics.message_latency.values['p(99)'].toFixed(2)}</td>
            </tr>
        </table>
    </div>
</body>
</html>`;
}
