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
    private ?Producer $producer = null;
    private ?ProducerTopic $topic = null;
    private Logger $logger;
    private string $brokers;
    private string $topicName;
    private bool $connected = false;

    /**
     * Construtor - configura produtor Kafka (lazy initialization)
     */
    public function __construct(
        string $brokers,
        string $topicName,
        Logger $logger
    ) {
        $this->logger = $logger;
        $this->brokers = $brokers;
        $this->topicName = $topicName;

        $this->logger->info("Kafka producer configured (lazy init)", [
            'brokers' => $brokers,
            'topic' => $topicName
        ]);
    }

    /**
     * Conectar ao Kafka com retry logic e exponential backoff
     */
    private function connect(): void
    {
        if ($this->connected) {
            return;
        }

        $maxRetries = 5;
        $retryDelay = 1; // segundos

        for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
            try {
                $this->producer = new Producer();
                $this->producer->addBrokers($this->brokers);
                $this->topic = $this->producer->newTopic($this->topicName);
                $this->connected = true;
                
                $this->logger->info("Kafka producer connected successfully", [
                    'attempt' => $attempt
                ]);
                return;
            } catch (\Exception $e) {
                $this->logger->warning("Kafka connection attempt $attempt/$maxRetries failed: " . $e->getMessage());
                
                if ($attempt < $maxRetries) {
                    sleep($retryDelay);
                    $retryDelay *= 2; // exponential backoff
                }
            }
        }

        throw new \RuntimeException("Failed to connect to Kafka after $maxRetries attempts");
    }

    /**
     * Publicar mensagem no Kafka
     * 
     * @param array $message Dados da mensagem
     * @param string|null $key Chave de particionamento (conversation_id para garantir ordem)
     */
    public function publish(array $message, ?string $key = null): void
    {
        // Lazy initialization - conecta apenas quando necessário
        $this->connect();
        
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
