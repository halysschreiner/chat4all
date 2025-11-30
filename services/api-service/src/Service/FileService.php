<?php
/**
 * ================================================
 * FileService - Chat4All API Service
 * ================================================
 * 
 * Serviço de lógica de negócio para operações com
 * arquivos, incluindo upload multipart e download.
 * 
 * Features:
 * - Upload multipart (até 2GB)
 * - Download via presigned URL
 * - Verificação de checksum
 * - Validação de tamanho
 * 
 * @package Chat4All\Api\Service
 * @author  Chat4All Team
 * @since   1.0.0
 * ================================================
 */

namespace Chat4All\Api\Service;

use Chat4All\Api\Entity\File;
use Chat4All\Api\Repository\FileRepository;
use Ramsey\Uuid\Uuid;
use Monolog\Logger;

class FileService
{
    /**
     * Repository de arquivos
     * @var FileRepository
     */
    private FileRepository $repository;

    /**
     * Serviço MinIO/S3
     * @var MinioService
     */
    private MinioService $minio;

    /**
     * Serviço de notificações
     * @var NotificationService
     */
    private NotificationService $notification;

    /**
     * Serviço de métricas
     * @var MetricsService|null
     */
    private ?MetricsService $metrics;

    /**
     * Logger
     * @var Logger
     */
    private Logger $logger;

    /**
     * Tamanho máximo de arquivo (2GB)
     * @var int
     */
    private const MAX_FILE_SIZE = 2147483648; // 2GB em bytes

    /**
     * Tamanho mínimo para uma parte de upload (5MB)
     * @var int
     */
    private const MIN_PART_SIZE = 5242880; // 5MB

    /**
     * Construtor do serviço
     * 
     * @param FileRepository $repository Repository de arquivos
     * @param MinioService $minio Serviço MinIO
     * @param NotificationService $notification Serviço de notificações
     * @param Logger $logger Logger
     * @param MetricsService|null $metrics Serviço de métricas (opcional)
     */
    public function __construct(
        FileRepository $repository,
        MinioService $minio,
        NotificationService $notification,
        Logger $logger,
        ?MetricsService $metrics = null
    ) {
        $this->repository = $repository;
        $this->minio = $minio;
        $this->notification = $notification;
        $this->logger = $logger;
        $this->metrics = $metrics;

        $this->logger->info('FileService inicializado');
    }

    /**
     * Inicia um upload multipart
     * 
     * Cria registro no banco e inicia upload no MinIO.
     * Retorna informações necessárias para upload das partes.
     * 
     * @param string $userId ID do usuário
     * @param string $filename Nome original do arquivo
     * @param int $fileSize Tamanho total em bytes
     * @param string $mimeType MIME type
     * @return array Dados do upload iniciado
     * @throws \InvalidArgumentException Se validação falhar
     */
    public function initiateUpload(
        string $userId,
        string $filename,
        int $fileSize,
        string $mimeType
    ): array {
        // Validar tamanho
        if ($fileSize > self::MAX_FILE_SIZE) {
            throw new \InvalidArgumentException(
                'Tamanho máximo de arquivo excedido (max: 2GB)'
            );
        }

        if ($fileSize <= 0) {
            throw new \InvalidArgumentException(
                'Tamanho do arquivo inválido'
            );
        }

        // Gerar nome único para storage
        $storageFilename = Uuid::uuid4()->toString() . '_' . $this->sanitizeFilename($filename);
        $storagePath = "uploads/{$userId}/{$storageFilename}";

        // Criar entidade
        $file = new File();
        $file->setUserId($userId);
        $file->setOriginalFilename($filename);
        $file->setStorageFilename($storageFilename);
        $file->setStoragePath($storagePath);
        $file->setFileSize($fileSize);
        $file->setMimeType($mimeType);
        $file->setUploadStatus(File::STATUS_PENDING);

        // Salvar no banco
        $file = $this->repository->insert($file);

        // Iniciar upload multipart no MinIO
        $uploadId = $this->minio->initiateMultipartUpload($storagePath, $mimeType);

        // Calcular número de partes
        $partSize = max(self::MIN_PART_SIZE, (int) ceil($fileSize / 10000)); // Max 10000 partes
        $totalParts = (int) ceil($fileSize / $partSize);

        // Atualizar status
        $this->repository->updateStatus($file->getFileId(), File::STATUS_UPLOADING);

        $this->logger->info('Upload multipart iniciado', [
            'fileId' => $file->getFileId(),
            'filename' => $filename,
            'fileSize' => $fileSize,
            'totalParts' => $totalParts,
            'uploadId' => $uploadId,
        ]);

        return [
            'file_id' => $file->getFileId(),
            'upload_id' => $uploadId,
            'storage_path' => $storagePath,
            'total_parts' => $totalParts,
            'part_size' => $partSize,
            'file_size' => $fileSize,
        ];
    }

    /**
     * Faz upload de uma parte do arquivo
     * 
     * @param string $fileId ID do arquivo
     * @param string $uploadId ID do upload multipart
     * @param int $partNumber Número da parte (1-indexed)
     * @param string $data Conteúdo da parte
     * @return array Informações da parte uploaded
     * @throws \InvalidArgumentException Se validação falhar
     */
    public function uploadPart(
        string $fileId,
        string $uploadId,
        int $partNumber,
        string $data
    ): array {
        // Buscar arquivo
        $file = $this->repository->findById($fileId);
        
        if (!$file) {
            throw new \InvalidArgumentException('Arquivo não encontrado');
        }

        if (!$file->isUploading()) {
            throw new \InvalidArgumentException('Upload não está em andamento');
        }

        // Fazer upload da parte no MinIO
        $etag = $this->minio->uploadPart(
            $file->getStoragePath(),
            $uploadId,
            $partNumber,
            $data
        );

        $this->logger->debug('Parte uploaded', [
            'fileId' => $fileId,
            'partNumber' => $partNumber,
            'size' => strlen($data),
            'etag' => $etag,
        ]);

        return [
            'part_number' => $partNumber,
            'etag' => $etag,
            'size' => strlen($data),
        ];
    }

    /**
     * Completa um upload multipart
     * 
     * @param string $fileId ID do arquivo
     * @param string $uploadId ID do upload multipart
     * @param array $parts Lista de partes [{partNumber, etag}, ...]
     * @param string|null $checksum Checksum SHA-256 esperado
     * @return File Arquivo completado
     * @throws \InvalidArgumentException Se validação falhar
     */
    public function completeUpload(
        string $fileId,
        string $uploadId,
        array $parts,
        ?string $checksum = null
    ): File {
        // Buscar arquivo
        $file = $this->repository->findById($fileId);
        
        if (!$file) {
            throw new \InvalidArgumentException('Arquivo não encontrado');
        }

        if (!$file->isUploading()) {
            throw new \InvalidArgumentException('Upload não está em andamento');
        }

        try {
            // Completar upload no MinIO
            $result = $this->minio->completeMultipartUpload(
                $file->getStoragePath(),
                $uploadId,
                $parts
            );

            // Atualizar entidade
            if ($checksum) {
                $file->setChecksum($checksum);
            }
            $file->setUploadStatus(File::STATUS_COMPLETED);
            
            // Salvar no banco
            $this->repository->update($file);

            // Notificar usuário
            $this->notification->notifyFileUploaded(
                $file->getUserId(),
                $file->getFileId(),
                $file->getOriginalFilename()
            );

            // Métricas
            if ($this->metrics) {
                $this->metrics->incrementFilesUploaded('completed');
                $this->metrics->observeFileSize($file->getFileSize());
            }

            $this->logger->info('Upload multipart completado', [
                'fileId' => $fileId,
                'filename' => $file->getOriginalFilename(),
                'fileSize' => $file->getFileSize(),
            ]);

            return $file;

        } catch (\Exception $e) {
            // Falha no upload
            $file->setUploadStatus(File::STATUS_FAILED);
            $file->setErrorMessage($e->getMessage());
            $this->repository->update($file);

            // Notificar falha
            $this->notification->notifyFileUploadFailed(
                $file->getUserId(),
                $file->getFileId(),
                $e->getMessage()
            );

            // Métricas
            if ($this->metrics) {
                $this->metrics->incrementFilesUploaded('failed');
            }

            $this->logger->error('Falha ao completar upload', [
                'fileId' => $fileId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Cancela um upload em andamento
     * 
     * @param string $fileId ID do arquivo
     * @param string $uploadId ID do upload multipart
     * @return bool Sucesso
     */
    public function abortUpload(string $fileId, string $uploadId): bool
    {
        $file = $this->repository->findById($fileId);
        
        if (!$file) {
            throw new \InvalidArgumentException('Arquivo não encontrado');
        }

        try {
            // Abortar no MinIO
            $this->minio->abortMultipartUpload($file->getStoragePath(), $uploadId);

            // Atualizar status
            $this->repository->updateStatus($fileId, File::STATUS_FAILED, 'Upload abortado pelo usuário');

            $this->logger->info('Upload abortado', [
                'fileId' => $fileId,
            ]);

            return true;

        } catch (\Exception $e) {
            $this->logger->error('Erro ao abortar upload', [
                'fileId' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Obtém metadados de um arquivo
     * 
     * @param string $fileId ID do arquivo
     * @param string $userId ID do usuário (para verificação de acesso)
     * @return File|null Arquivo ou null se não encontrado/sem acesso
     */
    public function getFile(string $fileId, string $userId): ?File
    {
        $file = $this->repository->findById($fileId);

        if (!$file) {
            return null;
        }

        // Verificar se o usuário tem acesso
        if ($file->getUserId() !== $userId) {
            $this->logger->warning('Acesso negado ao arquivo', [
                'fileId' => $fileId,
                'userId' => $userId,
                'ownerId' => $file->getUserId(),
            ]);
            return null;
        }

        return $file;
    }

    /**
     * Gera URL presigned para download
     * 
     * @param string $fileId ID do arquivo
     * @param string $userId ID do usuário (para verificação de acesso)
     * @param int $expiresInSeconds Tempo de expiração (default: 1 hora)
     * @return string|null URL presigned ou null se não disponível
     */
    public function getDownloadUrl(
        string $fileId,
        string $userId,
        int $expiresInSeconds = 3600
    ): ?string {
        $file = $this->getFile($fileId, $userId);

        if (!$file || !$file->isAvailable()) {
            return null;
        }

        try {
            $url = $this->minio->getPresignedUrl(
                $file->getStoragePath(),
                $expiresInSeconds,
                $file->getOriginalFilename()
            );

            $this->logger->info('URL presigned gerada', [
                'fileId' => $fileId,
                'expiresIn' => $expiresInSeconds,
            ]);

            return $url;

        } catch (\Exception $e) {
            $this->logger->error('Erro ao gerar URL presigned', [
                'fileId' => $fileId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Lista arquivos de um usuário
     * 
     * @param string $userId ID do usuário
     * @param int $limit Limite de resultados
     * @param int $offset Offset para paginação
     * @return array Lista de arquivos
     */
    public function listFiles(string $userId, int $limit = 50, int $offset = 0): array
    {
        return $this->repository->findByUserId($userId, $limit, $offset);
    }

    /**
     * Deleta um arquivo (soft delete)
     * 
     * @param string $fileId ID do arquivo
     * @param string $userId ID do usuário (para verificação de acesso)
     * @return bool Sucesso
     */
    public function deleteFile(string $fileId, string $userId): bool
    {
        $file = $this->getFile($fileId, $userId);

        if (!$file) {
            return false;
        }

        // Soft delete no banco
        $this->repository->delete($fileId);

        // Opcionalmente, deletar do MinIO também
        // $this->minio->deleteObject($file->getStoragePath());

        $this->logger->info('Arquivo deletado', [
            'fileId' => $fileId,
            'userId' => $userId,
        ]);

        return true;
    }

    /**
     * Sanitiza nome de arquivo para storage
     * 
     * @param string $filename Nome original
     * @return string Nome sanitizado
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remover caracteres especiais
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Limitar tamanho
        if (strlen($filename) > 100) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $filename = substr($name, 0, 90) . '.' . $ext;
        }

        return $filename;
    }

    /**
     * Obtém estatísticas de arquivos do usuário
     * 
     * @param string $userId ID do usuário
     * @return array Estatísticas
     */
    public function getStats(string $userId): array
    {
        return [
            'total_files' => $this->repository->countByUserId($userId),
            'total_size' => $this->repository->getTotalSizeByUserId($userId),
        ];
    }
}
