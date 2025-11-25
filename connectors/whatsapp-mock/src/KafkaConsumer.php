<?php

namespace Chat4All\Connector\WhatsApp;

use Psr\Log\LoggerInterface;

class KafkaConsumer
{
    private array $config;
    private MessageProcessor $processor;
    private LoggerInterface $logger;

    public function __construct(array $config, MessageProcessor $processor, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->processor = $processor;
        $this->logger = $logger;
    }

    public function consume(): void
    {
        $conf = new \RdKafka\Conf();
        $conf->set('group.id', $this->config['group_id']);
        $conf->set('metadata.broker.list', $this->config['bootstrap_servers']);
        $conf->set('auto.offset.reset', 'earliest');
        $conf->set('enable.auto.commit', 'true');

        $consumer = new \RdKafka\KafkaConsumer($conf);
        $consumer->subscribe([$this->config['topic']]);

        $this->logger->info('✅ Subscribed to topic: ' . $this->config['topic']);
        $this->logger->info('🔄 Waiting for messages...');

        while (true) {
            $message = $consumer->consume(1000);

            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->processor->process($message->payload);
                    break;

                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    // Sem novas mensagens
                    break;

                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    // Timeout esperando mensagens
                    break;

                default:
                    $this->logger->error('Kafka error: ' . $message->errstr());
                    break;
            }

            // Pequena pausa para não sobrecarregar CPU
            usleep(100000); // 0.1 segundo
        }
    }
}
