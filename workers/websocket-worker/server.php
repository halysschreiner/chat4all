<?php
/**
 * ================================================
 * WebSocket Server - Chat4All
 * ================================================
 * 
 * Servidor WebSocket para notificações em tempo real.
 * Utiliza Ratchet para gerenciamento de conexões WebSocket
 * e Redis Pub/Sub para receber eventos de status.
 * 
 * Arquitetura:
 * - Conexões WebSocket autenticadas via JWT
 * - Redis Pub/Sub para receber eventos de status
 * - Kafka consumer para processar status-updates
 * 
 * @package Chat4All\WebSocket
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

require __DIR__ . '/vendor/autoload.php';

use Chat4All\WebSocket\WebSocketServer;
use Chat4All\WebSocket\StatusNotificationHandler;
use Chat4All\WebSocket\RedisSubscriber;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;
use React\EventLoop\Loop;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

// ================================================
// Configuração de Logging
// ================================================
$logger = new Logger('websocket');
$formatter = new LineFormatter(
    "[%datetime%] %channel%.%level_name%: %message% %context%\n",
    'Y-m-d H:i:s'
);
$handler = new StreamHandler('php://stdout', Logger::DEBUG);
$handler->setFormatter($formatter);
$logger->pushHandler($handler);

$logger->info('=== WebSocket Server Iniciando ===');

// ================================================
// Configuração do ambiente
// ================================================
$config = [
    'websocket_port' => (int) getenv('WEBSOCKET_PORT') ?: 8081,
    'redis_host' => getenv('REDIS_HOST') ?: 'redis',
    'redis_port' => (int) getenv('REDIS_PORT') ?: 6379,
    'jwt_secret' => getenv('JWT_SECRET') ?: 'chat4all_secret_key_2024',
    'kafka_brokers' => getenv('KAFKA_BROKERS') ?: 'kafka:9092',
];

$logger->info('Configuração carregada', [
    'port' => $config['websocket_port'],
    'redis' => $config['redis_host'] . ':' . $config['redis_port'],
]);

// ================================================
// Inicialização do Event Loop (React PHP)
// ================================================
$loop = Loop::get();

// ================================================
// Criar Handler de WebSocket
// ================================================
$wsHandler = new StatusNotificationHandler($logger, $config);

// ================================================
// ================================================
// Configurar Redis Subscriber para eventos
// ================================================
$redisSubscriber = new RedisSubscriber(
    $config['redis_host'],
    $config['redis_port'],
    $wsHandler,
    $logger,
    $loop
);

// Heartbeat para debug
$loop->addPeriodicTimer(5.0, function () use ($logger) {
    $logger->debug('WebSocket Server Heartbeat - Loop is running');
});

// ================================================
// Criar e iniciar servidor WebSocket
// ================================================
$wsServer = new WsServer($wsHandler);
$httpServer = new HttpServer($wsServer);

$socket = new \React\Socket\SocketServer('0.0.0.0:' . $config['websocket_port'], [], $loop);
$server = new \Ratchet\Server\IoServer($httpServer, $socket, $loop);

$logger->info('WebSocket Server rodando', [
    'address' => '0.0.0.0:' . $config['websocket_port'],
]);

// ================================================
// Rodar Event Loop
// ================================================
$loop->run();
