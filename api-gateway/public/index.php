<?php
/**
 * API Gateway - Ponto de entrada REST que converte para gRPC
 * Expõe endpoints REST para o frontend e se comunica com serviços gRPC
 */

require __DIR__ . '/../vendor/autoload.php';

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Content-Type: application/json');

// Responder a preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Clients gRPC
$authClient = new Auth\AuthServiceClient(
    getenv('AUTH_SERVICE_HOST') . ':' . getenv('AUTH_SERVICE_PORT'),
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);

$messageClient = new Message\MessageServiceClient(
    getenv('MESSAGE_SERVICE_HOST') . ':' . getenv('MESSAGE_SERVICE_PORT'),
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);

$conversationClient = new Conversation\ConversationServiceClient(
    getenv('CONVERSATION_SERVICE_HOST') . ':' . getenv('CONVERSATION_SERVICE_PORT'),
    ['credentials' => Grpc\ChannelCredentials::createInsecure()]
);

// Obter rota e método
$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];
$path = parse_url($requestUri, PHP_URL_PATH);

// Router simples
switch ($path) {
    // ===== HEALTH CHECK =====
    case '/':
    case '/health':
        echo json_encode([
            'status' => 'ok',
            'service' => 'Chat4All API Gateway',
            'version' => '1.0.0',
            'backend' => 'gRPC'
        ]);
        break;

    // ===== AUTH ENDPOINTS =====
    
    case '/v1/auth/register':
        if ($requestMethod === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Auth\RegisterRequest();
            $request->setUsername($data['username'] ?? '');
            $request->setEmail($data['email'] ?? '');
            $request->setPhone($data['phone'] ?? '');
            $request->setPassword($data['password'] ?? '');
            
            list($response, $status) = $authClient->Register($request)->wait();
            
            // Check if response is null or status indicates error
            if ($response === null || !$status->code === 0) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to communicate with authentication service',
                    'error' => $status ? $status->details : 'No response from gRPC server'
                ]);
                break;
            }
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'user' => $response->getUser() ? [
                    'user_id' => $response->getUser()->getUserId(),
                    'username' => $response->getUser()->getUsername(),
                    'email' => $response->getUser()->getEmail(),
                    'phone' => $response->getUser()->getPhone(),
                    'created_at' => $response->getUser()->getCreatedAt(),
                ] : null
            ]);
        }
        break;

    case '/v1/auth/login':
        if ($requestMethod === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Auth\LoginRequest();
            $request->setEmail($data['email'] ?? '');
            $request->setPhone($data['phone'] ?? '');
            $request->setPassword($data['password'] ?? '');
            
            list($response, $status) = $authClient->Login($request)->wait();
            
            // Check if response is null or status indicates error
            if ($response === null || !$status->code === 0) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Failed to communicate with authentication service',
                    'error' => $status ? $status->details : 'No response from gRPC server'
                ]);
                break;
            }
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'token' => $response->getToken(),
                'user' => $response->getUser() ? [
                    'user_id' => $response->getUser()->getUserId(),
                    'username' => $response->getUser()->getUsername(),
                    'email' => $response->getUser()->getEmail(),
                    'phone' => $response->getUser()->getPhone(),
                ] : null
            ]);
        }
        break;

    // ===== CONVERSATION ENDPOINTS =====
    
    case '/v1/conversations/private':
        if ($requestMethod === 'POST') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Conversation\CreatePrivateConversationRequest();
            $request->setUserId($userId);
            $request->setOtherUserId($data['other_user_id'] ?? '');
            
            list($response, $status) = $conversationClient->CreatePrivateConversation($request)->wait();
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'conversation' => formatConversation($response->getConversation())
            ]);
        }
        break;

    case '/v1/conversations/group':
        if ($requestMethod === 'POST') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Conversation\CreateGroupRequest();
            $request->setUserId($userId);
            $request->setGroupName($data['group_name'] ?? '');
            
            if (!empty($data['member_user_ids'])) {
                $request->setMemberUserIds($data['member_user_ids']);
            }
            
            list($response, $status) = $conversationClient->CreateGroup($request)->wait();
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'conversation' => formatConversation($response->getConversation())
            ]);
        }
        break;

    case '/v1/conversations':
        if ($requestMethod === 'GET') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $request = new Conversation\ListConversationsRequest();
            $request->setUserId($userId);
            $request->setLimit($_GET['limit'] ?? 50);
            $request->setOffset($_GET['offset'] ?? 0);
            
            list($response, $status) = $conversationClient->ListConversations($request)->wait();
            
            $conversations = [];
            foreach ($response->getConversations() as $conv) {
                $conversations[] = [
                    'conversation_id' => $conv->getConversationId(),
                    'type' => $conv->getType(),
                    'name' => $conv->getName(),
                    'last_message' => $conv->getLastMessage(),
                    'last_message_at' => $conv->getLastMessageAt(),
                    'unread_count' => $conv->getUnreadCount(),
                    'members' => formatMembers($conv->getMembers())
                ];
            }
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'conversations' => $conversations
            ]);
        }
        break;

    // ===== MESSAGE ENDPOINTS =====
    
    case '/v1/messages':
        if ($requestMethod === 'POST') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Message\SendMessageRequest();
            $request->setConversationId($data['conversation_id'] ?? '');
            $request->setFromUserId($userId);
            $request->setMessageType($data['message_type'] ?? 'text');
            $request->setContent($data['content'] ?? '');
            
            // Adicionar file_id se fornecido
            if (!empty($data['file_id'])) {
                $request->setFileId($data['file_id']);
            }
            
            list($response, $status) = $messageClient->SendMessage($request)->wait();
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'sent_message' => formatMessage($response->getSentMessage())
            ]);
        }
        break;

    case (preg_match('/^\/v1\/conversations\/(.+)\/messages$/', $path, $matches) ? true : false):
        if ($requestMethod === 'GET') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $conversationId = $matches[1];
            
            $request = new Message\ListMessagesRequest();
            $request->setConversationId($conversationId);
            $request->setUserId($userId);
            $request->setLimit($_GET['limit'] ?? 50);
            $request->setOffset($_GET['offset'] ?? 0);
            
            list($response, $status) = $messageClient->ListMessages($request)->wait();
            
            $messages = [];
            foreach ($response->getMessages() as $msg) {
                $messages[] = formatMessage($msg);
            }
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'messages' => $messages,
                'total_count' => $response->getTotalCount()
            ]);
        }
        break;

    case '/v1/messages/read':
        if ($requestMethod === 'POST') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Message\MarkAsReadRequest();
            $request->setMessageId($data['message_id'] ?? '');
            $request->setUserId($userId);
            
            list($response, $status) = $messageClient->MarkAsRead($request)->wait();
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage()
            ]);
        }
        break;

    case (preg_match('/^\/v1\/conversations\/(.+)\/read$/', $path, $matches) ? true : false):
        if ($requestMethod === 'POST') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $conversationId = $matches[1];
            
            // Fazer chamada HTTP direta ao api-service pois não existe gRPC para isso
            $apiServiceUrl = 'http://' . getenv('API_SERVICE_HOST') . ':' . getenv('API_SERVICE_PORT');
            $url = "{$apiServiceUrl}/v1/conversations/{$conversationId}/read";
            
            // Obter token da requisição atual
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: ' . $authHeader,
                'Content-Type: application/json'
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            http_response_code($httpCode);
            echo $result;
        }
        break;

    case (preg_match('/^\/v1\/conversations\/(.+)\/unread$/', $path, $matches) ? true : false):
        if ($requestMethod === 'GET') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $conversationId = $matches[1];
            
            // Fazer chamada HTTP direta ao api-service pois não existe gRPC para isso
            $apiServiceUrl = 'http://' . getenv('API_SERVICE_HOST') . ':' . getenv('API_SERVICE_PORT');
            $url = "{$apiServiceUrl}/v1/conversations/{$conversationId}/unread";
            
            // Obter token da requisição atual
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
            
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: ' . $authHeader,
                'Content-Type: application/json'
            ]);
            
            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            http_response_code($httpCode);
            echo $result;
        }
        break;

    default:
        http_response_code(404);
        echo json_encode(['error' => 'Endpoint não encontrado']);
        break;
}

/**
 * Autenticar requisição via token JWT
 */
function authenticateRequest(): ?string
{
    global $authClient;
    
    $headers = getallheaders();
    $authHeader = $headers['Authorization'] ?? '';
    
    if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
        http_response_code(401);
        echo json_encode(['error' => 'Token não fornecido']);
        return null;
    }
    
    $token = $matches[1];
    
    $request = new Auth\ValidateTokenRequest();
    $request->setToken($token);
    
    list($response, $status) = $authClient->ValidateToken($request)->wait();
    
    if (!$response->getValid()) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido']);
        return null;
    }
    
    return $response->getUserId();
}

/**
 * Formatar objeto Conversation para array
 */
function formatConversation($conv): ?array
{
    if (!$conv) return null;
    
    return [
        'conversation_id' => $conv->getConversationId(),
        'type' => $conv->getType(),
        'name' => $conv->getName(),
        'created_by' => $conv->getCreatedBy(),
        'created_at' => $conv->getCreatedAt(),
        'is_active' => $conv->getIsActive(),
        'members' => formatMembers($conv->getMembers())
    ];
}

/**
 * Formatar lista de membros
 */
function formatMembers($members): array
{
    $result = [];
    foreach ($members as $member) {
        $result[] = [
            'user_id' => $member->getUserId(),
            'username' => $member->getUsername(),
            'role' => $member->getRole(),
            'joined_at' => $member->getJoinedAt()
        ];
    }
    return $result;
}

/**
 * Formatar objeto Message para array
 */
function formatMessage($msg): ?array
{
    if (!$msg) return null;
    
    $readBy = [];
    foreach ($msg->getReadBy() as $read) {
        $readBy[] = [
            'user_id' => $read->getUserId(),
            'username' => $read->getUsername(),
            'read_at' => $read->getReadAt()
        ];
    }
    
    return [
        'message_id' => $msg->getMessageId(),
        'conversation_id' => $msg->getConversationId(),
        'from_user_id' => $msg->getFromUserId(),
        'from_username' => $msg->getFromUsername(),
        'message_type' => $msg->getMessageType(),
        'content' => $msg->getContent(),
        'file_id' => $msg->getFileId() ?: null,
        'status' => $msg->getStatus(),
        'created_at' => $msg->getCreatedAt(),
        'sequence_number' => $msg->getSequenceNumber(),
        'read_by' => $readBy
    ];
}