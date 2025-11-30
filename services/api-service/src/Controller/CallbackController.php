<?php

declare(strict_types=1);

namespace Chat4All\Api\Controller;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Chat4All\Api\Database\Database;
use Chat4All\Api\Service\NotificationService;
use Monolog\Logger;

/**
 * CallbackController - Recebe callbacks de status dos conectores
 * 
 * Responsável por processar callbacks de status (SENT, DELIVERED, READ, FAILED)
 * enviados pelos conectores WhatsApp e Instagram, atualizar o banco de dados
 * e notificar clientes WebSocket.
 */
class CallbackController
{
    private Database $database;
    private NotificationService $notificationService;
    private Logger $logger;

    public function __construct(
        Database $database,
        NotificationService $notificationService,
        Logger $logger
    ) {
        $this->database = $database;
        $this->notificationService = $notificationService;
        $this->logger = $logger;
    }

    /**
     * POST /v1/callbacks/status
     * Recebe callback de status genérico de qualquer conector
     * 
     * Body esperado:
     * {
     *   "message_id": "uuid-da-mensagem",
     *   "status": "DELIVERED",
     *   "connector": "whatsapp",
     *   "timestamp": "2025-01-01T12:00:00Z",
     *   "metadata": { ... }
     * }
     */
    public function receiveStatus(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();

            // Validações
            if (!isset($data['message_id']) || !isset($data['status'])) {
                return $this->errorResponse($response, 'message_id e status são obrigatórios', 400);
            }

            $messageId = $data['message_id'];
            $status = strtoupper($data['status']);
            $connector = $data['connector'] ?? 'unknown';
            $timestamp = $data['timestamp'] ?? date('c');
            $metadata = $data['metadata'] ?? [];

            // Validar status
            $validStatuses = ['SENT', 'DELIVERED', 'READ', 'FAILED'];
            if (!in_array($status, $validStatuses)) {
                return $this->errorResponse(
                    $response, 
                    'Status inválido. Valores aceitos: ' . implode(', ', $validStatuses), 
                    400
                );
            }

            $this->logger->info("📥 Callback recebido de {$connector}", [
                'message_id' => $messageId,
                'status' => $status,
                'connector' => $connector
            ]);

            // Processar callback
            $result = $this->processCallback($messageId, $status, $connector, $timestamp, $metadata);

            if (!$result['success']) {
                return $this->errorResponse($response, $result['error'], $result['code'] ?? 500);
            }

            $this->logger->info("✅ Callback processado com sucesso", [
                'message_id' => $messageId,
                'status' => $status
            ]);

            return $this->jsonResponse($response, [
                'success' => true,
                'message_id' => $messageId,
                'status' => $status,
                'processed_at' => date('c')
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('❌ Erro ao processar callback: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            return $this->errorResponse($response, 'Erro interno ao processar callback', 500);
        }
    }

    /**
     * POST /v1/callbacks/whatsapp
     * Recebe callback específico do WhatsApp
     */
    public function receiveWhatsappCallback(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $data['connector'] = 'whatsapp';
        
        // Reutiliza o handler genérico
        $newRequest = $request->withParsedBody($data);
        return $this->receiveStatus($newRequest, $response);
    }

    /**
     * POST /v1/callbacks/instagram
     * Recebe callback específico do Instagram
     */
    public function receiveInstagramCallback(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $data['connector'] = 'instagram';
        
        // Reutiliza o handler genérico
        $newRequest = $request->withParsedBody($data);
        return $this->receiveStatus($newRequest, $response);
    }

    /**
     * GET /v1/callbacks/message/{messageId}
     * Retorna histórico de callbacks de uma mensagem
     */
    public function getMessageCallbacks(Request $request, Response $response, array $args): Response
    {
        try {
            $messageId = $args['messageId'] ?? null;

            if (!$messageId) {
                return $this->errorResponse($response, 'messageId é obrigatório', 400);
            }

            // Verificar se a mensagem existe
            $message = $this->database->getMessageById($messageId);
            if (!$message) {
                return $this->errorResponse($response, 'Mensagem não encontrada', 404);
            }

            // Buscar callbacks
            $callbacks = $this->database->getCallbacksByMessageId($messageId);

            return $this->jsonResponse($response, [
                'message_id' => $messageId,
                'current_status' => $message['status'] ?? 'PENDING',
                'callbacks' => $callbacks,
                'total' => count($callbacks)
            ], 200);

        } catch (\Exception $e) {
            $this->logger->error('❌ Erro ao buscar callbacks: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro interno', 500);
        }
    }

    /**
     * Processa o callback de status
     */
    private function processCallback(
        string $messageId, 
        string $status, 
        string $connector, 
        string $timestamp,
        array $metadata
    ): array {
        // Verificar se a mensagem existe
        $message = $this->database->getMessageById($messageId);
        if (!$message) {
            $this->logger->warning("⚠️ Callback para mensagem inexistente: {$messageId}");
            return [
                'success' => false,
                'error' => 'Mensagem não encontrada',
                'code' => 404
            ];
        }

        // Validar transição de status
        $currentStatus = $message['status'] ?? 'PENDING';
        if (!$this->isValidStatusTransition($currentStatus, $status)) {
            $this->logger->warning("⚠️ Transição de status inválida: {$currentStatus} → {$status}", [
                'message_id' => $messageId
            ]);
            // Não falhar, apenas logar (callbacks podem chegar fora de ordem)
        }

        // Atualizar status da mensagem no banco
        $updateResult = $this->database->updateMessageStatus($messageId, $status);
        if (!$updateResult) {
            return [
                'success' => false,
                'error' => 'Falha ao atualizar status da mensagem',
                'code' => 500
            ];
        }

        // Registrar callback no histórico
        $callbackId = $this->generateUuid();
        $this->database->insertDeliveryCallback([
            'id' => $callbackId,
            'message_id' => $messageId,
            'status' => $status,
            'connector' => $connector,
            'received_at' => date('Y-m-d H:i:s'),
            'connector_timestamp' => $timestamp,
            'metadata' => json_encode($metadata)
        ]);

        // Notificar via WebSocket
        $this->notifyStatusUpdate($message, $status, $connector, $timestamp);

        // Registrar audit log
        $this->database->insertAuditLog(
            'message_status_update',
            'message',
            $messageId,
            null,
            [
                'old_status' => $currentStatus,
                'new_status' => $status,
                'connector' => $connector,
                'callback_id' => $callbackId
            ]
        );

        return ['success' => true];
    }

    /**
     * Valida se a transição de status é válida
     * 
     * Fluxo esperado: PENDING → SENT → DELIVERED → READ
     * FAILED pode vir de qualquer estado
     */
    private function isValidStatusTransition(string $from, string $to): bool
    {
        $validTransitions = [
            'PENDING' => ['SENT', 'DELIVERED', 'READ', 'FAILED'],
            'SENT' => ['DELIVERED', 'READ', 'FAILED'],
            'DELIVERED' => ['READ', 'FAILED'],
            'READ' => ['FAILED'], // READ é estado final, só FAILED pode vir depois
            'FAILED' => [] // FAILED é estado terminal
        ];

        return in_array($to, $validTransitions[$from] ?? []);
    }

    /**
     * Notifica clientes WebSocket sobre atualização de status
     */
    private function notifyStatusUpdate(
        array $message, 
        string $status, 
        string $connector,
        string $timestamp
    ): void {
        try {
            $conversationId = $message['conversation_id'] ?? null;
            if (!$conversationId) {
                return;
            }

            $notification = [
                'type' => 'status_update',
                'message_id' => $message['id'],
                'conversation_id' => $conversationId,
                'status' => $status,
                'connector' => $connector,
                'timestamp' => $timestamp,
                'updated_at' => date('c')
            ];

            // Publicar via NotificationService (Redis pub-sub)
            $this->notificationService->publishStatusUpdate($conversationId, $notification);

            $this->logger->debug("📡 Notificação WebSocket enviada", [
                'message_id' => $message['id'],
                'status' => $status
            ]);

        } catch (\Exception $e) {
            $this->logger->error("❌ Erro ao notificar WebSocket: " . $e->getMessage());
            // Não falhar o callback por erro de notificação
        }
    }

    /**
     * Gera UUID v4
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    /**
     * Retorna resposta JSON
     */
    private function jsonResponse(Response $response, array $data, int $status = 200): Response
    {
        $response->getBody()->write(json_encode($data));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }

    /**
     * Retorna resposta de erro
     */
    private function errorResponse(Response $response, string $message, int $status = 400): Response
    {
        return $this->jsonResponse($response, [
            'error' => true,
            'message' => $message,
            'timestamp' => date('c')
        ], $status);
    }
}
