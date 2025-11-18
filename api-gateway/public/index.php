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
    // ===== AUTH ENDPOINTS =====
    
    case '/api/auth/register':
        if ($requestMethod === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Auth\RegisterRequest();
            $request->setUsername($data['username'] ?? '');
            $request->setEmail($data['email'] ?? '');
            $request->setPassword($data['password'] ?? '');
            
            list($response, $status) = $authClient->Register($request)->wait();
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'user' => $response->getUser() ? [
                    'user_id' => $response->getUser()->getUserId(),
                    'username' => $response->getUser()->getUsername(),
                    'email' => $response->getUser()->getEmail(),
                    'created_at' => $response->getUser()->getCreatedAt(),
                ] : null
            ]);
        }
        break;

    case '/api/auth/login':
        if ($requestMethod === 'POST') {
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Auth\LoginRequest();
            $request->setEmail($data['email'] ?? '');
            $request->setPassword($data['password'] ?? '');
            
            list($response, $status) = $authClient->Login($request)->wait();
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'token' => $response->getToken(),
                'user' => $response->getUser() ? [
                    'user_id' => $response->getUser()->getUserId(),
                    'username' => $response->getUser()->getUsername(),
                    'email' => $response->getUser()->getEmail(),
                ] : null
            ]);
        }
        break;

    // ===== CONVERSATION ENDPOINTS =====
    
    case '/api/conversations/private':
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

    case '/api/conversations/group':
        if ($requestMethod === 'POST') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Conversation\CreateGroupRequest();
            $request->setUserId($userId);
            $request->setGroupName($data['group_name'] ?? '');
            
            if (!empty($data['member_user_ids'])) {
                foreach ($data['member_user_ids'] as $memberId) {
                    $request->addMemberUserIds($memberId);
                }
            }
            
            list($response, $status) = $conversationClient->CreateGroup($request)->wait();
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'conversation' => formatConversation($response->getConversation())
            ]);
        }
        break;

    case '/api/conversations':
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
    
    case '/api/messages/send':
        if ($requestMethod === 'POST') {
            $userId = authenticateRequest();
            if (!$userId) break;
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $request = new Message\SendMessageRequest();
            $request->setConversationId($data['conversation_id'] ?? '');
            $request->setFromUserId($userId);
            $request->setMessageType($data['message_type'] ?? 'text');
            $request->setContent($data['content'] ?? '');
            
            list($response, $status) = $messageClient->SendMessage($request)->wait();
            
            echo json_encode([
                'success' => $response->getSuccess(),
                'message' => $response->getMessage(),
                'sent_message' => formatMessage($response->getSentMessage())
            ]);
        }
        break;

    case (preg_match('/^\/api\/conversations\/(.+)\/messages$/', $path, $matches) ? true : false):
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

    case '/api/messages/read':
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
        'status' => $msg->getStatus(),
        'created_at' => $msg->getCreatedAt(),
        'sequence_number' => $msg->getSequenceNumber(),
        'read_by' => $readBy
    ];
}