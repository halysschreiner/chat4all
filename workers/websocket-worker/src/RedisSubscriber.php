<?php
/**
 * ================================================
 * RedisSubscriber - Chat4All WebSocket
 * ================================================
 * 
 * Subscriber Redis para receber eventos de atualização
 * de status de mensagens via Pub/Sub.
 * 
 * Utiliza Clue\React\Redis (Assíncrono) para não bloquear
 * o Event Loop do ReactPHP.
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

use Clue\React\Redis\Factory;
use Clue\React\Redis\Client;
use React\EventLoop\LoopInterface;
use Psr\Log\LoggerInterface;

class RedisSubscriber
{
    /**
     * Cliente Redis Async
     * @var Client
     */
    protected Client $redis;

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
     * Construtor do subscriber
     * 
     * @param string $host Host do Redis
     * @param int $port Porta do Redis
     * @param StatusNotificationHandler $wsHandler Handler WebSocket
     * @param LoggerInterface $logger Logger
     * @param LoopInterface $loop Event Loop
     */
    public function __construct(
        string $host,
        int $port,
        StatusNotificationHandler $wsHandler,
        LoggerInterface $logger,
        LoopInterface $loop
    ) {
        $this->wsHandler = $wsHandler;
        $this->logger = $logger;

        $factory = new Factory($loop);
        $url = "redis://$host:$port";

        $factory->createClient($url)->then(
            function (Client $client) {
                $this->redis = $client;
                $this->logger->info('Redis subscriber conectado (Async)');

                $this->redis->on('message', function ($channel, $payload) {
                    $this->handleMessage($channel, $payload);
                });

                $this->subscribe();
            },
            function (\Exception $e) {
                $this->logger->error('Falha ao conectar ao Redis (Async)', [
                    'error' => $e->getMessage()
                ]);
            }
        );
    }

    /**
     * Inscreve nos canais de eventos
     */
    public function subscribe(): void
    {
        if (!isset($this->redis)) {
            return;
        }

        $channels = ['status-updates', 'message-events'];

        foreach ($channels as $channel) {
            $this->redis->subscribe($channel)->then(
                function () use ($channel) {
                    $this->logger->info("Inscrito no canal: $channel");
                },
                function (\Exception $e) use ($channel) {
                    $this->logger->error("Erro ao inscrever no canal $channel", [
                        'error' => $e->getMessage()
                    ]);
                }
            );
        }
    }

    /**
     * Processa uma mensagem recebida do Redis
     * 
     * @param string $channel Canal de origem
     * @param string $payload Conteúdo da mensagem
     */
    protected function handleMessage(string $channel, string $payload): void
    {
        $this->logger->debug('Mensagem Redis recebida', [
            'channel' => $channel,
            'payload' => $payload
        ]);

        $data = json_decode($payload, true);

        if (!$data) {
            $this->logger->warning('Mensagem Redis inválida (JSON parse error)', [
                'payload' => $payload
            ]);
            return;
        }

        if ($channel === 'status-updates') {
            $this->handleStatusUpdate($data);
        } elseif ($channel === 'message-events') {
            $this->handleMessageEvent($data);
        }
    }

    /**
     * Processa atualização de status (lido/entregue)
     */
    protected function handleStatusUpdate(array $data): void
    {
        if (!isset($data['event_type']) || $data['event_type'] !== 'status_update') {
            return;
        }

        if (empty($data['sender_id'])) {
            $this->logger->warning('Status update ignore: sender_id missing', ['data' => $data]);
            return;
        }

        // Envia os dados diretamente (o StatusNotificationHandler já empacota)
        $this->wsHandler->notifyUser($data['sender_id'], $data);
    }

    /**
     * Processa evento de nova mensagem
     */
    protected function handleMessageEvent(array $data): void
    {
        if (isset($data['event_type']) && $data['event_type'] === 'new_message') {

            // Validar IDs
            if (empty($data['recipient_id'])) {
                $this->logger->warning('Message event ignored: recipient_id missing', ['data' => $data]);
                return;
            }

            // Payload para notification
            $notificationPayload = [
                'event' => 'new_message',
                'message' => $data
            ];

            // Notificar destinatário
            $this->wsHandler->notifyUser($data['recipient_id'], $notificationPayload);

            // Notificar remetente (se existir)
            if (!empty($data['sender_id'])) {
                $this->wsHandler->notifyUser($data['sender_id'], $notificationPayload);
            }
        }
    }
}
