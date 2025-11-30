<?php
/**
 * ================================================
 * RedisSubscriber - Chat4All WebSocket
 * ================================================
 * 
 * Subscriber Redis para receber eventos de atualização
 * de status de mensagens via Pub/Sub.
 * 
 * Canais monitorados:
 * - status-updates: Atualizações de status de mensagens
 * - message-events: Eventos gerais de mensagens
 * 
 * @package Chat4All\WebSocket
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\WebSocket;

use Predis\Client as RedisClient;
use Predis\PubSub\Consumer as PubSubConsumer;
use Psr\Log\LoggerInterface;

class RedisSubscriber
{
    /**
     * Cliente Redis para Pub/Sub
     * @var RedisClient
     */
    protected RedisClient $redis;

    /**
     * Handler WebSocket para notificações
     * @var StatusNotificationHandler
     */
    protected StatusNotificationHandler $wsHandler;

    /**
     * Logger para debug
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Consumer Pub/Sub
     * @var PubSubConsumer|null
     */
    protected ?PubSubConsumer $pubsub = null;

    /**
     * Fila de mensagens pendentes
     * @var array
     */
    protected array $pendingMessages = [];

    /**
     * Construtor do subscriber
     * 
     * @param string $host Host do Redis
     * @param int $port Porta do Redis
     * @param StatusNotificationHandler $wsHandler Handler WebSocket
     * @param LoggerInterface $logger Logger
     */
    public function __construct(
        string $host,
        int $port,
        StatusNotificationHandler $wsHandler,
        LoggerInterface $logger
    ) {
        $this->wsHandler = $wsHandler;
        $this->logger = $logger;

        try {
            $this->redis = new RedisClient([
                'scheme' => 'tcp',
                'host' => $host,
                'port' => $port,
                'read_write_timeout' => 0,
            ]);

            $this->logger->info('Redis subscriber conectado', [
                'host' => $host,
                'port' => $port,
            ]);

            // Iniciar subscription nos canais
            $this->subscribe();

        } catch (\Exception $e) {
            $this->logger->error('Falha ao conectar ao Redis', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Inscreve nos canais de eventos
     */
    protected function subscribe(): void
    {
        try {
            $this->pubsub = $this->redis->pubSubLoop();

            // Inscrever nos canais de status
            $this->pubsub->subscribe('status-updates', 'message-events');

            $this->logger->info('Inscrito nos canais Redis', [
                'channels' => ['status-updates', 'message-events'],
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Falha ao inscrever em canais', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Processa mensagens pendentes do Redis
     * 
     * Este método deve ser chamado periodicamente pelo event loop
     * para processar mensagens do Pub/Sub de forma não-bloqueante.
     */
    public function processMessages(): void
    {
        if (!$this->pubsub) {
            return;
        }

        try {
            // Tentar ler mensagem (não-bloqueante se possível)
            $message = $this->pubsub->current();
            
            if ($message) {
                $this->handleMessage($message);
                $this->pubsub->next();
            }

        } catch (\Exception $e) {
            $this->logger->error('Erro ao processar mensagem Redis', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Processa uma mensagem recebida do Redis
     * 
     * @param object $message Mensagem do Pub/Sub
     */
    protected function handleMessage(object $message): void
    {
        if ($message->kind !== 'message') {
            return;
        }

        $this->logger->debug('Mensagem Redis recebida', [
            'channel' => $message->channel,
            'payload' => substr($message->payload, 0, 200),
        ]);

        try {
            $data = json_decode($message->payload, true);

            if (!$data) {
                $this->logger->warning('Payload inválido recebido', [
                    'channel' => $message->channel,
                ]);
                return;
            }

            switch ($message->channel) {
                case 'status-updates':
                    $this->handleStatusUpdate($data);
                    break;

                case 'message-events':
                    $this->handleMessageEvent($data);
                    break;

                default:
                    $this->logger->debug('Canal desconhecido', [
                        'channel' => $message->channel,
                    ]);
            }

        } catch (\Exception $e) {
            $this->logger->error('Erro ao processar evento', [
                'channel' => $message->channel,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Processa atualização de status de mensagem
     * 
     * Formato esperado:
     * {
     *   "message_id": "uuid",
     *   "user_id": "uuid",
     *   "status": "sent|delivered|read|failed",
     *   "platform": "whatsapp|instagram",
     *   "timestamp": 1234567890
     * }
     * 
     * @param array $data Dados do status
     */
    protected function handleStatusUpdate(array $data): void
    {
        $requiredFields = ['message_id', 'user_id', 'status'];
        
        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                $this->logger->warning('Campo obrigatório ausente em status-update', [
                    'field' => $field,
                    'data' => $data,
                ]);
                return;
            }
        }

        $this->logger->info('Processando atualização de status', [
            'messageId' => $data['message_id'],
            'userId' => $data['user_id'],
            'status' => $data['status'],
        ]);

        // Notificar usuário via WebSocket
        $this->wsHandler->notifyUser($data['user_id'], [
            'message_id' => $data['message_id'],
            'status' => $data['status'],
            'platform' => $data['platform'] ?? null,
            'timestamp' => $data['timestamp'] ?? time(),
            'error_message' => $data['error_message'] ?? null,
        ]);
    }

    /**
     * Processa evento geral de mensagem
     * 
     * @param array $data Dados do evento
     */
    protected function handleMessageEvent(array $data): void
    {
        $eventType = $data['event_type'] ?? 'unknown';

        $this->logger->debug('Evento de mensagem recebido', [
            'eventType' => $eventType,
        ]);

        // Processar diferentes tipos de eventos
        switch ($eventType) {
            case 'new_message':
                // Notificar sobre nova mensagem recebida
                if (isset($data['recipient_id'])) {
                    $this->wsHandler->notifyUser($data['recipient_id'], [
                        'event' => 'new_message',
                        'conversation_id' => $data['conversation_id'] ?? null,
                        'sender_id' => $data['sender_id'] ?? null,
                        'preview' => $data['preview'] ?? null,
                    ]);
                }
                break;

            case 'file_uploaded':
                // Notificar sobre upload concluído
                if (isset($data['user_id'])) {
                    $this->wsHandler->notifyUser($data['user_id'], [
                        'event' => 'file_uploaded',
                        'file_id' => $data['file_id'] ?? null,
                        'filename' => $data['filename'] ?? null,
                    ]);
                }
                break;

            default:
                $this->logger->debug('Tipo de evento não tratado', [
                    'eventType' => $eventType,
                ]);
        }
    }

    /**
     * Publica evento no Redis (método utilitário)
     * 
     * @param string $channel Canal para publicar
     * @param array $data Dados do evento
     */
    public static function publish(string $channel, array $data): void
    {
        $redis = new RedisClient([
            'scheme' => 'tcp',
            'host' => getenv('REDIS_HOST') ?: 'redis',
            'port' => (int)(getenv('REDIS_PORT') ?: 6379),
        ]);

        $redis->publish($channel, json_encode($data));
    }
}
