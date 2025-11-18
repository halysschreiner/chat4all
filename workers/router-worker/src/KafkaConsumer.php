<?php

namespace Chat4All\Worker;

use RdKafka\Consumer as RdKafkaConsumer;
use RdKafka\ConsumerTopic;
use Monolog\Logger;

/**
 * Consumidor Kafka
 * Consome mensagens do tópico e processa
 */
class KafkaConsumer
{
    private RdKafkaConsumer $consumer;
    private ConsumerTopic $topic;
    private MessageProcessor $processor;
    private Logger $logger;

    /**
     * Construtor - inicializa consumidor Kafka
     */
    public function __construct(
        string $brokers,
        string $topicName,
        string $groupId,
        MessageProcessor $processor,
        Logger $logger
    ) {
        $this->processor = $processor;
        $this->logger = $logger;

        try {
            // Criar instância do Consumer
            $conf = new \RdKafka\Conf();
            
            // Configurações básicas
            $conf->set('metadata.broker.list', $brokers);
            $conf->set('group.id', $groupId);
            $conf->set('auto.offset.reset', 'earliest');
            $conf->set('enable.auto.commit', 'true');

            // Criar consumer
            $this->consumer = new RdKafkaConsumer($conf);
            $this->consumer->addBrokers($brokers);

            // Criar tópico
            $topicConf = new \RdKafka\TopicConf();
            $this->topic = $this->consumer->newTopic($topicName, $topicConf);

            $this->logger->info("Kafka consumer initialized", [
                'brokers' => $brokers,
                'topic' => $topicName,
                'group_id' => $groupId
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize Kafka consumer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Consumir mensagens do Kafka
     */
    public function consume(bool &$shutdown): void
    {
        // Iniciar consumo na partição 0 (para simplificar)
        // Em produção, usar High-level Consumer API para múltiplas partições
        $this->topic->consumeStart(0, RD_KAFKA_OFFSET_STORED);

        $this->logger->info('Starting message consumption');

        while (!$shutdown) {
            // Consumir mensagem com timeout de 1 segundo
            $message = $this->topic->consume(0, 1000);

            if ($message === null) {
                continue;
            }

            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    // Mensagem recebida com sucesso
                    $this->logger->info('Message received from Kafka', [
                        'partition' => $message->partition,
                        'offset' => $message->offset
                    ]);

                    try {
                        // Processar mensagem
                        $this->processor->process($message->payload);
                    } catch (\Exception $e) {
                        $this->logger->error('Failed to process message: ' . $e->getMessage(), [
                            'payload' => $message->payload
                        ]);
                    }
                    break;

                case RD_KAFKA_RESP_ERR__PARTITION_EOF:
                    // Fim da partição, aguardar novas mensagens
                    $this->logger->debug('End of partition reached');
                    break;

                case RD_KAFKA_RESP_ERR__TIMED_OUT:
                    // Timeout, continuar aguardando
                    break;

                default:
                    $this->logger->error('Kafka error: ' . $message->errstr(), [
                        'error_code' => $message->err
                    ]);
                    break;
            }
        }

        // Parar consumo
        $this->topic->consumeStop(0);
        $this->logger->info('Message consumption stopped');
    }
}
