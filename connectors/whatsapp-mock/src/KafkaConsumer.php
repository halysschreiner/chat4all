<?php

namespace Chat4All\Connector\WhatsApp;

use Psr\Log\LoggerInterface;

/**
 * Consumidor Kafka para o Connector WhatsApp Mock
 * 
 * Este componente implementa o padrão Consumer do Apache Kafka, responsável por:
 * - Consumir mensagens do tópico 'whatsapp.messages'
 * - Processar mensagens e simular entrega ao WhatsApp
 * - Enviar callbacks de status (DELIVERED, READ) de volta à API
 * 
 * CONCEITO DE SISTEMAS DISTRIBUÍDOS:
 * O Consumer Group (whatsapp-connector-group) permite escalabilidade horizontal.
 * Múltiplas instâncias deste connector podem rodar em paralelo, cada uma
 * consumindo de partições diferentes do mesmo tópico.
 * 
 * TOLERÂNCIA A FALHAS:
 * Com commit manual (enable.auto.commit=false), garantimos que mensagens
 * só são marcadas como processadas após processamento bem-sucedido.
 * Em caso de falha, a mensagem será reprocessada automaticamente.
 */
class KafkaConsumer
{
    private array $config;
    private MessageProcessor $processor;
    private LoggerInterface $logger;
    private bool $shutdown = false;

    public function __construct(array $config, MessageProcessor $processor, LoggerInterface $logger)
    {
        $this->config = $config;
        $this->processor = $processor;
        $this->logger = $logger;
        
        // Registrar handler para shutdown graceful
        $this->registerShutdownHandler();
    }

    /**
     * Registra handlers para shutdown graceful
     * 
     * CONCEITO DE SISTEMAS DISTRIBUÍDOS:
     * Graceful shutdown é essencial em sistemas distribuídos para garantir
     * que mensagens em processamento sejam concluídas antes do encerramento,
     * evitando reprocessamento desnecessário.
     */
    private function registerShutdownHandler(): void
    {
        // Handler para SIGTERM (Docker stop, Kubernetes termination)
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, function () {
                $this->logger->info('🛑 Received SIGTERM, initiating graceful shutdown...');
                $this->shutdown = true;
            });
            pcntl_signal(SIGINT, function () {
                $this->logger->info('🛑 Received SIGINT, initiating graceful shutdown...');
                $this->shutdown = true;
            });
        }
    }

    /**
     * Inicia o loop de consumo de mensagens
     * 
     * PADRÃO POLL-PROCESS-COMMIT:
     * 1. Poll: busca mensagens do broker Kafka
     * 2. Process: processa a mensagem (simula entrega ao WhatsApp)
     * 3. Commit: confirma o offset somente após sucesso
     * 
     * Isso garante at-least-once delivery semantics.
     */
    public function consume(): void
    {
        $conf = new \RdKafka\Conf();
        $conf->set('group.id', $this->config['group_id']);
        $conf->set('metadata.broker.list', $this->config['bootstrap_servers']);
        $conf->set('auto.offset.reset', 'earliest');
        
        // TOLERÂNCIA A FALHAS: Desabilitar auto-commit para controle manual
        $conf->set('enable.auto.commit', 'false');
        
        // Configurações de sessão para rebalanceamento rápido
        $conf->set('session.timeout.ms', '10000');
        $conf->set('heartbeat.interval.ms', '3000');
        $conf->set('max.poll.interval.ms', '300000');

        $consumer = new \RdKafka\KafkaConsumer($conf);
        $consumer->subscribe([$this->config['topic']]);

        $this->logger->info('✅ Subscribed to topic: ' . $this->config['topic']);
        $this->logger->info('🔄 Waiting for messages (manual commit enabled)...');

        while (!$this->shutdown) {
            // Processar signals pendentes
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }

            $message = $consumer->consume(1000);

            switch ($message->err) {
                case RD_KAFKA_RESP_ERR_NO_ERROR:
                    $this->logger->info('📨 Message received', [
                        'partition' => $message->partition,
                        'offset' => $message->offset
                    ]);
                    
                    try {
                        // Processar mensagem
                        $this->processor->process($message->payload);
                        
                        // COMMIT MANUAL: Só commita após sucesso
                        $consumer->commit($message);
                        $this->logger->debug('✅ Offset committed', [
                            'partition' => $message->partition,
                            'offset' => $message->offset
                        ]);
                    } catch (\Exception $e) {
                        // NÃO commita em caso de erro - mensagem será reprocessada
                        $this->logger->error('❌ Failed to process message (NOT committed): ' . $e->getMessage(), [
                            'partition' => $message->partition,
                            'offset' => $message->offset
                        ]);
                    }
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

        // GRACEFUL SHUTDOWN: Fechar consumer de forma limpa
        $consumer->close();
        $this->logger->info('👋 Consumer closed gracefully');
    }
}
