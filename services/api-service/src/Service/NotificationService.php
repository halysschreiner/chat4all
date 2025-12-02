<?php
/**
 * ================================================
 * NotificationService - Chat4All API Service
 * ================================================
 * 
 * Serviço para envio de notificações em tempo real
 * via Redis Pub/Sub para o websocket-worker.
 * 
 * Tipos de notificações:
 * - Status updates (sent, delivered, read, failed)
 * - Novas mensagens
 * - Upload de arquivos concluído
 * 
 * @package Chat4All\Api\Service
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\Api\Service;

use Monolog\Logger;

class NotificationService
{
    /**
     * Serviço Redis para Pub/Sub
     * @var RedisService
     */
    private RedisService $redis;

    /**
     * Logger para debug
     * @var Logger
     */
    private Logger $logger;

    /**
     * Canal para atualizações de status
     * @var string
     */
    private const CHANNEL_STATUS = 'status-updates';

    /**
     * Canal para eventos de mensagens
     * @var string
     */
    private const CHANNEL_MESSAGES = 'message-events';

    /**
     * Construtor do serviço
     * 
     * @param RedisService $redis Serviço Redis
     * @param Logger $logger Logger para debug
     */
    public function __construct(RedisService $redis, Logger $logger)
    {
        $this->redis = $redis;
        $this->logger = $logger;

        $this->logger->info('NotificationService inicializado');
    }

    /**
     * Notifica atualização de status de mensagem
     * 
     * Envia evento para o websocket-worker que irá
     * notificar o usuário via WebSocket.
     * 
     * @param string $messageId ID da mensagem
     * @param string $userId ID do usuário a notificar
     * @param string $status Novo status (sent, delivered, read, failed)
     * @param string|null $platform Plataforma (whatsapp, instagram)
     * @param string|null $errorMessage Mensagem de erro se failed
     * @return bool Sucesso
     */
    public function notifyStatusUpdate(
        string $messageId,
        string $userId,
        string $status,
        ?string $platform = null,
        ?string $errorMessage = null
    ): bool {
        $data = [
            'message_id' => $messageId,
            'user_id' => $userId,
            'status' => $status,
            'platform' => $platform,
            'timestamp' => time(),
            'error_message' => $errorMessage,
        ];

        $this->logger->info('Enviando notificação de status', [
            'messageId' => $messageId,
            'userId' => $userId,
            'status' => $status,
            'platform' => $platform,
        ]);

        return $this->redis->publish(self::CHANNEL_STATUS, $data);
    }

    /**
     * Notifica sobre nova mensagem recebida
     * 
     * @param string $recipientId ID do destinatário
     * @param string $senderId ID do remetente
     * @param string $conversationId ID da conversa
     * @param string|null $preview Preview da mensagem
     * @return bool Sucesso
     */
    public function notifyNewMessage(
        string $recipientId,
        string $senderId,
        string $conversationId,
        ?string $preview = null
    ): bool {
        $data = [
            'event_type' => 'new_message',
            'recipient_id' => $recipientId,
            'sender_id' => $senderId,
            'conversation_id' => $conversationId,
            'preview' => $preview,
            'timestamp' => time(),
        ];

        $this->logger->info('Enviando notificação de nova mensagem', [
            'recipientId' => $recipientId,
            'conversationId' => $conversationId,
        ]);

        return $this->redis->publish(self::CHANNEL_MESSAGES, $data);
    }

    /**
     * Notifica sobre upload de arquivo concluído
     * 
     * @param string $userId ID do usuário
     * @param string $fileId ID do arquivo
     * @param string $filename Nome do arquivo
     * @return bool Sucesso
     */
    public function notifyFileUploaded(
        string $userId,
        string $fileId,
        string $filename
    ): bool {
        $data = [
            'event_type' => 'file_uploaded',
            'user_id' => $userId,
            'file_id' => $fileId,
            'filename' => $filename,
            'timestamp' => time(),
        ];

        $this->logger->info('Enviando notificação de arquivo uploaded', [
            'userId' => $userId,
            'fileId' => $fileId,
        ]);

        return $this->redis->publish(self::CHANNEL_MESSAGES, $data);
    }

    /**
     * Notifica sobre falha no upload de arquivo
     * 
     * @param string $userId ID do usuário
     * @param string $fileId ID do arquivo
     * @param string $errorMessage Mensagem de erro
     * @return bool Sucesso
     */
    public function notifyFileUploadFailed(
        string $userId,
        string $fileId,
        string $errorMessage
    ): bool {
        $data = [
            'event_type' => 'file_upload_failed',
            'user_id' => $userId,
            'file_id' => $fileId,
            'error_message' => $errorMessage,
            'timestamp' => time(),
        ];

        $this->logger->error('Enviando notificação de falha no upload', [
            'userId' => $userId,
            'fileId' => $fileId,
            'error' => $errorMessage,
        ]);

        return $this->redis->publish(self::CHANNEL_MESSAGES, $data);
    }

    /**
     * Envia broadcast para todos os usuários conectados
     * 
     * Útil para notificações de sistema, manutenção, etc.
     * 
     * @param string $message Mensagem do broadcast
     * @param string $type Tipo de broadcast (info, warning, error)
     * @return bool Sucesso
     */
    public function broadcast(string $message, string $type = 'info'): bool
    {
        $data = [
            'event_type' => 'broadcast',
            'message' => $message,
            'type' => $type,
            'timestamp' => time(),
        ];

        $this->logger->info('Enviando broadcast', [
            'type' => $type,
            'message' => substr($message, 0, 100),
        ]);

        return $this->redis->publish(self::CHANNEL_MESSAGES, $data);
    }

    /**
     * Publica atualização de status para uma conversa
     * 
     * Usado pelo CallbackController para notificar todos os participantes
     * de uma conversa sobre mudanças de status de mensagem.
     * 
     * @param string $conversationId ID da conversa
     * @param array $notification Dados da notificação
     * @return bool Sucesso
     */
    public function publishStatusUpdate(string $conversationId, array $notification): bool
    {
        // Adiciona o conversation_id como canal específico
        $channel = "conversation:{$conversationId}";
        
        $data = array_merge($notification, [
            'event_type' => 'status_update',
            'conversation_id' => $conversationId,
            'published_at' => time(),
        ]);

        $this->logger->info('Publicando status update para conversa', [
            'conversationId' => $conversationId,
            'messageId' => $notification['message_id'] ?? 'unknown',
            'status' => $notification['status'] ?? 'unknown',
        ]);

        // Publica tanto no canal específico da conversa quanto no canal geral
        $result1 = $this->redis->publish($channel, $data);
        $result2 = $this->redis->publish(self::CHANNEL_STATUS, $data);

        return $result1 || $result2;
    }

    /**
     * Notifica múltiplos usuários sobre atualização de conversa
     * 
     * @param array $userIds Lista de IDs de usuários
     * @param string $conversationId ID da conversa
     * @param string $eventType Tipo de evento
     * @param array $additionalData Dados adicionais
     * @return int Número de notificações enviadas com sucesso
     */
    public function notifyConversationUpdate(
        array $userIds,
        string $conversationId,
        string $eventType,
        array $additionalData = []
    ): int {
        $successCount = 0;

        foreach ($userIds as $userId) {
            $data = array_merge([
                'event_type' => $eventType,
                'user_id' => $userId,
                'conversation_id' => $conversationId,
                'timestamp' => time(),
            ], $additionalData);

            if ($this->redis->publish(self::CHANNEL_MESSAGES, $data)) {
                $successCount++;
            }
        }

        $this->logger->info('Notificações de conversa enviadas', [
            'conversationId' => $conversationId,
            'eventType' => $eventType,
            'totalUsers' => count($userIds),
            'successful' => $successCount,
        ]);

        return $successCount;
    }
}
