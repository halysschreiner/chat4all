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
     * Buscar usuário por username
     */
    public function getUserByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT user_id, username, email, status
            FROM users
            WHERE username = :username AND status = \'active\'
        ');
        $stmt->execute(['username' => $username]);
        
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
                message_type, content, file_id, status, created_at
            ) VALUES (
                :message_id, :conversation_id, :from_user_id,
                :message_type, :content, :file_id, :status, NOW()
            )
            RETURNING message_id
        ');

        $stmt->execute([
            'message_id' => $data['message_id'],
            'conversation_id' => $data['conversation_id'],
            'from_user_id' => $data['from_user_id'],
            'message_type' => $data['message_type'] ?? 'text',
            'content' => $data['content'],
            'file_id' => $data['file_id'] ?? null,
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
                m.file_id,
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
     * Marcar mensagens de uma conversa como lidas pelo usuário
     */
    public function markMessagesAsRead(string $conversationId, string $userId): int
    {
        $sql = '
            UPDATE messages 
            SET status = :status, read_at = NOW(), updated_at = NOW()
            WHERE conversation_id = :conversation_id 
            AND from_user_id != :user_id
            AND status != :read_status
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'status' => 'READ',
            'read_status' => 'READ'
        ]);

        $count = $stmt->rowCount();
        
        if ($count > 0) {
            $this->logger->info("Marked $count messages as READ in conversation $conversationId by user $userId");
        }

        return $count;
    }

    /**
     * Marcar mensagens SENT como DELIVERED quando o destinatário as buscar
     * Atualiza apenas mensagens que:
     * - Estão em status SENT
     * - Não foram enviadas pelo usuário que está buscando (o destinatário)
     */
    public function markMessagesAsDelivered(string $conversationId, string $recipientUserId): int
    {
        $sql = '
            UPDATE messages 
            SET status = :status, delivered_at = NOW(), updated_at = NOW()
            WHERE conversation_id = :conversation_id 
            AND from_user_id != :recipient_user_id
            AND status = :sent_status
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'conversation_id' => $conversationId,
            'recipient_user_id' => $recipientUserId,
            'status' => 'DELIVERED',
            'sent_status' => 'SENT'
        ]);

        $count = $stmt->rowCount();
        
        if ($count > 0) {
            $this->logger->info("Marked $count messages as DELIVERED in conversation $conversationId for recipient $recipientUserId");
        }

        return $count;
    }

    /**
     * Buscar mensagens não lidas de uma conversa para um usuário
     */
    public function getUnreadMessages(string $conversationId, string $userId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT 
                m.message_id,
                m.conversation_id,
                m.from_user_id,
                u.username as sender_username,
                m.content,
                m.status,
                m.file_id,
                m.created_at,
                m.delivered_at,
                m.read_at
            FROM messages m
            INNER JOIN users u ON m.from_user_id = u.user_id
            WHERE m.conversation_id = :conversation_id
            AND m.from_user_id != :user_id
            AND m.status != :read_status
            ORDER BY m.created_at ASC
        ');

        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'read_status' => 'READ'
        ]);

        return $stmt->fetchAll();
    }

    /**
     * Contar mensagens não lidas de uma conversa para um usuário
     */
    public function countUnreadMessages(string $conversationId, string $userId): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) as count
            FROM messages
            WHERE conversation_id = :conversation_id
            AND from_user_id != :user_id
            AND status != :read_status
        ');

        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'read_status' => 'READ'
        ]);

        $result = $stmt->fetch();
        return (int)$result['count'];
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
     * Buscar mensagem por ID
     */
    public function getMessageById(string $messageId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT 
                message_id as id, conversation_id, from_user_id,
                content, message_type, status, created_at
            FROM messages
            WHERE message_id = :message_id
        ');
        $stmt->execute(['message_id' => $messageId]);

        $message = $stmt->fetch();
        return $message ?: null;
    }

    /**
     * Buscar callbacks de uma mensagem
     */
    public function getCallbacksByMessageId(string $messageId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT 
                id, message_id, status, connector,
                received_at, connector_timestamp, metadata
            FROM delivery_callbacks
            WHERE message_id = :message_id
            ORDER BY received_at ASC
        ');
        $stmt->execute(['message_id' => $messageId]);

        return $stmt->fetchAll();
    }

    /**
     * Inserir callback de entrega
     */
    public function insertDeliveryCallback(array $data): bool
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO delivery_callbacks (
                id, message_id, status, connector,
                received_at, connector_timestamp, metadata
            ) VALUES (
                :id, :message_id, :status, :connector,
                :received_at, :connector_timestamp, :metadata
            )
        ');

        try {
            $stmt->execute([
                'id' => $data['id'],
                'message_id' => $data['message_id'],
                'status' => $data['status'],
                'connector' => $data['connector'],
                'received_at' => $data['received_at'],
                'connector_timestamp' => $data['connector_timestamp'] ?? null,
                'metadata' => $data['metadata'] ?? '{}'
            ]);

            $this->logger->debug("Callback inserido para mensagem {$data['message_id']}", [
                'status' => $data['status'],
                'connector' => $data['connector']
            ]);

            return true;
        } catch (PDOException $e) {
            $this->logger->error('Erro ao inserir callback: ' . $e->getMessage());
            return false;
        }
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

    /**
     * Criar nova conversa
     */
    public function createConversation(string $type, ?string $name, string $createdBy): string
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO conversations (type, name, created_by)
            VALUES (:type, :name, :created_by)
            RETURNING conversation_id
        ');
        
        $stmt->execute([
            'type' => $type,
            'name' => $name,
            'created_by' => $createdBy
        ]);
        
        $result = $stmt->fetch();
        return $result['conversation_id'];
    }

    /**
     * Adicionar membro à conversa
     */
    public function addConversationMember(string $conversationId, string $userId, string $role = 'member'): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO conversation_members (conversation_id, user_id, role)
            VALUES (:conversation_id, :user_id, :role)
            ON CONFLICT (conversation_id, user_id) DO NOTHING
        ');
        
        $stmt->execute([
            'conversation_id' => $conversationId,
            'user_id' => $userId,
            'role' => $role
        ]);
    }

    /**
     * Verificar se já existe conversa privada entre dois usuários
     */
    public function checkPrivateConversationExists(string $user1Id, string $user2Id): ?string
    {
        $stmt = $this->pdo->prepare('
            SELECT c.conversation_id
            FROM conversations c
            JOIN conversation_members cm1 ON c.conversation_id = cm1.conversation_id
            JOIN conversation_members cm2 ON c.conversation_id = cm2.conversation_id
            WHERE c.type = \'private\'
            AND cm1.user_id = :user1_id
            AND cm2.user_id = :user2_id
            LIMIT 1
        ');
        
        $stmt->execute([
            'user1_id' => $user1Id,
            'user2_id' => $user2Id
        ]);
        
        $result = $stmt->fetch();
        return $result ? $result['conversation_id'] : null;
    }

    /**
     * Obter detalhes da conversa
     */
    public function getConversationById(string $conversationId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM conversations WHERE conversation_id = :conversation_id
        ');
        $stmt->execute(['conversation_id' => $conversationId]);
        $conversation = $stmt->fetch();
        
        if (!$conversation) return null;
        
        // Buscar membros
        $stmtMembers = $this->pdo->prepare('
            SELECT u.user_id, u.username, cm.role, cm.joined_at
            FROM conversation_members cm
            JOIN users u ON cm.user_id = u.user_id
            WHERE cm.conversation_id = :conversation_id
        ');
        $stmtMembers->execute(['conversation_id' => $conversationId]);
        $conversation['members'] = $stmtMembers->fetchAll();
        
        return $conversation;
    }

    /**
     * Inserir metadados do arquivo no banco
     * Registra: file_id, checksum, tamanho, uploader, conversation_id
     */
    public function insertFile(array $data): string
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO files (
                file_id, upload_id, conversation_id, user_id, username,
                filename, original_filename, file_size, content_type,
                storage_path, checksum, status, total_parts, uploaded_parts
            ) VALUES (
                :file_id, :upload_id, :conversation_id, :user_id, :username,
                :filename, :original_filename, :file_size, :content_type,
                :storage_path, :checksum, :status, :total_parts, :uploaded_parts
            )
            RETURNING file_id
        ');

        $stmt->execute([
            'file_id' => $data['file_id'],
            'upload_id' => $data['upload_id'],
            'conversation_id' => $data['conversation_id'],
            'user_id' => $data['user_id'],
            'username' => $data['username'],
            'filename' => $data['filename'],
            'original_filename' => $data['original_filename'],
            'file_size' => $data['file_size'],
            'content_type' => $data['content_type'],
            'storage_path' => $data['storage_path'],
            'checksum' => $data['checksum'] ?? null,
            'status' => $data['status'] ?? 'uploading',
            'total_parts' => $data['total_parts'],
            'uploaded_parts' => $data['uploaded_parts'] ?? 0
        ]);

        $result = $stmt->fetch();
        
        $this->logger->info('File metadata inserted', [
            'file_id' => $data['file_id'],
            'size' => $data['file_size'],
            'uploader' => $data['user_id']
        ]);

        return $result['file_id'];
    }

    /**
     * Atualizar minio_upload_id do arquivo
     */
    public function updateFileMinioUploadId(string $fileId, string $minioUploadId): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE files
            SET minio_upload_id = :minio_upload_id, updated_at = NOW()
            WHERE file_id = :file_id
        ');

        $stmt->execute([
            'file_id' => $fileId,
            'minio_upload_id' => $minioUploadId
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Buscar arquivo por ID
     */
    public function getFileById(string $fileId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT 
                file_id, upload_id, conversation_id, user_id, username,
                filename, original_filename, file_size, content_type,
                storage_path, checksum, status, minio_upload_id,
                total_parts, uploaded_parts, created_at, updated_at
            FROM files
            WHERE file_id = :file_id
        ');
        
        $stmt->execute(['file_id' => $fileId]);
        $file = $stmt->fetch();
        
        return $file ?: null;
    }

    /**
     * Inserir informação de parte do arquivo
     */
    public function insertFilePart(string $fileId, int $partNumber, string $etag, int $bytesUploaded): void
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO file_parts (file_id, part_number, etag, bytes_uploaded)
            VALUES (:file_id, :part_number, :etag, :bytes_uploaded)
            ON CONFLICT (file_id, part_number) DO UPDATE
            SET etag = EXCLUDED.etag, bytes_uploaded = EXCLUDED.bytes_uploaded
        ');

        $stmt->execute([
            'file_id' => $fileId,
            'part_number' => $partNumber,
            'etag' => $etag,
            'bytes_uploaded' => $bytesUploaded
        ]);
    }

    /**
     * Incrementar contador de partes enviadas
     */
    public function incrementFileUploadedParts(string $fileId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE files
            SET uploaded_parts = uploaded_parts + 1, updated_at = NOW()
            WHERE file_id = :file_id
        ');

        $stmt->execute(['file_id' => $fileId]);
    }

    /**
     * Buscar partes do arquivo
     */
    public function getFileParts(string $fileId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT part_number, etag, bytes_uploaded, uploaded_at
            FROM file_parts
            WHERE file_id = :file_id
            ORDER BY part_number ASC
        ');
        
        $stmt->execute(['file_id' => $fileId]);
        
        return $stmt->fetchAll();
    }

    /**
     * Atualizar status do arquivo
     */
    public function updateFileStatus(string $fileId, string $status): bool
    {
        $stmt = $this->pdo->prepare('
            UPDATE files
            SET status = :status, updated_at = NOW()
            WHERE file_id = :file_id
        ');

        $stmt->execute([
            'file_id' => $fileId,
            'status' => $status
        ]);

        $this->logger->info("File $fileId status updated to $status");

        return $stmt->rowCount() > 0;
    }

    /**
     * Listar arquivos de uma conversa
     */
    public function getFilesByConversation(
        string $conversationId,
        int $limit = 20,
        int $offset = 0,
        ?string $fileType = null
    ): array {
        $sql = '
            SELECT 
                file_id, conversation_id, user_id, username,
                filename, original_filename, file_size, content_type,
                status, created_at, updated_at
            FROM files
            WHERE conversation_id = :conversation_id AND status = \'completed\'
        ';

        if ($fileType) {
            $sql .= ' AND content_type LIKE :file_type';
        }

        $sql .= ' ORDER BY created_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue('conversation_id', $conversationId);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        
        if ($fileType) {
            // Mapear tipos genéricos para MIME types
            $mimeTypeMap = [
                'image' => 'image/%',
                'video' => 'video/%',
                'audio' => 'audio/%',
                'document' => '%pdf%',
            ];
            $mimePattern = $mimeTypeMap[$fileType] ?? $fileType . '%';
            $stmt->bindValue('file_type', $mimePattern);
        }
        
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
