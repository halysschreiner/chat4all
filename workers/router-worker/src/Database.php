<?php

namespace Chat4All\Worker;

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

        return $stmt->rowCount() > 0;
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
     * Obter metadados de uma conversa
     * 
     * @param string $conversationId ID da conversa
     * @return array|null Metadados ou null se não encontrado
     */
    public function getConversationMetadata(string $conversationId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT conversation_id, type, name, created_by 
            FROM conversations 
            WHERE conversation_id = :conversation_id
        ');

        $stmt->execute(['conversation_id' => $conversationId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }

    /**
     * Obter informações de um arquivo
     * 
     * @param string $fileId ID do arquivo
     * @return array|null Dados do arquivo ou null
     */
    public function getFileInfo(string $fileId): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT file_id, user_id, filename, original_filename, file_size, 
                   content_type, storage_path, status
            FROM files 
            WHERE file_id = :file_id
        ');

        $stmt->execute(['file_id' => $fileId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        return $result ?: null;
    }
}
