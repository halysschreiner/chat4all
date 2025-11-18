<?php
/**
 * Chat4All Router Worker
 * Consome mensagens do Kafka e atualiza status para DELIVERED
 */

require __DIR__ . '/vendor/autoload.php';

use Chat4All\Worker\KafkaConsumer;
use Chat4All\Worker\Database;
use Chat4All\Worker\MessageProcessor;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Carregar variáveis de ambiente
$env = [
    'DB_HOST' => getenv('DB_HOST') ?: 'localhost',
    'DB_PORT' => getenv('DB_PORT') ?: '5432',
    'DB_NAME' => getenv('DB_NAME') ?: 'chat4all',
    'DB_USER' => getenv('DB_USER') ?: 'chat4all_user',
    'DB_PASSWORD' => getenv('DB_PASSWORD') ?: 'chat4all_pass',
    'KAFKA_BROKERS' => getenv('KAFKA_BROKERS') ?: 'localhost:9092',
    'KAFKA_TOPIC_MESSAGES' => getenv('KAFKA_TOPIC_MESSAGES') ?: 'messages',
    'KAFKA_GROUP_ID' => getenv('KAFKA_GROUP_ID') ?: 'router-worker-group',
];

// Configurar logger
$logger = new Logger('router-worker');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$logger->info('Starting Router Worker');
$logger->info('Configuration', [
    'db_host' => $env['DB_HOST'],
    'kafka_brokers' => $env['KAFKA_BROKERS'],
    'kafka_topic' => $env['KAFKA_TOPIC_MESSAGES'],
    'kafka_group' => $env['KAFKA_GROUP_ID']
]);

// Criar instâncias de serviços
$database = new Database(
    $env['DB_HOST'],
    $env['DB_PORT'],
    $env['DB_NAME'],
    $env['DB_USER'],
    $env['DB_PASSWORD'],
    $logger
);

$messageProcessor = new MessageProcessor($database, $logger);

$consumer = new KafkaConsumer(
    $env['KAFKA_BROKERS'],
    $env['KAFKA_TOPIC_MESSAGES'],
    $env['KAFKA_GROUP_ID'],
    $messageProcessor,
    $logger
);

// Variável de controle para shutdown
$shutdown = false;

// Iniciar consumo
$logger->info('Worker started, waiting for messages...');

try {
    $consumer->consume($shutdown);
} catch (Exception $e) {
    $logger->error('Worker error: ' . $e->getMessage());
    exit(1);
}

$logger->info('Worker stopped');
exit(0);
