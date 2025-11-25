<?php

namespace Chat4All\Api\Controller;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Chat4All\Api\Database\Database;
use Chat4All\Api\Service\KafkaProducer;
use Monolog\Logger;

/**
 * Controller de mensagens
 * Responsável por envio e listagem de mensagens
 */
class MessageController
{
    private Database $database;
    private KafkaProducer $kafkaProducer;
    private Logger $logger;

    public function __construct(
        Database $database,
        KafkaProducer $kafkaProducer,
        Logger $logger
    ) {
        $this->database = $database;
        $this->kafkaProducer = $kafkaProducer;
        $this->logger = $logger;
    }

    /**
     * POST /v1/messages
     * Envia uma nova mensagem
     * 
     * Body esperado:
     * {
     *   "conversation_id": "uuid-da-conversa",
     *   "content": "Texto da mensagem",
     *   "message_type": "text" (opcional, padrão: text)
     * }
     */
    public function sendMessage(Request $request, Response $response): Response
    {
        try {
            // Pegar dados do usuário autenticado (adicionados pelo middleware)
            $userId = $request->getAttribute('user_id');
            $username = $request->getAttribute('username');

            // Pegar dados do body
            $data = $request->getParsedBody();

            // Validações
            if (!isset($data['conversation_id']) || !isset($data['content'])) {
                return $this->errorResponse($response, 'conversation_id e content são obrigatórios', 400);
            }

            $conversationId = $data['conversation_id'];
            $content = trim($data['content']);
            $messageType = $data['message_type'] ?? 'text';
            $fileId = $data['file_id'] ?? null;

            if (empty($content)) {
                return $this->errorResponse($response, 'Conteúdo da mensagem não pode ser vazio', 400);
            }

            // Verificar se usuário pertence à conversa
            if (!$this->database->isUserInConversation($userId, $conversationId)) {
                return $this->errorResponse($response, 'Usuário não pertence a esta conversa', 403);
            }

            // Gerar ID da mensagem
            $messageId = $this->generateUuid();
            $timestamp = date('Y-m-d H:i:s');

            // Preparar dados da mensagem
            $messageData = [
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'from_user_id' => $userId,
                'from_username' => $username,
                'message_type' => $messageType,
                'content' => $content,
                'file_id' => $fileId,
                'status' => 'SENT',
                'timestamp' => $timestamp
            ];

            // 1. Salvar no banco de dados
            $this->database->insertMessage($messageData);

            $this->logger->info('Message saved to database', [
                'message_id' => $messageId,
                'from_user' => $username
            ]);

            // 2. Publicar no Kafka (usar conversation_id como key para manter ordem)
            $this->kafkaProducer->publish($messageData, $conversationId);

            $this->logger->info('Message published to Kafka', [
                'message_id' => $messageId
            ]);

            // 3. Log de auditoria
            $this->database->insertAuditLog(
                'message.sent',
                'message',
                $messageId,
                $userId,
                ['conversation_id' => $conversationId]
            );

            // Retornar resposta
            $responseData = [
                'success' => true,
                'message' => [
                    'message_id' => $messageId,
                    'conversation_id' => $conversationId,
                    'from_user_id' => $userId,
                    'from_username' => $username,
                    'content' => $content,
                    'message_type' => $messageType,
                    'file_id' => $fileId,
                    'status' => 'SENT',
                    'created_at' => $timestamp
                ]
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (\Exception $e) {
            $this->logger->error('Send message error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao enviar mensagem', 500);
        }
    }

    /**
     * GET /v1/conversations/{id}/messages
     * Lista mensagens de uma conversa
     * 
     * Query params opcionais:
     * - limit: número de mensagens (padrão: 50)
     * - offset: paginação (padrão: 0)
     */
    public function listMessages(Request $request, Response $response, array $args): Response
    {
        try {
            // Pegar dados do usuário autenticado
            $userId = $request->getAttribute('user_id');
            $conversationId = $args['id'];

            // Verificar se usuário pertence à conversa
            if (!$this->database->isUserInConversation($userId, $conversationId)) {
                return $this->errorResponse($response, 'Usuário não pertence a esta conversa', 403);
            }

            // Pegar parâmetros de query
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 50;
            $offset = isset($queryParams['offset']) ? (int)$queryParams['offset'] : 0;

            // Limitar máximo de mensagens
            $limit = min($limit, 100);

            // Buscar mensagens
            $messages = $this->database->getMessagesByConversation($conversationId, $limit, $offset);

            // Marcar mensagens SENT como DELIVERED quando o destinatário as buscar
            // Apenas para mensagens que NÃO foram enviadas pelo usuário atual
            $deliveredCount = $this->database->markMessagesAsDelivered($conversationId, $userId);
            
            if ($deliveredCount > 0) {
                $this->logger->info('Messages marked as delivered', [
                    'conversation_id' => $conversationId,
                    'recipient_user_id' => $userId,
                    'count' => $deliveredCount
                ]);

                // Publicar evento no Kafka
                $event = [
                    'event_type' => 'messages_delivered',
                    'conversation_id' => $conversationId,
                    'recipient_user_id' => $userId,
                    'count' => $deliveredCount,
                    'timestamp' => date('Y-m-d H:i:s')
                ];
                $this->kafkaProducer->publish($event, $conversationId);
            }

            $this->logger->info('Messages retrieved', [
                'conversation_id' => $conversationId,
                'count' => count($messages)
            ]);

            // Retornar resposta
            $responseData = [
                'success' => true,
                'conversation_id' => $conversationId,
                'messages' => $messages,
                'pagination' => [
                    'limit' => $limit,
                    'offset' => $offset,
                    'count' => count($messages)
                ]
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('List messages error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao listar mensagens', 500);
        }
    }

    /**
     * GET /v1/conversations
     * Lista conversas do usuário
     */
    public function listConversations(Request $request, Response $response): Response
    {
        try {
            // Pegar dados do usuário autenticado
            $userId = $request->getAttribute('user_id');

            // Pegar parâmetros de query
            $queryParams = $request->getQueryParams();
            $limit = isset($queryParams['limit']) ? (int)$queryParams['limit'] : 20;
            $limit = min($limit, 50);

            // Buscar conversas
            $conversations = $this->database->getUserConversations($userId, $limit);

            $this->logger->info('Conversations retrieved', [
                'user_id' => $userId,
                'count' => count($conversations)
            ]);

            // Retornar resposta
            $responseData = [
                'success' => true,
                'conversations' => $conversations,
                'count' => count($conversations)
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('List conversations error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao listar conversas', 500);
        }
    }

    /**
     * POST /v1/conversations/{id}/read
     * Marca todas as mensagens de uma conversa como lidas pelo usuário
     */
    public function markConversationAsRead(Request $request, Response $response, array $args): Response
    {
        try {
            // Pegar dados do usuário autenticado
            $userId = $request->getAttribute('user_id');
            $username = $request->getAttribute('username');
            $conversationId = $args['id'];

            // Verificar se usuário pertence à conversa
            if (!$this->database->isUserInConversation($userId, $conversationId)) {
                return $this->errorResponse($response, 'Usuário não pertence a esta conversa', 403);
            }

            // Marcar mensagens como lidas
            $count = $this->database->markMessagesAsRead($conversationId, $userId);

            $this->logger->info('Messages marked as read', [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'count' => $count
            ]);

            // Se marcou alguma mensagem, registrar no log de auditoria
            if ($count > 0) {
                $this->database->insertAuditLog(
                    'messages.read',
                    'conversation',
                    $conversationId,
                    $userId,
                    ['messages_count' => $count]
                );

                // Publicar evento no Kafka para notificações
                $event = [
                    'event_type' => 'messages_read',
                    'conversation_id' => $conversationId,
                    'user_id' => $userId,
                    'username' => $username,
                    'count' => $count,
                    'timestamp' => date('Y-m-d H:i:s')
                ];

                $this->kafkaProducer->publish($event, $conversationId);

                $this->logger->info('Message read event published to Kafka', [
                    'conversation_id' => $conversationId,
                    'count' => $count
                ]);
            }

            // Retornar resposta
            $responseData = [
                'success' => true,
                'conversation_id' => $conversationId,
                'messages_marked' => $count
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Mark as read error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao marcar mensagens como lidas', 500);
        }
    }

    /**
     * GET /v1/conversations/{id}/unread
     * Retorna contagem de mensagens não lidas em uma conversa
     */
    public function getUnreadCount(Request $request, Response $response, array $args): Response
    {
        try {
            // Pegar dados do usuário autenticado
            $userId = $request->getAttribute('user_id');
            $conversationId = $args['id'];

            // Verificar se usuário pertence à conversa
            if (!$this->database->isUserInConversation($userId, $conversationId)) {
                return $this->errorResponse($response, 'Usuário não pertence a esta conversa', 403);
            }

            // Contar mensagens não lidas
            $count = $this->database->countUnreadMessages($conversationId, $userId);

            // Retornar resposta
            $responseData = [
                'success' => true,
                'conversation_id' => $conversationId,
                'unread_count' => $count
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Get unread count error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro ao contar mensagens não lidas', 500);
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
