<?php
/**
 * Chat4All API Service
 * Ponto de entrada da aplicação
 */

require __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Slim\Psr7\Request;
use Slim\Psr7\Response;
use Chat4All\Api\Middleware\AuthMiddleware;
use Chat4All\Api\Controller\AuthController;
use Chat4All\Api\Controller\MessageController;
use Chat4All\Api\Database\Database;
use Chat4All\Api\Service\KafkaProducer;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Carregar variáveis de ambiente
$env = [
    'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
    'DB_PORT' => getenv('DB_PORT') ?: '5432',
    'DB_NAME' => getenv('DB_NAME') ?: 'chat4all',
    'DB_USER' => getenv('DB_USER') ?: 'chat4all_user',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'chat4all_pass',
    'REDIS_HOST' => getenv('REDIS_HOST') ?: 'localhost',
    'REDIS_PORT' => getenv('REDIS_PORT') ?: '6379',
    'KAFKA_BROKERS' => getenv('KAFKA_BROKERS') ?: 'localhost:9092',
    'KAFKA_TOPIC_MESSAGES' => getenv('KAFKA_TOPIC_MESSAGES') ?: 'messages',
    'JWT_SECRET' => getenv('JWT_SECRET') ?: 'seu_secret_super_secreto',
    'JWT_EXPIRATION' => getenv('JWT_EXPIRATION') ?: '3600',
];

// Configurar logger
$logger = new Logger('api-service');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Criar aplicação Slim
$app = AppFactory::create();

// Adicionar middleware de parsing de JSON
$app->addBodyParsingMiddleware();

// Middleware de erro
$app->addErrorMiddleware(true, true, true);

// Criar instâncias de serviços
$database = new Database(
    $env['DB_HOST'],
    $env['DB_PORT'],
    $env['DB_NAME'],
    $env['DB_USER'],
    $env['DB_PASSWORD'],
    $logger
);

$kafkaProducer = new KafkaProducer(
    $env['KAFKA_BROKERS'],
    $env['KAFKA_TOPIC_MESSAGES'],
    $logger
);

// Controllers
$authController = new AuthController($database, $env['JWT_SECRET'], $env['JWT_EXPIRATION'], $logger);
$messageController = new MessageController($database, $kafkaProducer, $logger);

// ==========================================
// ROTAS PÚBLICAS (sem autenticação)
// ==========================================

// Rota raiz - Informações da API
$app->get('/', function (Request $request, Response $response) {
    $data = [
        'name' => 'Chat4All API',
        'version' => '1.0.0',
        'status' => 'running',
        'endpoints' => [
            'GET /health' => 'Health check',
            'POST /v1/auth/login' => 'Login e obter JWT token',
            'POST /v1/messages' => 'Enviar mensagem (requer autenticação)',
            'GET /v1/conversations/{id}/messages' => 'Listar mensagens (requer autenticação)',
            'GET /v1/conversations' => 'Listar conversas (requer autenticação)'
        ],
        'documentation' => 'https://github.com/chat4all/docs',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $response->getBody()->write(json_encode($data, JSON_PRETTY_PRINT));
    return $response->withHeader('Content-Type', 'application/json');
});

// Health check
$app->get('/health', function (Request $request, Response $response) use ($logger) {
    $logger->info('Health check requested');
    
    $data = [
        'status' => 'healthy',
        'service' => 'api-service',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// Login - gera JWT token
$app->post('/v1/auth/login', function (Request $request, Response $response) use ($authController) {
    return $authController->login($request, $response);
});

// ==========================================
// ROTAS PROTEGIDAS (requerem autenticação)
// ==========================================

// Criar middleware de autenticação
$authMiddleware = new AuthMiddleware($env['JWT_SECRET'], $logger);

// Enviar mensagem
$app->post('/v1/messages', function (Request $request, Response $response) use ($messageController) {
    return $messageController->sendMessage($request, $response);
})->add($authMiddleware);

// Listar mensagens de uma conversa
$app->get('/v1/conversations/{id}/messages', function (Request $request, Response $response, array $args) use ($messageController) {
    return $messageController->listMessages($request, $response, $args);
})->add($authMiddleware);

// Listar conversas do usuário
$app->get('/v1/conversations', function (Request $request, Response $response) use ($messageController) {
    return $messageController->listConversations($request, $response);
})->add($authMiddleware);

// ==========================================
// INICIAR SERVIDOR
// ==========================================

$logger->info('Starting Chat4All API Service on port 8080');

// Rodar aplicação
$app->run();
