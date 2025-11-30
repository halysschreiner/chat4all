<?php
/**
 * ================================================
 * FileRepository - Chat4All API Service
 * ================================================
 * 
 * Repository para operações de persistência da 
 * entidade File no banco de dados PostgreSQL.
 * 
 * @package Chat4All\Api\Repository
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\Api\Repository;

use Chat4All\Api\Entity\File;
use PDO;
use Monolog\Logger;

class FileRepository
{
    /**
     * Conexão PDO com o banco
     * @var PDO
     */
    private PDO $pdo;

    /**
     * Logger para debug
     * @var Logger
     */
    private Logger $logger;

    /**
     * Construtor do repository
     * 
     * @param PDO $pdo Conexão PDO
     * @param Logger $logger Logger
     */
    public function __construct(PDO $pdo, Logger $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    /**
     * Insere um novo arquivo no banco
     * 
     * @param File $file Entidade File
     * @return File Entidade com ID preenchido
     */
    public function insert(File $file): File
    {
        $sql = "
            INSERT INTO files (
                user_id, original_filename, storage_filename, storage_path,
                bucket_name, file_size, mime_type, checksum, upload_status, error_message
            ) VALUES (
                :user_id, :original_filename, :storage_filename, :storage_path,
                :bucket_name, :file_size, :mime_type, :checksum, :upload_status, :error_message
            )
            RETURNING file_id, created_at, updated_at
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $file->getUserId(),
            ':original_filename' => $file->getOriginalFilename(),
            ':storage_filename' => $file->getStorageFilename(),
            ':storage_path' => $file->getStoragePath(),
            ':bucket_name' => $file->getBucketName(),
            ':file_size' => $file->getFileSize(),
            ':mime_type' => $file->getMimeType(),
            ':checksum' => $file->getChecksum(),
            ':upload_status' => $file->getUploadStatus(),
            ':error_message' => $file->getErrorMessage(),
        ]);

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $file->setFileId($result['file_id']);
        $file->setCreatedAt(new \DateTimeImmutable($result['created_at']));
        $file->setUpdatedAt(new \DateTimeImmutable($result['updated_at']));

        $this->logger->info('Arquivo inserido', [
            'fileId' => $file->getFileId(),
            'filename' => $file->getOriginalFilename(),
        ]);

        return $file;
    }

    /**
     * Atualiza um arquivo existente
     * 
     * @param File $file Entidade File
     * @return bool Sucesso
     */
    public function update(File $file): bool
    {
        $sql = "
            UPDATE files SET
                file_size = :file_size,
                checksum = :checksum,
                upload_status = :upload_status,
                error_message = :error_message,
                updated_at = NOW()
            WHERE file_id = :file_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([
            ':file_id' => $file->getFileId(),
            ':file_size' => $file->getFileSize(),
            ':checksum' => $file->getChecksum(),
            ':upload_status' => $file->getUploadStatus(),
            ':error_message' => $file->getErrorMessage(),
        ]);

        $this->logger->info('Arquivo atualizado', [
            'fileId' => $file->getFileId(),
            'status' => $file->getUploadStatus(),
        ]);

        return $result;
    }

    /**
     * Busca arquivo por ID
     * 
     * @param string $fileId UUID do arquivo
     * @return File|null Entidade ou null
     */
    public function findById(string $fileId): ?File
    {
        $sql = "
            SELECT * FROM files 
            WHERE file_id = :file_id 
            AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':file_id' => $fileId]);
        
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$data) {
            return null;
        }

        return File::fromArray($data);
    }

    /**
     * Busca arquivos por usuário
     * 
     * @param string $userId UUID do usuário
     * @param int $limit Limite de resultados
     * @param int $offset Offset para paginação
     * @return array Lista de Files
     */
    public function findByUserId(string $userId, int $limit = 50, int $offset = 0): array
    {
        $sql = "
            SELECT * FROM files 
            WHERE user_id = :user_id 
            AND deleted_at IS NULL
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':user_id', $userId);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $files = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $files[] = File::fromArray($data);
        }

        return $files;
    }

    /**
     * Busca arquivos por status
     * 
     * @param string $status Status do upload
     * @param int $limit Limite de resultados
     * @return array Lista de Files
     */
    public function findByStatus(string $status, int $limit = 100): array
    {
        $sql = "
            SELECT * FROM files 
            WHERE upload_status = :status 
            AND deleted_at IS NULL
            ORDER BY created_at ASC
            LIMIT :limit
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':status', $status);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $files = [];
        while ($data = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $files[] = File::fromArray($data);
        }

        return $files;
    }

    /**
     * Atualiza status do arquivo
     * 
     * @param string $fileId ID do arquivo
     * @param string $status Novo status
     * @param string|null $errorMessage Mensagem de erro (opcional)
     * @return bool Sucesso
     */
    public function updateStatus(string $fileId, string $status, ?string $errorMessage = null): bool
    {
        $sql = "
            UPDATE files SET
                upload_status = :status,
                error_message = :error_message,
                updated_at = NOW()
            WHERE file_id = :file_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([
            ':file_id' => $fileId,
            ':status' => $status,
            ':error_message' => $errorMessage,
        ]);

        $this->logger->info('Status atualizado', [
            'fileId' => $fileId,
            'newStatus' => $status,
        ]);

        return $result;
    }

    /**
     * Atualiza checksum e tamanho do arquivo
     * 
     * @param string $fileId ID do arquivo
     * @param int $fileSize Tamanho em bytes
     * @param string $checksum Hash SHA-256
     * @return bool Sucesso
     */
    public function updateSizeAndChecksum(string $fileId, int $fileSize, string $checksum): bool
    {
        $sql = "
            UPDATE files SET
                file_size = :file_size,
                checksum = :checksum,
                updated_at = NOW()
            WHERE file_id = :file_id
        ";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':file_id' => $fileId,
            ':file_size' => $fileSize,
            ':checksum' => $checksum,
        ]);
    }

    /**
     * Soft delete de arquivo
     * 
     * @param string $fileId ID do arquivo
     * @return bool Sucesso
     */
    public function delete(string $fileId): bool
    {
        $sql = "
            UPDATE files SET
                deleted_at = NOW(),
                updated_at = NOW()
            WHERE file_id = :file_id
        ";

        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([':file_id' => $fileId]);

        $this->logger->info('Arquivo deletado (soft)', [
            'fileId' => $fileId,
        ]);

        return $result;
    }

    /**
     * Conta total de arquivos de um usuário
     * 
     * @param string $userId ID do usuário
     * @return int Total de arquivos
     */
    public function countByUserId(string $userId): int
    {
        $sql = "
            SELECT COUNT(*) FROM files 
            WHERE user_id = :user_id 
            AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        
        return (int) $stmt->fetchColumn();
    }

    /**
     * Calcula tamanho total de arquivos de um usuário
     * 
     * @param string $userId ID do usuário
     * @return int Tamanho total em bytes
     */
    public function getTotalSizeByUserId(string $userId): int
    {
        $sql = "
            SELECT COALESCE(SUM(file_size), 0) FROM files 
            WHERE user_id = :user_id 
            AND deleted_at IS NULL
            AND upload_status = 'completed'
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':user_id' => $userId]);
        
        return (int) $stmt->fetchColumn();
    }

    /**
     * Busca arquivos pendentes/em upload há mais de X minutos (cleanup)
     * 
     * @param int $minutesOld Idade em minutos
     * @return array Lista de IDs para cleanup
     */
    public function findStaleUploads(int $minutesOld = 60): array
    {
        $sql = "
            SELECT file_id FROM files 
            WHERE upload_status IN ('pending', 'uploading')
            AND created_at < NOW() - INTERVAL ':minutes minutes'
            AND deleted_at IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':minutes' => $minutesOld]);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
