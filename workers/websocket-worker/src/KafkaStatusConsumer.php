<?php

declare(strict_types=1);

namespace Chat4All\WebSocket;

use Psr\Log\LoggerInterface;
use RdKafka\Conf;
use RdKafka\Consumer;
use RdKafka\TopicConf;

/**
 * KafkaStatusConsumer - Consome eventos de status do Kafka
 * 
 * Escuta o tópico status-updates e encaminha eventos para
 * o WebSocket handler para broadcast aos clientes conectados.
 */
class KafkaStatusConsumer
{
    private LoggerInterface $logger;
    private Consumer $consumer;
    private string $topic;
    private string $groupId;
    private bool $running = false;
    
    /** @var callable|null */
    private $messageHandler = null;

    public function __construct(
        LoggerInterface $logger,
        string $brokers = 'kafka:9092',
        string $topic = 'status-updates',
        string $groupId = 'websocket-status-group'
    ) {
        $this->logger = $logger;
        $this->topic = $topic;
        $this->groupId = $groupId;

        $this->initializeConsumer($brokers);
    }

    /**
     * Inicializa o consumer Kafka
     */
    private function initializeConsumer(string $brokers): void
    {
        $conf = new Conf();
        $conf->set('group.id', $this->groupId);
        $conf->set('metadata.broker.list', $brokers);
        $conf->set('auto.offset.reset', 'latest');
        $conf->set('enable.auto.commit', 'true');
        $conf->set('auto.commit.interval.ms', '1000');

        // Callbacks de log
        $conf->setErrorCb(function ($kafka, $err, $reason) {
            $this->logger->error("Kafka error: {$reason}", ['error' => $err]);
        });

        $conf->setRebalanceCb(function ($kafka, $err, $partitions) {
            switch ($err) {
                case RD_KAFKA_RESP_ERR__ASSIGN_PARTITIONS:
                    $this->logger->info('Partitions assigned', [
                        'count' => count($partitions)
                    ]);
                    $kafka->assign($partitions);
                    break;

                case RD_KAFKA_RESP_ERR__REVOKE_PARTITIONS:
                    $this->logger->info('Partitions revoked');
                    $kafka->assign(null);
                    break;

                default:
                    $this->logger->error("Kafka rebalance error", ['error' => $err]);
            }
        });

        $this->consumer = new Consumer($conf);
        
        $this->logger->info('Kafka consumer initialized', [
            'brokers' => $brokers,
            'topic' => $this->topic,
            'groupId' => $this->groupId
        ]);
    }

    /**
     * Define o handler para mensagens recebidas
     */
    public function setMessageHandler(callable $handler): void
    {
        $this->messageHandler = $handler;
    }

    /**
     * Inicia o consumo de mensagens
     */
    public function start(): void
    {
        if (!$this->messageHandler) {
            throw new \RuntimeException('Message handler not set');
        }

        $this->running = true;

        $topicConf = new TopicConf();
        $topicConf->set('auto.offset.reset', 'latest');

        $topic = $this->consumer->newTopic($this->topic, $topicConf);
        $topic->consumeStart(0, RD_KAFKA_OFFSET_END);

        $this->logger->info("Started consuming from topic: {$this->topic}");

        while ($this->running) {
            $message = $topic->consume(0, 100); // 100ms timeout

            if ($message === null) {
                continue;
            }

            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->processMessage($message);
                    break;

                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    // End of partition, normal
                    break;

                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    // Timeout, normal
                    break;

                default:
                    $this->logger->warning('Kafka consume error', [
                        'error' => $message->errstr(),
                        'code' => $message->err
                    ]);
            }
        }
    }

    /**
     * Processa uma mensagem recebida
     */
    private function processMessage($message): void
    {
        try {
            $payload = json_decode($message->payload, true);

            if (!$payload) {
                $this->logger->warning('Invalid JSON payload', [
                    'payload' => substr($message->payload, 0, 100)
                ]);
                return;
            }

            $this->logger->debug('Processing status message', [
                'message_id' => $payload['message_id'] ?? 'unknown',
                'status' => $payload['status'] ?? 'unknown'
            ]);

            // Chamar o handler
            ($this->messageHandler)($payload);

        } catch (\Exception $e) {
            $this->logger->error('Error processing message: ' . $e->getMessage());
        }
    }

    /**
     * Para o consumo
     */
    public function stop(): void
    {
        $this->running = false;
        $this->logger->info('Kafka consumer stopping...');
    }

    /**
     * Verifica se está rodando
     */
    public function isRunning(): bool
    {
        return $this->running;
    }
}
