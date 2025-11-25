<?php

require_once __DIR__ . '/vendor/autoload.php';

use Chat4All\Connector\Instagram\KafkaConsumer;
use Chat4All\Connector\Instagram\MessageProcessor;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Configurar logger
$logger = new Logger('instagram-connector');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$logger->info('🚀 Instagram Mock Connector Consumer starting...');

// Configurar Kafka
$kafkaConfig = [
    'bootstrap_servers' => getenv('KAFKA_BROKER') ?: 'kafka:9092',
    'group_id' => 'instagram-connector-group',
    'topic' => 'instagram.messages'
];

// Criar processor
$processor = new MessageProcessor($logger);

// Criar e iniciar consumer
$consumer = new KafkaConsumer($kafkaConfig, $processor, $logger);
$consumer->consume();
