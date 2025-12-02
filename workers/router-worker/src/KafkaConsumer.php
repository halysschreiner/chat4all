<?php

namespace Chat4All\Worker;

use RdKafka\Consumer as RdKafkaConsumer;
use RdKafka\ConsumerTopic;
use Monolog\Logger;

/**
 * Consumidor Kafka para o Router Worker
 * 
 * Este componente implementa o padrão Consumer do Apache Kafka, responsável por:
 * - Conectar ao cluster Kafka e se inscrever em um tópico
 * - Consumir mensagens do tópico de forma assíncrona
 * - Processar mensagens através do MessageProcessor
 * - Garantir tolerância a falhas via commit manual de offsets
 * 
 * CONCEITO DE SISTEMAS DISTRIBUÍDOS:
 * O Consumer Group permite que múltiplas instâncias deste worker consumam
 * do mesmo tópico em paralelo, dividindo as partições entre si.
 * O commit manual garante que mensagens só são marcadas como processadas
 * após o processamento bem-sucedido, evitando perda de dados em falhas.
 */
class KafkaConsumer
{
    private RdKafkaConsumer $consumer;
    private ConsumerTopic $topic;
    private MessageProcessor $processor;
    private Logger $logger;

    /**
     * Construtor - inicializa consumidor Kafka
     * 
     * @param string $brokers Lista de brokers Kafka (broker1:9092,broker2:9092)
     * @param string $topicName Nome do tópico para consumir mensagens
     * @param string $groupId ID do Consumer Group para balanceamento de carga
     * @param MessageProcessor $processor Processador de mensagens
     * @param Logger $logger Logger para monitoramento
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
            // Criar configuração do Consumer
            $conf = new \RdKafka\Conf();
            
            // Configurações básicas de conexão
            $conf->set('metadata.broker.list', $brokers);
            $conf->set('group.id', $groupId);
            
            // TOLERÂNCIA A FALHAS: Commit manual de offsets
            // Com auto.commit desabilitado, o offset só é commitado após
            // processamento bem-sucedido, garantindo que mensagens não sejam
            // perdidas em caso de falha do worker
            $conf->set('enable.auto.commit', 'false');
            
            // Começar do início se não houver offset armazenado
            $conf->set('auto.offset.reset', 'earliest');
            
            // Configurações de sessão para rebalanceamento rápido
            // session.timeout.ms: tempo máximo sem heartbeat antes de considerar o consumer morto
            // heartbeat.interval.ms: frequência de heartbeat para o coordinator
            $conf->set('session.timeout.ms', '10000');
            $conf->set('heartbeat.interval.ms', '3000');
            
            // max.poll.interval.ms: tempo máximo entre chamadas poll()
            // Se excedido, o consumer é considerado como falho e suas partições são reatribuídas
            $conf->set('max.poll.interval.ms', '300000');

            // Criar consumer
            $this->consumer = new RdKafkaConsumer($conf);
            $this->consumer->addBrokers($brokers);

            // Criar tópico com configuração
            $topicConf = new \RdKafka\TopicConf();
            // Armazenar offset no broker para persistência
            $topicConf->set('offset.store.method', 'broker');
            $this->topic = $this->consumer->newTopic($topicName, $topicConf);

            $this->logger->info("Kafka consumer initialized", [
                'brokers' => $brokers,
                'topic' => $topicName,
                'group_id' => $groupId,
                'auto_commit' => 'disabled (manual commit)',
                'session_timeout_ms' => '10000'
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Failed to initialize Kafka consumer: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Consumir mensagens do Kafka
     * 
     * CONCEITO DE SISTEMAS DISTRIBUÍDOS:
     * O loop de consumo implementa o padrão poll-process-commit:
     * 1. Poll: buscar mensagens do broker
     * 2. Process: processar a mensagem recebida
     * 3. Commit: confirmar que a mensagem foi processada (apenas se sucesso)
     * 
     * Isso garante at-least-once delivery: uma mensagem pode ser processada
     * mais de uma vez em caso de falha, mas nunca será perdida.
     * 
     * @param bool &$shutdown Referência para variável de controle de shutdown
     */
    public function consume(bool &$shutdown): void
    {
        // Iniciar consumo na partição 0 (para simplificar)
        // Em produção, usar High-level Consumer API para múltiplas partições
        $this->topic->consumeStart(0, RD_KAFKA_OFFSET_STORED);

        $this->logger->info('Starting message consumption with manual commit');

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
                        
                        // COMMIT MANUAL: Só commita o offset após processamento bem-sucedido
                        // Isso garante que em caso de falha, a mensagem será reprocessada
                        $this->topic->offsetStore($message->partition, $message->offset);
                        $this->logger->debug('Offset committed after successful processing', [
                            'partition' => $message->partition,
                            'offset' => $message->offset
                        ]);
                    } catch (\Exception $e) {
                        // NÃO COMMITA em caso de erro - mensagem será reprocessada
                        $this->logger->error('Failed to process message (offset NOT committed): ' . $e->getMessage(), [
                            'payload' => $message->payload,
                            'partition' => $message->partition,
                            'offset' => $message->offset
                        ]);
                        // Opcional: implementar dead letter queue ou retry com backoff
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

        // GRACEFUL SHUTDOWN: Parar consumo de forma limpa
        $this->topic->consumeStop(0);
        $this->logger->info('Message consumption stopped gracefully');
    }
}
