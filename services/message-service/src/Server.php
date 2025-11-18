<?php
/**
 * Message Service - Servidor gRPC
 * Responsável por envio e gerenciamento de mensagens
 */

require __DIR__ . '/../vendor/autoload.php';

use Message\MessageServiceInterface;
use Message\SendMessageRequest;
use Message\SendMessageResponse;
use Message\ListMessagesRequest;
use Message\ListMessagesResponse;
use Message\MarkAsReadRequest;
use Message\MarkAsReadResponse;
use Message\UpdateMessageStatusRequest;
use Message\UpdateMessageStatusResponse;
use Message\Message;
use Message\ReadStatus;

class MessageServiceImpl implements MessageServiceInterface
{
    private PDO $db;
    private Redis $redis;

    public function __construct()
    {
        // Conexão PostgreSQL
        $this->db = new PDO(
            sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                getenv('DB_HOST'),
                getenv('DB_PORT'),
                getenv('DB_NAME')
            ),
            getenv('DB_USER'),
            getenv('DB_PASSWORD')
        );
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Conexão Redis
        $this->redis = new Redis();
        $this->redis->connect(getenv('REDIS_HOST'), getenv('REDIS_PORT'));
    }

    /**
     * Enviar mensagem
     */
    public function SendMessage(SendMessageRequest $request): SendMessageResponse
    {
        $response = new SendMessageResponse();

        try {
            $conversationId = $request->getConversationId();
            $fromUserId = $request->getFromUserId();
            $messageType = $request->getMessageType();
            $content = $request->getContent();

            // Verificar se usuário é membro da conversa
            $stmt = $this->db->prepare(
                "SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?"
            );
            $stmt->execute([$conversationId, $fromUserId]);
            
            if (!$stmt->fetch()) {
                $response->setSuccess(false);
                $response->setMessage('Usuário não é membro desta conversa');
                return $response;
            }

            // Inserir mensagem
            $stmt = $this->db->prepare("
                INSERT INTO messages (conversation_id, from_user_id, message_type, content, status)
                VALUES (?, ?, ?, ?, 'sent')
                RETURNING message_id, conversation_id, from_user_id, message_type, content, status, created_at, sequence_number
            ");
            $stmt->execute([$conversationId, $fromUserId, $messageType, $content]);
            $messageData = $stmt->fetch(PDO::FETCH_ASSOC);

            // Buscar username do remetente
            $stmt = $this->db->prepare("SELECT username FROM users WHERE user_id = ?");
            $stmt->execute([$fromUserId]);
            $username = $stmt->fetchColumn();

            // Atualizar timestamp da conversa
            $this->db->prepare("UPDATE conversations SET updated_at = NOW() WHERE conversation_id = ?")
                ->execute([$conversationId]);

            // Marcar como entregue automaticamente (simplificação)
            $this->updateMessageStatus($messageData['message_id'], 'delivered');

            // Criar objeto Message
            $message = $this->buildMessageObject($messageData, $username);

            $response->setSuccess(true);
            $response->setMessage('Mensagem enviada com sucesso');
            $response->setSentMessage($message);

            // Cache da mensagem no Redis
            $this->redis->setex(
                "message:{$messageData['message_id']}",
                3600,
                json_encode($messageData)
            );

        } catch (Exception $e) {
            $response->setSuccess(false);
            $response->setMessage('Erro ao enviar mensagem: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Listar mensagens de uma conversa
     */
    public function ListMessages(ListMessagesRequest $request): ListMessagesResponse
    {
        $response = new ListMessagesResponse();

        try {
            $conversationId = $request->getConversationId();
            $userId = $request->getUserId();
            $limit = $request->getLimit() ?: 50;
            $offset = $request->getOffset() ?: 0;

            // Verificar se usuário é membro
            $stmt = $this->db->prepare(
                "SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?"
            );
            $stmt->execute([$conversationId, $userId]);
            
            if (!$stmt->fetch()) {
                $response->setSuccess(false);
                return $response;
            }

            // Buscar mensagens
            $stmt = $this->db->prepare("
                SELECT 
                    m.message_id,
                    m.conversation_id,
                    m.from_user_id,
                    u.username as from_username,
                    m.message_type,
                    m.content,
                    m.status,
                    m.created_at,
                    m.sequence_number
                FROM messages m
                INNER JOIN users u ON m.from_user_id = u.user_id
                WHERE m.conversation_id = ?
                ORDER BY m.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$conversationId, $limit, $offset]);
            $messagesData = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // Contar total de mensagens
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM messages WHERE conversation_id = ?");
            $stmt->execute([$conversationId]);
            $totalCount = $stmt->fetchColumn();

            foreach ($messagesData as $msgData) {
                // Buscar quem leu a mensagem
                $readStatuses = $this->loadReadStatuses($msgData['message_id']);
                
                $message = $this->buildMessageObject($msgData, $msgData['from_username'], $readStatuses);
                $response->addMessages($message);
            }

            $response->setSuccess(true);
            $response->setTotalCount($totalCount);

        } catch (Exception $e) {
            $response->setSuccess(false);
        }

        return $response;
    }

    /**
     * Marcar mensagem como lida
     */
    public function MarkAsRead(MarkAsReadRequest $request): MarkAsReadResponse
    {
        $response = new MarkAsReadResponse();

        try {
            $messageId = $request->getMessageId();
            $userId = $request->getUserId();

            // Inserir registro de leitura
            $stmt = $this->db->prepare("
                INSERT INTO message_read_status (message_id, user_id, read_at)
                VALUES (?, ?, NOW())
                ON CONFLICT (message_id, user_id) DO NOTHING
            ");
            $stmt->execute([$messageId, $userId]);

            // Atualizar status da mensagem para 'read' se ainda não estiver
            $this->updateMessageStatus($messageId, 'read');

            // Atualizar last_read_at do membro
            $stmt = $this->db->prepare("
                UPDATE conversation_members cm
                SET last_read_at = NOW()
                FROM messages m
                WHERE m.message_id = ?
                AND cm.conversation_id = m.conversation_id
                AND cm.user_id = ?
            ");
            $stmt->execute([$messageId, $userId]);

            $response->setSuccess(true);
            $response->setMessage('Mensagem marcada como lida');

        } catch (Exception $e) {
            $response->setSuccess(false);
            $response->setMessage('Erro ao marcar como lida: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Atualizar status da mensagem
     */
    public function UpdateMessageStatus(UpdateMessageStatusRequest $request): UpdateMessageStatusResponse
    {
        $response = new UpdateMessageStatusResponse();

        try {
            $messageId = $request->getMessageId();
            $status = $request->getStatus();

            // Validar status
            if (!in_array($status, ['sent', 'delivered', 'read'])) {
                $response->setSuccess(false);
                $response->setMessage('Status inválido');
                return $response;
            }

            $this->updateMessageStatus($messageId, $status);

            $response->setSuccess(true);
            $response->setMessage('Status atualizado com sucesso');

        } catch (Exception $e) {
            $response->setSuccess(false);
            $response->setMessage('Erro ao atualizar status: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Helper: Atualizar status da mensagem
     */
    private function updateMessageStatus(string $messageId, string $status): void
    {
        $stmt = $this->db->prepare(
            "UPDATE messages SET status = ?, updated_at = NOW() WHERE message_id = ?"
        );
        $stmt->execute([$status, $messageId]);

        // Atualizar cache
        $cachedData = $this->redis->get("message:{$messageId}");
        if ($cachedData) {
            $data = json_decode($cachedData, true);
            $data['status'] = $status;
            $this->redis->setex("message:{$messageId}", 3600, json_encode($data));
        }
    }

    /**
     * Helper: Carregar status de leitura
     */
    private function loadReadStatuses(string $messageId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                mrs.user_id,
                u.username,
                mrs.read_at
            FROM message_read_status mrs
            INNER JOIN users u ON mrs.user_id = u.user_id
            WHERE mrs.message_id = ?
        ");
        $stmt->execute([$messageId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $statuses = [];
        foreach ($data as $row) {
            $readStatus = new ReadStatus();
            $readStatus->setUserId($row['user_id']);
            $readStatus->setUsername($row['username']);
            $readStatus->setReadAt($row['read_at']);
            $statuses[] = $readStatus;
        }

        return $statuses;
    }

    /**
     * Helper: Construir objeto Message
     */
    private function buildMessageObject(array $data, string $username, array $readStatuses = []): Message
    {
        $message = new Message();
        $message->setMessageId($data['message_id']);
        $message->setConversationId($data['conversation_id']);
        $message->setFromUserId($data['from_user_id']);
        $message->setFromUsername($username);
        $message->setMessageType($data['message_type']);
        $message->setContent($data['content']);
        $message->setStatus($data['status']);
        $message->setCreatedAt($data['created_at']);
        $message->setSequenceNumber($data['sequence_number']);

        foreach ($readStatuses as $readStatus) {
            $message->addReadBy($readStatus);
        }

        return $message;
    }
}

// Iniciar servidor gRPC
$server = new \Grpc\RpcServer();
$server->addHttp2Port('0.0.0.0:' . getenv('GRPC_PORT'));
$server->handle(new MessageServiceImpl());

echo "Message Service rodando na porta " . getenv('GRPC_PORT') . "\n";
$server->run();