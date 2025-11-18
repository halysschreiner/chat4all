<?php

namespace Chat4All\Api\Service;

use RdKafka\Producer;
use RdKafka\ProducerTopic;
use Monolog\Logger;

/**
 * Produtor Kafka - publica mensagens no tópico
 */
class KafkaProducer
{
    private Producer $producer;
    private ProducerTopic $topic;
    private Logger $logger;

    /**
     * Construtor - inicializa produtor Kafka
     */
    public function __construct(
        string $brokers,
        string $topicName,
        Logger $logger
    ) {
        $this->logger = $logger;

        try {
            // Criar instância do Producer
            $this->producer = new Producer();
            
            // Adicionar brokers
            $this->producer->addBrokers($brokers);

            // Criar tópico
            $this->topic = $this->producer->newTopic($topicName);

            $this->logger->info("Kafka producer initialized", [
                'brokers' => $brokers,
                'topic' => $topicName
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize Kafka producer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Publicar mensagem no Kafka
     * 
     * @param array $message Dados da mensagem
     * @param string|null $key Chave de particionamento (conversation_id para garantir ordem)
     */
    public function publish(array $message, ?string $key = null): void
    {
        try {
            $payload = json_encode($message);

            // Produzir mensagem
            // RD_KAFKA_PARTITION_UA = usar particionamento automático baseado na key
            $this->topic->produce(RD_KAFKA_PARTITION_UA, 0, $payload, $key);

            // Poll para enviar as mensagens
            $this->producer->poll(0);

            $this->logger->info('Message published to Kafka', [
                'message_id' => $message['message_id'] ?? 'unknown',
                'key' => $key
            ]);

            // Garantir que mensagens sejam enviadas
            for ($flushRetries = 0; $flushRetries < 10; $flushRetries++) {
                $result = $this->producer->flush(1000);
                if (RD_KAFKA_RESP_ERR_NO_ERROR === $result) {
                    break;
                }
            }

            if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $this->logger->warning('Kafka flush incomplete', ['result' => $result]);
            }
        } catch (\Exception $e) {
            $this->logger->error('Failed to publish message to Kafka: ' . $e->getMessage(), [
                'message' => $message
            ]);
            throw $e;
        }
    }

    /**
     * Destrutor - flush final das mensagens
     */
    public function __destruct()
    {
        if (isset($this->producer)) {
            $this->producer->flush(5000);
        }
    }
}
