<?php

namespace Chat4All\Worker;

use Monolog\Logger;

/**
 * Processador de mensagens
 * Processa mensagens consumidas do Kafka e atualiza status
 */
class MessageProcessor
{
    private Database $database;
    private Logger $logger;

    public function __construct(Database $database, Logger $logger)
    {
        $this->database = $database;
        $this->logger = $logger;
    }

    /**
     * Processar mensagem recebida do Kafka
     */
    public function process(string $payload): void
    {
        // Decodificar JSON
        $message = json_decode($payload, true);

        if (!$message) {
            $this->logger->error('Invalid JSON payload', ['payload' => $payload]);
            return;
        }

        $this->logger->info('Processing message', [
            'message_id' => $message['message_id'] ?? 'unknown',
            'conversation_id' => $message['conversation_id'] ?? 'unknown'
        ]);

        // Validar dados obrigatórios
        if (!isset($message['message_id']) || !isset($message['conversation_id'])) {
            $this->logger->error('Missing required fields in message', ['message' => $message]);
            return;
        }

        try {
            // Simular processamento de roteamento
            // Em uma implementação real, aqui seria feito:
            // 1. Identificar canais de destino (WhatsApp, Telegram, etc)
            // 2. Enviar para os connectors apropriados
            // 3. Aguardar confirmação de entrega
            
            $this->logger->info('Routing message to channels', [
                'message_id' => $message['message_id']
            ]);

            // Simular delay de processamento (simula envio para canais externos)
            usleep(100000); // 100ms

            // Atualizar status para DELIVERED
            $updated = $this->database->updateMessageStatus(
                $message['message_id'],
                'DELIVERED',
                'delivered_at'
            );

            if ($updated) {
                $this->logger->info('Message status updated to DELIVERED', [
                    'message_id' => $message['message_id']
                ]);

                // Log de auditoria
                $this->database->insertAuditLog(
                    'message.delivered',
                    'message',
                    $message['message_id'],
                    $message['from_user_id'] ?? null,
                    [
                        'conversation_id' => $message['conversation_id'],
                        'processed_by' => 'router-worker'
                    ]
                );
            } else {
                $this->logger->warning('Message not found for status update', [
                    'message_id' => $message['message_id']
                ]);
            }

            $this->logger->info('Message processing completed', [
                'message_id' => $message['message_id']
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Error processing message: ' . $e->getMessage(), [
                'message_id' => $message['message_id'] ?? 'unknown',
                'exception' => get_class($e)
            ]);

            // Em caso de erro, tentar atualizar status para FAILED
            try {
                $this->database->updateMessageStatus(
                    $message['message_id'],
                    'FAILED'
                );
            } catch (\Exception $updateException) {
                $this->logger->error('Failed to update message to FAILED status: ' . $updateException->getMessage());
            }

            throw $e;
        }
    }
}
