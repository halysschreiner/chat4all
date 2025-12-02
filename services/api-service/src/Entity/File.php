<?php
/**
 * ================================================
 * File Entity - Chat4All API Service
 * ================================================
 * 
 * Entidade que representa um arquivo armazenado 
 * no sistema. Mapeia para a tabela 'files' do banco.
 * 
 * @package Chat4All\Api\Entity
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\Api\Entity;

class File
{
    /**
     * ID único do arquivo (UUID)
     * @var string|null
     */
    private ?string $fileId = null;

    /**
     * ID do usuário que fez upload
     * @var string
     */
    private string $userId;

    /**
     * Nome original do arquivo
     * @var string
     */
    private string $originalFilename;

    /**
     * Nome do arquivo no storage
     * @var string
     */
    private string $storageFilename;

    /**
     * Caminho no bucket S3/MinIO
     * @var string
     */
    private string $storagePath;

    /**
     * Nome do bucket
     * @var string
     */
    private string $bucketName = 'chat4all-files';

    /**
     * Tamanho do arquivo em bytes
     * @var int
     */
    private int $fileSize = 0;

    /**
     * MIME type do arquivo
     * @var string
     */
    private string $mimeType = 'application/octet-stream';

    /**
     * Checksum SHA-256
     * @var string|null
     */
    private ?string $checksum = null;

    /**
     * Status do upload: pending, uploading, completed, failed
     * @var string
     */
    private string $uploadStatus = 'pending';

    /**
     * Mensagem de erro (se falhou)
     * @var string|null
     */
    private ?string $errorMessage = null;

    /**
     * Data de criação
     * @var \DateTimeImmutable|null
     */
    private ?\DateTimeImmutable $createdAt = null;

    /**
     * Data de atualização
     * @var \DateTimeImmutable|null
     */
    private ?\DateTimeImmutable $updatedAt = null;

    /**
     * Data de exclusão (soft delete)
     * @var \DateTimeImmutable|null
     */
    private ?\DateTimeImmutable $deletedAt = null;

    // ================================================
    // Constantes de status
    // ================================================
    
    public const STATUS_PENDING = 'pending';
    public const STATUS_UPLOADING = 'uploading';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // ================================================
    // Getters e Setters
    // ================================================

    public function getFileId(): ?string
    {
        return $this->fileId;
    }

    public function setFileId(?string $fileId): self
    {
        $this->fileId = $fileId;
        return $this;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function setUserId(string $userId): self
    {
        $this->userId = $userId;
        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;
        return $this;
    }

    public function getStorageFilename(): string
    {
        return $this->storageFilename;
    }

    public function setStorageFilename(string $storageFilename): self
    {
        $this->storageFilename = $storageFilename;
        return $this;
    }

    public function getStoragePath(): string
    {
        return $this->storagePath;
    }

    public function setStoragePath(string $storagePath): self
    {
        $this->storagePath = $storagePath;
        return $this;
    }

    public function getBucketName(): string
    {
        return $this->bucketName;
    }

    public function setBucketName(string $bucketName): self
    {
        $this->bucketName = $bucketName;
        return $this;
    }

    public function getFileSize(): int
    {
        return $this->fileSize;
    }

    public function setFileSize(int $fileSize): self
    {
        $this->fileSize = $fileSize;
        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;
        return $this;
    }

    public function getChecksum(): ?string
    {
        return $this->checksum;
    }

    public function setChecksum(?string $checksum): self
    {
        $this->checksum = $checksum;
        return $this;
    }

    public function getUploadStatus(): string
    {
        return $this->uploadStatus;
    }

    public function setUploadStatus(string $uploadStatus): self
    {
        $this->uploadStatus = $uploadStatus;
        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function getDeletedAt(): ?\DateTimeImmutable
    {
        return $this->deletedAt;
    }

    public function setDeletedAt(?\DateTimeImmutable $deletedAt): self
    {
        $this->deletedAt = $deletedAt;
        return $this;
    }

    // ================================================
    // Métodos de conveniência
    // ================================================

    /**
     * Verifica se o arquivo está disponível para download
     * 
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->uploadStatus === self::STATUS_COMPLETED 
            && $this->deletedAt === null;
    }

    /**
     * Verifica se o upload está em andamento
     * 
     * @return bool
     */
    public function isUploading(): bool
    {
        return in_array($this->uploadStatus, [
            self::STATUS_PENDING,
            self::STATUS_UPLOADING,
        ]);
    }

    /**
     * Converte entidade para array (para JSON)
     * 
     * @return array
     */
    public function toArray(): array
    {
        return [
            'file_id' => $this->fileId,
            'user_id' => $this->userId,
            'original_filename' => $this->originalFilename,
            'storage_filename' => $this->storageFilename,
            'storage_path' => $this->storagePath,
            'bucket_name' => $this->bucketName,
            'file_size' => $this->fileSize,
            'mime_type' => $this->mimeType,
            'checksum' => $this->checksum,
            'upload_status' => $this->uploadStatus,
            'error_message' => $this->errorMessage,
            'created_at' => $this->createdAt?->format('c'),
            'updated_at' => $this->updatedAt?->format('c'),
        ];
    }

    /**
     * Cria entidade a partir de array (do banco de dados)
     * 
     * @param array $data Dados do banco
     * @return self
     */
    public static function fromArray(array $data): self
    {
        $file = new self();
        $file->setFileId($data['file_id'] ?? null);
        $file->setUserId($data['user_id']);
        $file->setOriginalFilename($data['original_filename']);
        $file->setStorageFilename($data['storage_filename']);
        $file->setStoragePath($data['storage_path']);
        $file->setBucketName($data['bucket_name'] ?? 'chat4all-files');
        $file->setFileSize((int)($data['file_size'] ?? 0));
        $file->setMimeType($data['mime_type'] ?? 'application/octet-stream');
        $file->setChecksum($data['checksum'] ?? null);
        $file->setUploadStatus($data['upload_status'] ?? 'pending');
        $file->setErrorMessage($data['error_message'] ?? null);

        if (isset($data['created_at'])) {
            $file->setCreatedAt(new \DateTimeImmutable($data['created_at']));
        }
        if (isset($data['updated_at'])) {
            $file->setUpdatedAt(new \DateTimeImmutable($data['updated_at']));
        }
        if (isset($data['deleted_at']) && $data['deleted_at'] !== null) {
            $file->setDeletedAt(new \DateTimeImmutable($data['deleted_at']));
        }

        return $file;
    }

    /**
     * Retorna tamanho formatado (KB, MB, GB)
     * 
     * @return string
     */
    public function getFormattedSize(): string
    {
        $bytes = $this->fileSize;
        
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        }
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        }
        
        return $bytes . ' bytes';
    }
}
