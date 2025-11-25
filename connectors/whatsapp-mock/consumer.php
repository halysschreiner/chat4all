<?php

require_once __DIR__ . '/vendor/autoload.php';

use Chat4All\Connector\WhatsApp\KafkaConsumer;
use Chat4All\Connector\WhatsApp\MessageProcessor;
use Monolog\Logger;
use Monolog\Handler\StreamHandler;

// Configurar logger
$logger = new Logger('whatsapp-connector');
$logger->pushHandler(new StreamHandler('php://stdout', Logger::INFO));

$logger->info('🚀 WhatsApp Mock Connector Consumer starting...');

// Configurar Kafka
$kafkaConfig = [
    'bootstrap_servers' => getenv('KAFKA_BROKER') ?: 'kafka:9092',
    'group_id' => 'whatsapp-connector-group',
    'topic' => 'whatsapp.messages'
];

// Criar processor
$processor = new MessageProcessor($logger);

// Criar e iniciar consumer
$consumer = new KafkaConsumer($kafkaConfig, $processor, $logger);
$consumer->consume();
