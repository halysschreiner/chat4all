// ===================================================================
// Chat4All - k6 Load Test Script
// Week 7-8: Advanced Load Testing with k6
// ===================================================================

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

// Custom metrics
const messagesSent = new Counter('messages_sent');
const messagesSuccess = new Counter('messages_success');
const messagesFailed = new Counter('messages_failed');
const authFailures = new Rate('auth_failures');
const messageLatency = new Trend('message_latency');

// Test configuration
export const options = {
    stages: [
        { duration: '30s', target: 10 },   // Ramp up to 10 users
        { duration: '1m', target: 50 },    // Ramp up to 50 users
        { duration: '2m', target: 100 },   // Ramp up to 100 users
        { duration: '2m', target: 100 },   // Stay at 100 users
        { duration: '1m', target: 200 },   // Peak load
        { duration: '1m', target: 200 },   // Sustain peak
        { duration: '30s', target: 0 },    // Ramp down
    ],
    thresholds: {
        http_req_duration: ['p(95)<500', 'p(99)<1000'], // 95% under 500ms, 99% under 1s
        http_req_failed: ['rate<0.05'],                  // Error rate under 5%
        messages_success: ['count>1000'],                // At least 1000 successful messages
    },
};

// Configuration
const BASE_URL = __ENV.API_BASE_URL || 'http://localhost:8000';
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
// Main test scenario
// ===================================================================
export default function () {
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
// Summary handler
// ===================================================================
export function handleSummary(data) {
    return {
        'stdout': textSummary(data, { indent: ' ', enableColors: true }),
        '../results/k6_summary.json': JSON.stringify(data, null, 2),
        '../results/k6_summary.html': htmlReport(data),
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
