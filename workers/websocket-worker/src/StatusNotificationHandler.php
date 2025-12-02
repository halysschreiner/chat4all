<?php
/**
 * ================================================
 * StatusNotificationHandler - Chat4All WebSocket
 * ================================================
 * 
 * Handler para conexões WebSocket que gerencia:
 * - Autenticação via JWT
 * - Registro de conexões por user_id
 * - Envio de notificações de status em tempo real
 * 
 * @package Chat4All\WebSocket
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\WebSocket;

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Psr\Log\LoggerInterface;

class StatusNotificationHandler implements MessageComponentInterface
{
    /**
     * Conexões ativas indexadas por resourceId
     * @var \SplObjectStorage
     */
    protected \SplObjectStorage $connections;

    /**
     * Mapeamento user_id => [connectionIds]
     * @var array<string, array<int>>
     */
    protected array $userConnections = [];

    /**
     * Mapeamento connectionId => user_id
     * @var array<int, string>
     */
    protected array $connectionUsers = [];

    /**
     * Logger para debug e monitoramento
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    /**
     * Configurações do servidor
     * @var array
     */
    protected array $config;

    /**
     * Construtor do handler
     * 
     * @param LoggerInterface $logger Logger para debug
     * @param array $config Configurações do servidor
     */
    public function __construct(LoggerInterface $logger, array $config)
    {
        $this->connections = new \SplObjectStorage();
        $this->logger = $logger;
        $this->config = $config;

        $this->logger->info('StatusNotificationHandler inicializado');
    }

    /**
     * Chamado quando uma nova conexão WebSocket é estabelecida
     * 
     * A conexão ainda não está autenticada neste ponto.
     * O cliente deve enviar uma mensagem de autenticação com JWT.
     * 
     * @param ConnectionInterface $conn Nova conexão
     */
    public function onOpen(ConnectionInterface $conn): void
    {
        $this->connections->attach($conn);

        $this->logger->info('Nova conexão WebSocket', [
            'resourceId' => $conn->resourceId,
            'totalConnections' => $this->connections->count(),
        ]);

        // Enviar mensagem solicitando autenticação
        $conn->send(json_encode([
            'type' => 'auth_required',
            'message' => 'Envie seu token JWT para autenticação',
        ]));
    }

    /**
     * Chamado quando uma mensagem é recebida de um cliente
     * 
     * Tipos de mensagens suportados:
     * - auth: Autenticação com JWT
     * - ping: Keep-alive
     * - subscribe: Inscrever para atualizações de uma conversa específica
     * 
     * @param ConnectionInterface $from Conexão que enviou a mensagem
     * @param string $msg Conteúdo da mensagem (JSON)
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        $this->logger->debug('Mensagem recebida', [
            'resourceId' => $from->resourceId,
            'message' => substr($msg, 0, 100),
        ]);

        try {
            $data = json_decode($msg, true);

            if (!isset($data['type'])) {
                throw new \InvalidArgumentException('Tipo de mensagem não especificado');
            }

            switch ($data['type']) {
                case 'auth':
                    $this->handleAuth($from, $data);
                    break;

                case 'ping':
                    $from->send(json_encode(['type' => 'pong', 'timestamp' => time()]));
                    break;

                case 'subscribe':
                    $this->handleSubscribe($from, $data);
                    break;

                default:
                    $from->send(json_encode([
                        'type' => 'error',
                        'message' => 'Tipo de mensagem desconhecido: ' . $data['type'],
                    ]));
            }
        } catch (\Exception $e) {
            $this->logger->error('Erro ao processar mensagem', [
                'resourceId' => $from->resourceId,
                'error' => $e->getMessage(),
            ]);

            $from->send(json_encode([
                'type' => 'error',
                'message' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Chamado quando uma conexão é fechada
     * 
     * @param ConnectionInterface $conn Conexão fechada
     */
    public function onClose(ConnectionInterface $conn): void
    {
        $resourceId = $conn->resourceId;

        // Remover mapeamento de usuário
        if (isset($this->connectionUsers[$resourceId])) {
            $userId = $this->connectionUsers[$resourceId];
            
            // Remover da lista de conexões do usuário
            if (isset($this->userConnections[$userId])) {
                $key = array_search($resourceId, $this->userConnections[$userId]);
                if ($key !== false) {
                    unset($this->userConnections[$userId][$key]);
                }
                
                // Limpar array vazio
                if (empty($this->userConnections[$userId])) {
                    unset($this->userConnections[$userId]);
                }
            }

            unset($this->connectionUsers[$resourceId]);
        }

        $this->connections->detach($conn);

        $this->logger->info('Conexão WebSocket fechada', [
            'resourceId' => $resourceId,
            'totalConnections' => $this->connections->count(),
        ]);
    }

    /**
     * Chamado quando ocorre um erro na conexão
     * 
     * @param ConnectionInterface $conn Conexão com erro
     * @param \Exception $e Exceção ocorrida
     */
    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        $this->logger->error('Erro na conexão WebSocket', [
            'resourceId' => $conn->resourceId,
            'error' => $e->getMessage(),
        ]);

        $conn->close();
    }

    /**
     * Processa autenticação via JWT
     * 
     * @param ConnectionInterface $conn Conexão a autenticar
     * @param array $data Dados com token JWT
     */
    protected function handleAuth(ConnectionInterface $conn, array $data): void
    {
        if (!isset($data['token'])) {
            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Token não fornecido',
            ]));
            return;
        }

        try {
            // Decodificar JWT
            $decoded = JWT::decode(
                $data['token'],
                new Key($this->config['jwt_secret'], 'HS256')
            );

            $userId = $decoded->sub ?? $decoded->user_id ?? null;

            if (!$userId) {
                throw new \Exception('User ID não encontrado no token');
            }

            // Registrar conexão para o usuário
            $resourceId = $conn->resourceId;
            $this->connectionUsers[$resourceId] = $userId;

            if (!isset($this->userConnections[$userId])) {
                $this->userConnections[$userId] = [];
            }
            $this->userConnections[$userId][] = $resourceId;

            $this->logger->info('Usuário autenticado via WebSocket', [
                'userId' => $userId,
                'resourceId' => $resourceId,
            ]);

            $conn->send(json_encode([
                'type' => 'auth_success',
                'user_id' => $userId,
                'message' => 'Autenticação bem-sucedida',
            ]));

        } catch (\Exception $e) {
            $this->logger->warning('Falha na autenticação WebSocket', [
                'resourceId' => $conn->resourceId,
                'error' => $e->getMessage(),
            ]);

            $conn->send(json_encode([
                'type' => 'auth_error',
                'message' => 'Token inválido: ' . $e->getMessage(),
            ]));
        }
    }

    /**
     * Processa inscrição para atualizações de conversa
     * 
     * @param ConnectionInterface $conn Conexão
     * @param array $data Dados com conversation_id
     */
    protected function handleSubscribe(ConnectionInterface $conn, array $data): void
    {
        // Verificar se está autenticado
        if (!isset($this->connectionUsers[$conn->resourceId])) {
            $conn->send(json_encode([
                'type' => 'error',
                'message' => 'Autenticação necessária',
            ]));
            return;
        }

        // Por enquanto, apenas confirmar inscrição
        // Em implementação futura, pode filtrar eventos por conversa
        $conn->send(json_encode([
            'type' => 'subscribed',
            'conversation_id' => $data['conversation_id'] ?? 'all',
        ]));
    }

    /**
     * Envia notificação de status para um usuário específico
     * 
     * Este método é chamado pelo RedisSubscriber quando
     * um evento de atualização de status é recebido.
     * 
     * @param string $userId ID do usuário destinatário
     * @param array $statusData Dados do status a enviar
     */
    public function notifyUser(string $userId, array $statusData): void
    {
        if (!isset($this->userConnections[$userId])) {
            $this->logger->debug('Usuário não conectado, notificação ignorada', [
                'userId' => $userId,
            ]);
            return;
        }

        $message = json_encode([
            'type' => 'status_update',
            'data' => $statusData,
            'timestamp' => time(),
        ]);

        $notified = 0;
        foreach ($this->userConnections[$userId] as $resourceId) {
            foreach ($this->connections as $conn) {
                if ($conn->resourceId === $resourceId) {
                    $conn->send($message);
                    $notified++;
                    break;
                }
            }
        }

        $this->logger->info('Notificação de status enviada', [
            'userId' => $userId,
            'connectionsNotified' => $notified,
            'messageId' => $statusData['message_id'] ?? 'unknown',
            'status' => $statusData['status'] ?? 'unknown',
        ]);
    }

    /**
     * Broadcast para todos os usuários conectados
     * 
     * @param array $data Dados a enviar
     */
    public function broadcast(array $data): void
    {
        $message = json_encode([
            'type' => 'broadcast',
            'data' => $data,
            'timestamp' => time(),
        ]);

        foreach ($this->connections as $conn) {
            $conn->send($message);
        }

        $this->logger->info('Broadcast enviado', [
            'totalConnections' => $this->connections->count(),
        ]);
    }

    /**
     * Retorna estatísticas do servidor WebSocket
     * 
     * @return array Estatísticas
     */
    public function getStats(): array
    {
        return [
            'total_connections' => $this->connections->count(),
            'authenticated_users' => count($this->userConnections),
            'connections_per_user' => array_map('count', $this->userConnections),
        ];
    }
}
