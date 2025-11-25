<?php

require_once __DIR__ . '/../vendor/autoload.php';

use Slim\Factory\AppFactory;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Configurar logger
$logger = new Logger('instagram-api');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

// Criar app Slim
$app = AppFactory::create();

// Middleware CORS
$app->add(function (Request $request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', '*')
        ->withHeader('Access-Control-Allow-Headers', 'X-Requested-With, Content-Type, Accept, Origin, Authorization')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, PATCH, OPTIONS');
});

// Middleware de parsing do body
$app->addBodyParsingMiddleware();

// Health check
$app->get('/health', function (Request $request, Response $response) {
    $response->getBody()->write(json_encode(['status' => 'healthy', 'connector' => 'instagram']));
    return $response->withHeader('Content-Type', 'application/json');
});

// Webhook para receber mensagens simuladas do Instagram
$app->post('/webhook/incoming', function (Request $request, Response $response) use ($logger) {
    $data = $request->getParsedBody();
    
    $logger->info('[Instagram] 📥 Mensagem recebida via webhook', [
        'from' => $data['from'] ?? 'unknown',
        'text' => $data['text'] ?? ''
    ]);
    
    // Simular processamento e resposta
    $result = [
        'status' => 'received',
        'message_id' => uniqid('instagram_'),
        'timestamp' => time(),
        'from' => $data['from'] ?? 'unknown',
        'text' => $data['text'] ?? ''
    ];
    
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json');
});

// Endpoint para simular envio de mensagem (para testes manuais)
$app->post('/send', function (Request $request, Response $response) use ($logger) {
    $data = $request->getParsedBody();
    
    $to = $data['to'] ?? 'unknown';
    $text = $data['text'] ?? '';
    
    $logger->info("[Instagram] 📤 Simulando envio", [
        'to' => $to,
        'text' => $text
    ]);
    
    // Simular delay de rede
    usleep(600000); // 0.6 segundos
    
    $result = [
        'status' => 'sent',
        'message_id' => uniqid('instagram_'),
        'timestamp' => time()
    ];
    
    $logger->info("[Instagram] ✅ Entregue a usuário {$to}");
    
    $response->getBody()->write(json_encode($result));
    return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
});

$app->run();
