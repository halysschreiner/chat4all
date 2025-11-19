<?php

namespace Chat4All\Api\Database;

use PDO;
use PDOException;
use Monolog\Logger;

/**
 * Classe de conexão e operações com banco de dados PostgreSQL
 */
class Database
{
    private PDO $pdo;
    private Logger $logger;

    /**
     * Construtor - estabelece conexão com PostgreSQL
     */
    public function __construct(
        string $host,
        string $port,
        string $database,
        string $user,
        string $password,
        Logger $logger
    ) {
        $this->logger = $logger;

        try {
            $dsn = "pgsql:host=$host;port=$port;dbname=$database";
            $this->pdo = new PDO($dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            $this->logger->info('Database connection established');
        } catch (PDOException $e) {
            $this->logger->error('Database connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Retorna a conexão PDO
     */
    public function getConnection(): PDO
    {
        return $this->pdo;
    }

    /**
     * Buscar usuário por email
     */
    public function getUserByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT user_id, username, email, password_hash, status
            FROM users
            WHERE email = :email AND status = \'active\'
        ');
        $stmt->execute(['email' => $email]);
        
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Buscar usuário por ID
     */
    public function getUserById(string $userId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT user_id, username, email, status
            FROM users
            WHERE user_id = :user_id AND status = \'active\'
        ');
        $stmt->execute(['user_id' => $userId]);
        
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Buscar usuário por email ou telefone
     */
    public function getUserByEmailOrPhone(string $identifier): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT user_id, username, email, phone, password_hash, status
            FROM users
            WHERE (email = :identifier OR phone = :identifier) AND status = \'active\'
        ');
        $stmt->execute(['identifier' => $identifier]);
        
        $user = $stmt->fetch();
        return $user ?: null;
    }

    /**
     * Criar novo usuário
     */
    public function createUser(string $username, ?string $email, ?string $phone, string $passwordHash): array
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO users (username, email, phone, password_hash)
            VALUES (:username, :email, :phone, :password_hash)
            RETURNING user_id, username, email, phone, created_at, status
        ');
        
        $stmt->execute([
            'username' => $username,
            'email' => $email,
            'phone' => $phone,
            'password_hash' => $passwordHash
        ]);
        
        return $stmt->fetch();
    }

    /**
     * Inserir nova mensagem no banco
     */
    public function insertMessage(array $data): string
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO messages (
                message_id, conversation_id, from_user_id, 
                message_type, content, status, created_at
            ) VALUES (
                :message_id, :conversation_id, :from_user_id,
                :message_type, :content, :status, NOW()
            )
            RETURNING message_id
        ');

        $stmt->execute([
            'message_id' => $data['message_id'],
            'conversation_id' => $data['conversation_id'],
            'from_user_id' => $data['from_user_id'],
            'message_type' => $data['message_type'] ?? 'text',
            'content' => $data['content'],
            'status' => $data['status'] ?? 'SENT'
        ]);

        $result = $stmt->fetch();
        
        // Atualizar última mensagem na conversa
        $this->updateConversationLastMessage(
            $data['conversation_id'],
            $data['message_id'],
            $data['content']
        );

        return $result['message_id'];
    }

    /**
     * Listar mensagens de uma conversa
     */
    public function getMessagesByConversation(
        string $conversationId,
        int $limit = 50,
        int $offset = 0
    ): array {
        $stmt = $this->pdo->prepare('
            SELECT 
                m.message_id,
                m.conversation_id,
                m.from_user_id,
                u.username as from_username,
                m.message_type,
                m.content,
                m.status,
                m.created_at,
                m.delivered_at,
                m.read_at,
                m.reply_to_message_id
            FROM messages m
            JOIN users u ON m.from_user_id = u.user_id
            WHERE m.conversation_id = :conversation_id
            ORDER BY m.created_at DESC
            LIMIT :limit OFFSET :offset
        ');

        $stmt->bindValue('conversation_id', $conversationId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Atualizar status de uma mensagem
     */
    public function updateMessageStatus(
        string $messageId,
        string $status,
        ?string $timestampField = null
    ): bool {
        $sql = 'UPDATE messages SET status = :status, updated_at = NOW()';
        
        if ($timestampField && in_array($timestampField, ['delivered_at', 'read_at'])) {
            $sql .= ", $timestampField = NOW()";
        }
        
        $sql .= ' WHERE message_id = :message_id';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'message_id' => $messageId,
            'status' => $status
        ]);

        $this->logger->info("Message $messageId status updated to $status");

        return $stmt->rowCount() > 0;
    }

    /**
     * Verificar se usuário pertence à conversa
     */
    public function isUserInConversation(string $userId, string $conversationId): bool
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) as count
            FROM conversation_members
            WHERE user_id = :user_id AND conversation_id = :conversation_id
        ');
        
        $stmt->execute([
            'user_id' => $userId,
            'conversation_id' => $conversationId
        ]);

        $result = $stmt->fetch();
        return $result['count'] > 0;
    }

    /**
     * Atualizar última mensagem da conversa (desnormalização)
     */
    private function updateConversationLastMessage(
        string $conversationId,
        string $messageId,
        string $content
    ): void {
        // Pegar snippet (primeiros 100 caracteres)
        $snippet = mb_substr($content, 0, 100);
        if (mb_strlen($content) > 100) {
            $snippet .= '...';
        }

        $stmt = $this->pdo->prepare('
            UPDATE conversations
            SET 
                last_message_id = :message_id,
                last_message_at = NOW(),
                last_message_snippet = :snippet,
                updated_at = NOW()
            WHERE conversation_id = :conversation_id
        ');

        $stmt->execute([
            'conversation_id' => $conversationId,
            'message_id' => $messageId,
            'snippet' => $snippet
        ]);
    }

    /**
     * Listar conversas do usuário
     */
    public function getUserConversations(string $userId, int $limit = 20): array
    {
        $stmt = $this->pdo->prepare('
            SELECT 
                c.conversation_id,
                c.type,
                c.created_at,
                c.updated_at,
                c.last_message_snippet,
                c.last_message_at,
                (
                    SELECT COUNT(*)
                    FROM conversation_members cm
                    WHERE cm.conversation_id = c.conversation_id
                ) as members_count
            FROM conversations c
            JOIN conversation_members cm ON c.conversation_id = cm.conversation_id
            WHERE cm.user_id = :user_id AND c.is_active = true
            ORDER BY c.last_message_at DESC NULLS LAST, c.created_at DESC
            LIMIT :limit
        ');

        $stmt->bindValue('user_id', $userId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Inserir log de auditoria
     */
    public function insertAuditLog(
        string $eventType,
        string $entityType,
        string $entityId,
        ?string $userId = null,
        array $details = []
    ): void {
        $stmt = $this->pdo->prepare('
            INSERT INTO audit_logs (event_type, entity_type, entity_id, user_id, details)
            VALUES (:event_type, :entity_type, :entity_id, :user_id, :details)
        ');

        $stmt->execute([
            'event_type' => $eventType,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'user_id' => $userId,
            'details' => json_encode($details)
        ]);
    }
}
