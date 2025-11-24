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
use Chat4All\Api\Controller\FileController;
use Chat4All\Api\Database\Database;
use Chat4All\Api\Service\KafkaProducer;
use Chat4All\Api\Service\MinioService;
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
    'MINIO_ENDPOINT' => getenv('MINIO_ENDPOINT') ?: 'localhost:9001',
    'MINIO_ACCESS_KEY' => getenv('MINIO_ACCESS_KEY') ?: 'chat4all_admin',
    'MINIO_SECRET_KEY' => getenv('MINIO_SECRET_KEY') ?: 'chat4all_minio_pass',
    'MINIO_BUCKET' => getenv('MINIO_BUCKET') ?: 'chat4all-files',
    'MINIO_USE_SSL' => getenv('MINIO_USE_SSL') === 'true',
];

// Configurar logger
$logger = new Logger('api-service');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Criar aplicação Slim
$app = AppFactory::create();

// ============================================
// Middleware CORS - Permitir acesso da interface web
// ============================================
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Access-Control-Max-Age', '3600');
});

// Adicionar rota OPTIONS para preflight CORS
$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

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

$minioService = new MinioService(
    $env['MINIO_ENDPOINT'],
    $env['MINIO_ACCESS_KEY'],
    $env['MINIO_SECRET_KEY'],
    $env['MINIO_BUCKET'],
    $env['MINIO_USE_SSL'],
    $logger
);

// Controllers
$authController = new AuthController($database, $env['JWT_SECRET'], $env['JWT_EXPIRATION'], $logger);
$messageController = new MessageController($database, $kafkaProducer, $logger);
$fileController = new FileController($database, $minioService, $logger);

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
// ROTAS DE ARQUIVOS (protegidas)
// ==========================================

// Iniciar upload multipart
$app->post('/v1/files/upload/initiate', function (Request $request, Response $response) use ($fileController) {
    return $fileController->initiateUpload($request, $response);
})->add($authMiddleware);

// Upload de parte
$app->post('/v1/files/upload/part', function (Request $request, Response $response) use ($fileController) {
    return $fileController->uploadPart($request, $response);
})->add($authMiddleware);

// Completar upload
$app->post('/v1/files/upload/complete', function (Request $request, Response $response) use ($fileController) {
    return $fileController->completeUpload($request, $response);
})->add($authMiddleware);

// Cancelar upload
$app->post('/v1/files/upload/abort', function (Request $request, Response $response) use ($fileController) {
    return $fileController->abortUpload($request, $response);
})->add($authMiddleware);

// Obter informações do arquivo
$app->get('/v1/files/{id}', function (Request $request, Response $response, array $args) use ($fileController) {
    return $fileController->getFileInfo($request, $response, $args);
})->add($authMiddleware);

// Gerar URL de download
$app->get('/v1/files/{id}/download', function (Request $request, Response $response, array $args) use ($fileController) {
    return $fileController->getDownloadUrl($request, $response, $args);
})->add($authMiddleware);

// Listar arquivos de uma conversa
$app->get('/v1/conversations/{id}/files', function (Request $request, Response $response, array $args) use ($fileController) {
    return $fileController->listFiles($request, $response, $args);
})->add($authMiddleware);

// Deletar arquivo
$app->delete('/v1/files/{id}', function (Request $request, Response $response, array $args) use ($fileController) {
    return $fileController->deleteFile($request, $response, $args);
})->add($authMiddleware);

// ==========================================
// INICIAR SERVIDOR
// ==========================================

$logger->info('Starting Chat4All API Service on port 8080');

// Rodar aplicação
$app->run();
