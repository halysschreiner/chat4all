<?php

namespace Chat4All\Api\Service;

use Aws\S3\S3Client;
use Aws\Exception\AwsException;
use Monolog\Logger;

/**
 * Serviço de integração com MinIO (compatível com S3)
 * Gerencia upload, download e armazenamento de arquivos
 */
class MinioService
{
    private S3Client $client;
    private string $bucket;
    private Logger $logger;

    public function __construct(
        string $endpoint,
        string $accessKey,
        string $secretKey,
        string $bucket,
        bool $useSSL,
        Logger $logger
    ) {
        $this->bucket = $bucket;
        $this->logger = $logger;

        // Criar cliente S3 (MinIO é compatível com S3)
        $this->client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => ($useSSL ? 'https://' : 'http://') . $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);

        // Criar bucket se não existir
        $this->ensureBucketExists();
    }

    /**
     * Garante que o bucket existe
     */
    private function ensureBucketExists(): void
    {
        try {
            if (!$this->client->doesBucketExist($this->bucket)) {
                $this->client->createBucket([
                    'Bucket' => $this->bucket,
                ]);
                $this->logger->info('Bucket created', ['bucket' => $this->bucket]);
            }
        } catch (AwsException $e) {
            $this->logger->error('Error checking/creating bucket: ' . $e->getMessage());
        }
    }

    /**
     * Inicia um upload multipart
     * 
     * @param string $key Caminho do arquivo no bucket
     * @param string $contentType MIME type do arquivo
     * @return string Upload ID do MinIO
     */
    public function initiateMultipartUpload(string $key, string $contentType): string
    {
        try {
            $result = $this->client->createMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'ContentType' => $contentType,
            ]);

            $uploadId = $result['UploadId'];
            
            $this->logger->info('Multipart upload initiated', [
                'key' => $key,
                'upload_id' => $uploadId
            ]);

            return $uploadId;
        } catch (AwsException $e) {
            $this->logger->error('Error initiating multipart upload: ' . $e->getMessage());
            throw new \Exception('Erro ao iniciar upload: ' . $e->getMessage());
        }
    }

    /**
     * Faz upload de uma parte
     * 
     * @param string $key Caminho do arquivo no bucket
     * @param string $uploadId ID do upload multipart
     * @param int $partNumber Número da parte (começa em 1)
     * @param string $data Dados binários da parte
     * @return string ETag da parte
     */
    public function uploadPart(string $key, string $uploadId, int $partNumber, string $data): string
    {
        try {
            $result = $this->client->uploadPart([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => $partNumber,
                'Body' => $data,
            ]);

            $etag = $result['ETag'];
            
            $this->logger->info('Part uploaded', [
                'key' => $key,
                'part_number' => $partNumber,
                'etag' => $etag
            ]);

            return $etag;
        } catch (AwsException $e) {
            $this->logger->error('Error uploading part: ' . $e->getMessage());
            throw new \Exception('Erro ao enviar parte: ' . $e->getMessage());
        }
    }

    /**
     * Completa um upload multipart
     * 
     * @param string $key Caminho do arquivo no bucket
     * @param string $uploadId ID do upload multipart
     * @param array $parts Array com part_number e etag de cada parte
     */
    public function completeMultipartUpload(string $key, string $uploadId, array $parts): void
    {
        try {
            // Formatar partes para o formato esperado pelo S3
            $formattedParts = [];
            foreach ($parts as $part) {
                $formattedParts[] = [
                    'PartNumber' => $part['part_number'],
                    'ETag' => $part['etag'],
                ];
            }

            // Ordenar por número da parte
            usort($formattedParts, function ($a, $b) {
                return $a['PartNumber'] - $b['PartNumber'];
            });

            $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => $formattedParts,
                ],
            ]);

            $this->logger->info('Multipart upload completed', [
                'key' => $key,
                'parts_count' => count($parts)
            ]);
        } catch (AwsException $e) {
            $this->logger->error('Error completing multipart upload: ' . $e->getMessage());
            throw new \Exception('Erro ao completar upload: ' . $e->getMessage());
        }
    }

    /**
     * Cancela um upload multipart
     * 
     * @param string $key Caminho do arquivo no bucket
     * @param string $uploadId ID do upload multipart
     */
    public function abortMultipartUpload(string $key, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);

            $this->logger->info('Multipart upload aborted', [
                'key' => $key,
                'upload_id' => $uploadId
            ]);
        } catch (AwsException $e) {
            $this->logger->error('Error aborting multipart upload: ' . $e->getMessage());
            throw new \Exception('Erro ao cancelar upload: ' . $e->getMessage());
        }
    }

    /**
     * Gera URL pré-assinada para download
     * 
     * @param string $key Caminho do arquivo no bucket
     * @param int $expirationSeconds Tempo de expiração em segundos
     * @return string URL temporária
     */
    public function getPresignedUrl(string $key, int $expirationSeconds = 3600): string
    {
        try {
            $cmd = $this->client->getCommand('GetObject', [
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            $request = $this->client->createPresignedRequest($cmd, "+{$expirationSeconds} seconds");
            
            $presignedUrl = (string)$request->getUri();

            $this->logger->info('Presigned URL generated', [
                'key' => $key,
                'expires_in' => $expirationSeconds
            ]);

            return $presignedUrl;
        } catch (AwsException $e) {
            $this->logger->error('Error generating presigned URL: ' . $e->getMessage());
            throw new \Exception('Erro ao gerar URL de download: ' . $e->getMessage());
        }
    }

    /**
     * Deleta um objeto do bucket
     * 
     * @param string $key Caminho do arquivo no bucket
     */
    public function deleteObject(string $key): void
    {
        try {
            $this->client->deleteObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            $this->logger->info('Object deleted', ['key' => $key]);
        } catch (AwsException $e) {
            $this->logger->error('Error deleting object: ' . $e->getMessage());
            throw new \Exception('Erro ao deletar arquivo: ' . $e->getMessage());
        }
    }

    /**
     * Verifica se um objeto existe
     * 
     * @param string $key Caminho do arquivo no bucket
     * @return bool
     */
    public function objectExists(string $key): bool
    {
        try {
            return $this->client->doesObjectExist($this->bucket, $key);
        } catch (AwsException $e) {
            $this->logger->error('Error checking object existence: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtém metadados de um objeto
     * 
     * @param string $key Caminho do arquivo no bucket
     * @return array|null
     */
    public function getObjectMetadata(string $key): ?array
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);

            return [
                'content_type' => $result['ContentType'] ?? null,
                'content_length' => $result['ContentLength'] ?? 0,
                'last_modified' => $result['LastModified'] ?? null,
                'etag' => $result['ETag'] ?? null,
            ];
        } catch (AwsException $e) {
            $this->logger->error('Error getting object metadata: ' . $e->getMessage());
            return null;
        }
    }
}
