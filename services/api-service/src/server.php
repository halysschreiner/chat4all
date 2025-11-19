<?php

require __DIR__ . '/../vendor/autoload.php';

use Grpc\Server;
use Chat4All\Api\Grpc\MessageService;
use Chat4All\Api\Grpc\AuthService;
use Chat4All\Api\Grpc\ConversationService;
use Chat4All\Api\Database\Database;
use Chat4All\Api\Service\KafkaProducer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Carregar variáveis de ambiente
$dbHost = getenv('DB_HOST') ?: 'localhost';
$dbPort = getenv('DB_PORT') ?: '5432';
$dbName = getenv('DB_NAME') ?: 'chat4all';
$dbUser = getenv('DB_USER') ?: 'chat4all_user';
$dbPass = getenv('DB_PASSWORD') ?: 'chat4all_pass';
$kafkaBrokers = getenv('KAFKA_BROKERS') ?: 'localhost:9092';
$kafkaTopic = getenv('KAFKA_TOPIC_MESSAGES') ?: 'messages';

// Configurar logger
$logger = new Logger('grpc-server');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

try {
    $logger->info("Initializing services...");
    
    $db = new Database($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $logger);
    $kafka = new KafkaProducer($kafkaBrokers, $kafkaTopic, $logger);
    $jwtSecret = getenv('JWT_SECRET') ?: 'default-secret';
    
    $service = new MessageService($db, $kafka, $logger);
    $authService = new AuthService($db, $logger, $jwtSecret);
    $conversationService = new ConversationService($db, $logger);
    
    $server = new Server();
    $server->addHttp2Port('0.0.0.0:50051');
    $server->start();
    
    $logger->info("Starting gRPC server on 0.0.0.0:50051");
    
    while (true) {
        $event = $server->requestCall();
        if (!$event || !$event->call) {
            continue;
        }
        
        $call = $event->call;
        $method = $event->method;
        
        $logger->info("Received request: $method");
        
        // Parse method: /message.MessageService/SendMessage
        $parts = explode('/', trim($method, '/'));
        if (count($parts) !== 2) {
            $logger->warning("Invalid method format: $method");
            continue;
        }
        
        $serviceName = $parts[0];
        $methodName = $parts[1];
        
        if ($serviceName !== 'message.MessageService' && $serviceName !== 'auth.AuthService' && $serviceName !== 'conversation.ConversationService') {
             $logger->warning("Unknown service: $serviceName");
             continue;
        }
        
        // Read request payload
        $batch = $call->startBatch([
            Grpc\OP_RECV_MESSAGE => true
        ]);
        
        $payload = $batch->message;
        if ($payload === null) {
             $logger->warning("Empty payload");
             continue;
        }
        
        // Dispatch
        try {
            $response = null;
            
            if ($serviceName === 'message.MessageService') {
                if ($methodName === 'SendMessage') {
                    $request = new \Message\SendMessageRequest();
                    $request->mergeFromString($payload);
                    $response = $service->SendMessage($request);
                } elseif ($methodName === 'ListMessages') {
                    $request = new \Message\ListMessagesRequest();
                    $request->mergeFromString($payload);
                    $response = $service->ListMessages($request);
                } else {
                    $logger->warning("Unknown method: $methodName");
                    continue;
                }
            } elseif ($serviceName === 'auth.AuthService') {
                if ($methodName === 'Register') {
                    $request = new \Auth\RegisterRequest();
                    $request->mergeFromString($payload);
                    $response = $authService->Register($request);
                } elseif ($methodName === 'Login') {
                    $request = new \Auth\LoginRequest();
                    $request->mergeFromString($payload);
                    $response = $authService->Login($request);
                } elseif ($methodName === 'ValidateToken') {
                    $request = new \Auth\ValidateTokenRequest();
                    $request->mergeFromString($payload);
                    $response = $authService->ValidateToken($request);
                } else {
                    $logger->warning("Unknown method: $methodName");
                    continue;
                }
            } elseif ($serviceName === 'conversation.ConversationService') {
                if ($methodName === 'CreatePrivateConversation') {
                    $request = new \Conversation\CreatePrivateConversationRequest();
                    $request->mergeFromString($payload);
                    $response = $conversationService->CreatePrivateConversation($request);
                } elseif ($methodName === 'CreateGroup') {
                    $request = new \Conversation\CreateGroupRequest();
                    $request->mergeFromString($payload);
                    $response = $conversationService->CreateGroup($request);
                } elseif ($methodName === 'ListConversations') {
                    $request = new \Conversation\ListConversationsRequest();
                    $request->mergeFromString($payload);
                    $response = $conversationService->ListConversations($request);
                } else {
                    $logger->warning("Unknown method: $methodName");
                    continue;
                }
            }
            
            if ($response) {
                $responsePayload = $response->serializeToString();
                $call->startBatch([
                    Grpc\OP_SEND_INITIAL_METADATA => [],
                    Grpc\OP_SEND_MESSAGE => ['message' => $responsePayload],
                    Grpc\OP_SEND_STATUS_FROM_SERVER => [
                        'metadata' => [],
                        'code' => Grpc\STATUS_OK,
                        'details' => 'OK'
                    ]
                ]);
            }
            
        } catch (\Exception $e) {
            $logger->error("Error handling request: " . $e->getMessage());
            $call->startBatch([
                Grpc\OP_SEND_STATUS_FROM_SERVER => [
                    'metadata' => [],
                    'code' => Grpc\STATUS_INTERNAL,
                    'details' => $e->getMessage()
                ]
            ]);
        }
    }
    
} catch (\Exception $e) {
    $logger->error("Server failed: " . $e->getMessage());
    exit(1);
}
