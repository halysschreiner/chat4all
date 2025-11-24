<?php

namespace Chat4All\Api\Controller;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Chat4All\Api\Database\Database;
use Chat4All\Api\Service\MinioService;
use Monolog\Logger;

/**
 * Controller de Arquivos
 * Responsável por upload, download e gerenciamento de arquivos
 * Suporta upload multipart para arquivos grandes (até 2GB)
 */
class FileController
{
    private Database $database;
    private MinioService $minioService;
    private Logger $logger;
    
    // Tamanho máximo de arquivo: 2GB
    private const MAX_FILE_SIZE = 2 * 1024 * 1024 * 1024;
    
    // Tamanho de cada parte no upload multipart: 5MB
    private const PART_SIZE = 5 * 1024 * 1024;

    public function __construct(
        Database $database,
        MinioService $minioService,
        Logger $logger
    ) {
        $this->database = $database;
        $this->minioService = $minioService;
        $this->logger = $logger;
    }

    /**
     * POST /v1/files/upload/initiate
     * Inicia um upload multipart
     * 
     * Body esperado:
     * {
     *   "conversation_id": "uuid-da-conversa",
     *   "filename": "documento.pdf",
     *   "file_size": 104857600,
     *   "content_type": "application/pdf",
     *   "checksum": "md5-hash-opcional"
     * }
     */
    public function initiateUpload(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $username = $request->getAttribute('username');
            $data = $request->getParsedBody();

            // Validações
            if (!isset($data['conversation_id']) || !isset($data['filename']) || !isset($data['file_size'])) {
                return $this->errorResponse($response, 'conversation_id, filename e file_size são obrigatórios', 400);
            }

            $conversationId = $data['conversation_id'];
            $filename = $data['filename'];
            $fileSize = (int)$data['file_size'];
            $contentType = $data['content_type'] ?? 'application/octet-stream';
            $checksum = $data['checksum'] ?? null;

            // Validar tamanho
            if ($fileSize <= 0) {
                return $this->errorResponse($response, 'Tamanho do arquivo inválido', 400);
            }

            if ($fileSize > self::MAX_FILE_SIZE) {
                return $this->errorResponse($response, 'Arquivo muito grande. Máximo: 2GB', 400);
            }

            // Verificar se usuário pertence à conversa
            if (!$this->database->isUserInConversation($userId, $conversationId)) {
                return $this->errorResponse($response, 'Usuário não pertence a esta conversa', 403);
            }

            // Gerar IDs
            $fileId = $this->generateUuid();
            $uploadId = $this->generateUuid();
            
            // Calcular número de partes
            $totalParts = (int)ceil($fileSize / self::PART_SIZE);

            // Sanitizar nome do arquivo
            $sanitizedFilename = $this->sanitizeFilename($filename);
            $storagePath = sprintf('%s/%s/%s', $conversationId, $fileId, $sanitizedFilename);

            // Salvar registro no banco com status "uploading"
            $fileData = [
                'file_id' => $fileId,
                'upload_id' => $uploadId,
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'username' => $username,
                'filename' => $sanitizedFilename,
                'original_filename' => $filename,
                'file_size' => $fileSize,
                'content_type' => $contentType,
                'storage_path' => $storagePath,
                'checksum' => $checksum,
                'status' => 'uploading',
                'total_parts' => $totalParts,
                'uploaded_parts' => 0
            ];

            $this->database->insertFile($fileData);

            // Iniciar upload multipart no MinIO
            $minioUploadId = $this->minioService->initiateMultipartUpload($storagePath, $contentType);

            // Atualizar com o upload_id do MinIO
            $this->database->updateFileMinioUploadId($fileId, $minioUploadId);

            $this->logger->info('Upload initiated', [
                'file_id' => $fileId,
                'upload_id' => $uploadId,
                'filename' => $filename,
                'size' => $fileSize,
                'parts' => $totalParts
            ]);

            // Retornar resposta
            $responseData = [
                'success' => true,
                'message' => 'Upload iniciado com sucesso',
                'upload_id' => $uploadId,
                'file_id' => $fileId,
                'part_size' => self::PART_SIZE,
                'total_parts' => $totalParts,
                'storage_path' => $storagePath
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Initiate upload error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao iniciar upload', 500);
        }
    }

    /**
     * POST /v1/files/upload/part
     * Faz upload de uma parte do arquivo
     * 
     * Multipart form-data esperado:
     * - upload_id: ID do upload
     * - file_id: ID do arquivo
     * - part_number: Número da parte (1, 2, 3...)
     * - data: Dados binários da parte
     */
    public function uploadPart(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            
            // Pegar dados do form
            $uploadedFiles = $request->getUploadedFiles();
            $params = $request->getParsedBody();

            if (!isset($params['upload_id']) || !isset($params['file_id']) || !isset($params['part_number'])) {
                return $this->errorResponse($response, 'upload_id, file_id e part_number são obrigatórios', 400);
            }

            $uploadId = $params['upload_id'];
            $fileId = $params['file_id'];
            $partNumber = (int)$params['part_number'];

            // Validar se o arquivo existe e pertence ao usuário
            $fileInfo = $this->database->getFileById($fileId);
            
            if (!$fileInfo) {
                return $this->errorResponse($response, 'Arquivo não encontrado', 404);
            }

            if ($fileInfo['user_id'] !== $userId) {
                return $this->errorResponse($response, 'Sem permissão para este arquivo', 403);
            }

            if ($fileInfo['upload_id'] !== $uploadId) {
                return $this->errorResponse($response, 'Upload ID inválido', 400);
            }

            if ($fileInfo['status'] !== 'uploading') {
                return $this->errorResponse($response, 'Upload não está em progresso', 400);
            }

            // Pegar arquivo enviado
            if (!isset($uploadedFiles['data'])) {
                return $this->errorResponse($response, 'Dados da parte não encontrados', 400);
            }

            $uploadedFile = $uploadedFiles['data'];
            
            if ($uploadedFile->getError() !== UPLOAD_ERR_OK) {
                return $this->errorResponse($response, 'Erro ao receber arquivo', 400);
            }

            // Fazer upload da parte para o MinIO
            $partData = $uploadedFile->getStream()->getContents();
            $bytesUploaded = strlen($partData);
            
            $etag = $this->minioService->uploadPart(
                $fileInfo['storage_path'],
                $fileInfo['minio_upload_id'],
                $partNumber,
                $partData
            );

            // Salvar informação da parte no banco
            $this->database->insertFilePart($fileId, $partNumber, $etag, $bytesUploaded);

            // Atualizar contador de partes
            $this->database->incrementFileUploadedParts($fileId);

            $this->logger->info('Part uploaded', [
                'file_id' => $fileId,
                'part_number' => $partNumber,
                'bytes' => $bytesUploaded,
                'etag' => $etag
            ]);

            // Retornar resposta
            $responseData = [
                'success' => true,
                'message' => 'Parte enviada com sucesso',
                'part_number' => $partNumber,
                'etag' => $etag,
                'bytes_uploaded' => $bytesUploaded
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Upload part error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao enviar parte', 500);
        }
    }

    /**
     * POST /v1/files/upload/complete
     * Completa um upload multipart
     * 
     * Body esperado:
     * {
     *   "upload_id": "uuid-do-upload",
     *   "file_id": "uuid-do-arquivo"
     * }
     */
    public function completeUpload(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $data = $request->getParsedBody();

            if (!isset($data['upload_id']) || !isset($data['file_id'])) {
                return $this->errorResponse($response, 'upload_id e file_id são obrigatórios', 400);
            }

            $uploadId = $data['upload_id'];
            $fileId = $data['file_id'];

            // Validar arquivo
            $fileInfo = $this->database->getFileById($fileId);
            
            if (!$fileInfo) {
                return $this->errorResponse($response, 'Arquivo não encontrado', 404);
            }

            if ($fileInfo['user_id'] !== $userId) {
                return $this->errorResponse($response, 'Sem permissão para este arquivo', 403);
            }

            if ($fileInfo['upload_id'] !== $uploadId) {
                return $this->errorResponse($response, 'Upload ID inválido', 400);
            }

            // Verificar se todas as partes foram enviadas
            $parts = $this->database->getFileParts($fileId);
            
            if (count($parts) !== $fileInfo['total_parts']) {
                return $this->errorResponse($response, 'Upload incompleto. Envie todas as partes.', 400);
            }

            // Completar upload no MinIO
            $this->minioService->completeMultipartUpload(
                $fileInfo['storage_path'],
                $fileInfo['minio_upload_id'],
                $parts
            );

            // Atualizar status no banco
            $this->database->updateFileStatus($fileId, 'completed');

            $this->logger->info('Upload completed', [
                'file_id' => $fileId,
                'filename' => $fileInfo['filename']
            ]);

            // Log de auditoria
            $this->database->insertAuditLog(
                'file.uploaded',
                'file',
                $fileId,
                $userId,
                ['conversation_id' => $fileInfo['conversation_id']]
            );

            // Retornar resposta
            $responseData = [
                'success' => true,
                'message' => 'Upload completo',
                'file_info' => [
                    'file_id' => $fileId,
                    'filename' => $fileInfo['filename'],
                    'file_size' => $fileInfo['file_size'],
                    'content_type' => $fileInfo['content_type'],
                    'status' => 'completed'
                ]
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Complete upload error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao completar upload', 500);
        }
    }

    /**
     * POST /v1/files/upload/abort
     * Cancela um upload em progresso
     */
    public function abortUpload(Request $request, Response $response): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $data = $request->getParsedBody();

            if (!isset($data['upload_id']) || !isset($data['file_id'])) {
                return $this->errorResponse($response, 'upload_id e file_id são obrigatórios', 400);
            }

            $uploadId = $data['upload_id'];
            $fileId = $data['file_id'];

            // Validar arquivo
            $fileInfo = $this->database->getFileById($fileId);
            
            if (!$fileInfo) {
                return $this->errorResponse($response, 'Arquivo não encontrado', 404);
            }

            if ($fileInfo['user_id'] !== $userId) {
                return $this->errorResponse($response, 'Sem permissão para este arquivo', 403);
            }

            // Abortar upload no MinIO
            $this->minioService->abortMultipartUpload(
                $fileInfo['storage_path'],
                $fileInfo['minio_upload_id']
            );

            // Atualizar status no banco
            $this->database->updateFileStatus($fileId, 'aborted');

            $this->logger->info('Upload aborted', ['file_id' => $fileId]);

            // Retornar resposta
            $responseData = [
                'success' => true,
                'message' => 'Upload cancelado'
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Abort upload error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao cancelar upload', 500);
        }
    }

    /**
     * GET /v1/files/{id}
     * Obtém informações de um arquivo
     */
    public function getFileInfo(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $fileId = $args['id'];

            $fileInfo = $this->database->getFileById($fileId);
            
            if (!$fileInfo) {
                return $this->errorResponse($response, 'Arquivo não encontrado', 404);
            }

            // Verificar se usuário tem acesso à conversa
            if (!$this->database->isUserInConversation($userId, $fileInfo['conversation_id'])) {
                return $this->errorResponse($response, 'Sem permissão para acessar este arquivo', 403);
            }

            $responseData = [
                'success' => true,
                'file_info' => $fileInfo
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Get file info error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao obter informações do arquivo', 500);
        }
    }

    /**
     * GET /v1/files/{id}/download
     * Gera URL temporária para download
     */
    public function getDownloadUrl(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $fileId = $args['id'];

            $fileInfo = $this->database->getFileById($fileId);
            
            if (!$fileInfo) {
                return $this->errorResponse($response, 'Arquivo não encontrado', 404);
            }

            if ($fileInfo['status'] !== 'completed') {
                return $this->errorResponse($response, 'Arquivo ainda não está disponível', 400);
            }

            // Verificar se usuário tem acesso à conversa
            if (!$this->database->isUserInConversation($userId, $fileInfo['conversation_id'])) {
                return $this->errorResponse($response, 'Sem permissão para acessar este arquivo', 403);
            }

            // Gerar URL temporária (válida por 1 hora)
            $downloadUrl = $this->minioService->getPresignedUrl(
                $fileInfo['storage_path'],
                3600 // 1 hora
            );

            $this->logger->info('Download URL generated', ['file_id' => $fileId]);

            $responseData = [
                'success' => true,
                'download_url' => $downloadUrl,
                'expires_at' => time() + 3600
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Get download URL error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao gerar URL de download', 500);
        }
    }

    /**
     * GET /v1/conversations/{id}/files
     * Lista arquivos de uma conversa
     */
    public function listFiles(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $conversationId = $args['id'];

            // Verificar permissão
            if (!$this->database->isUserInConversation($userId, $conversationId)) {
                return $this->errorResponse($response, 'Sem permissão para acessar esta conversa', 403);
            }

            // Pegar parâmetros de query
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 20;
            $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;
            $fileType = $queryParams['file_type'] ?? null;

            $limit = min($limit, 100);

            // Buscar arquivos
            $files = $this->database->getFilesByConversation($conversationId, $limit, $offset, $fileType);

            $responseData = [
                'success' => true,
                'conversation_id' => $conversationId,
                'files' => $files,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($files)
                ]
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('List files error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao listar arquivos', 500);
        }
    }

    /**
     * DELETE /v1/files/{id}
     * Deleta um arquivo (apenas o dono pode deletar)
     */
    public function deleteFile(Request $request, Response $response, array $args): Response
    {
        try {
            $userId = $request->getAttribute('user_id');
            $fileId = $args['id'];

            $fileInfo = $this->database->getFileById($fileId);
            
            if (!$fileInfo) {
                return $this->errorResponse($response, 'Arquivo não encontrado', 404);
            }

            // Apenas o dono pode deletar
            if ($fileInfo['user_id'] !== $userId) {
                return $this->errorResponse($response, 'Apenas o dono pode deletar o arquivo', 403);
            }

            // Deletar do MinIO
            if ($fileInfo['status'] === 'completed') {
                $this->minioService->deleteObject($fileInfo['storage_path']);
            }

            // Atualizar status no banco
            $this->database->updateFileStatus($fileId, 'deleted');

            $this->logger->info('File deleted', ['file_id' => $fileId]);

            // Log de auditoria
            $this->database->insertAuditLog(
                'file.deleted',
                'file',
                $fileId,
                $userId,
                ['conversation_id' => $fileInfo['conversation_id']]
            );

            $responseData = [
                'success' => true,
                'message' => 'Arquivo deletado com sucesso'
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Delete file error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao deletar arquivo', 500);
        }
    }

    /**
     * Helper para gerar UUID v4
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }

    /**
     * Helper para sanitizar nome de arquivo
     */
    private function sanitizeFilename(string $filename): string
    {
        // Remove caracteres especiais perigosos
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        
        // Limita o tamanho
        if (strlen($filename) > 255) {
            $ext = pathinfo($filename, PATHINFO_EXTENSION);
            $name = pathinfo($filename, PATHINFO_FILENAME);
            $name = substr($name, 0, 250 - strlen($ext));
            $filename = $name . '.' . $ext;
        }
        
        return $filename;
    }

    /**
     * Helper para retornar resposta de erro
     */
    private function errorResponse(Response $response, string $message, int $status): Response
    {
        $data = [
            'success' => false,
            'error' => $message
        ];

        $response->getBody()->write(json_encode($data));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
