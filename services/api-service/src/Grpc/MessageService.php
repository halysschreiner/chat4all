<?php

namespace Chat4All\Api\Grpc;

use Chat4All\Api\Database\Database;
use Chat4All\Api\Service\KafkaProducer;
use Message\SendMessageRequest;
use Message\SendMessageResponse;
use Message\ListMessagesRequest;
use Message\ListMessagesResponse;
use Message\Message;
use Message\MarkAsReadRequest;
use Message\MarkAsReadResponse;
use Message\UpdateMessageStatusRequest;
use Message\UpdateMessageStatusResponse;
use Monolog\Logger;
use Ramsey\Uuid\Uuid;

class MessageService
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

    public function SendMessage(
        SendMessageRequest $request
    ): SendMessageResponse {
        $response = new SendMessageResponse();
        
        try {
            $conversationId = $request->getConversationId();
            $fromUserId = $request->getFromUserId();
            $content = $request->getContent();
            $messageType = $request->getMessageType() ?: 'text';
            $fileId = $request->getFileId() ?: null;
            
            // Create message payload
            $messageId = Uuid::uuid4()->toString();
            $timestamp = date('Y-m-d H:i:s');
            
            $payload = [
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'from_user_id' => $fromUserId,
                'content' => $content,
                'message_type' => $messageType,
                'file_id' => $fileId,
                'status' => 'SENT',
                'created_at' => $timestamp
            ];
            
            // Save to database first (Outbox pattern or just simple save)
            // For this architecture, we save to DB then send to Kafka for async processing
            $this->database->insertMessage($payload);
            
            // Send to Kafka
            $this->kafkaProducer->publish(
                $payload,
                $conversationId // Key (partition by conversation)
            );
            
            $response->setSuccess(true);
            $response->setMessage("Message sent to queue");
            
            // Construct returned message object
            $msg = new Message();
            $msg->setMessageId($messageId);
            $msg->setConversationId($conversationId);
            $msg->setFromUserId($fromUserId);
            $msg->setContent($content);
            $msg->setMessageType($messageType);
            $msg->setStatus('SENT');
            $msg->setCreatedAt($timestamp);
            if ($fileId) {
                $msg->setFileId($fileId);
            }
            
            $response->setSentMessage($msg);
            
            $this->logger->info("Message sent to Kafka", ['id' => $messageId]);
            
        } catch (\Exception $e) {
            $this->logger->error("Error sending message: " . $e->getMessage());
            $response->setSuccess(false);
            $response->setMessage($e->getMessage());
        }
        
        return $response;
    }

    public function ListMessages(
        ListMessagesRequest $request
    ): ListMessagesResponse {
        $response = new ListMessagesResponse();
        
        try {
            $conversationId = $request->getConversationId();
            $userId = $request->getUserId();
            $limit = $request->getLimit() ?: 50;
            $offset = $request->getOffset() ?: 0;
            
            $this->logger->info('ListMessages called', [
                'conversation_id' => $conversationId,
                'user_id' => $userId,
                'limit' => $limit,
                'offset' => $offset
            ]);
            
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
            } else {
                $this->logger->info('No messages to mark as delivered', [
                    'conversation_id' => $conversationId,
                    'recipient_user_id' => $userId
                ]);
            }
            
            $messagesData = $this->database->getMessagesByConversation($conversationId, $limit, $offset);
            
            $messages = [];
            foreach ($messagesData as $data) {
                $msg = new Message();
                $msg->setMessageId($data['message_id']);
                $msg->setConversationId($data['conversation_id']);
                $msg->setFromUserId($data['from_user_id']);
                $msg->setFromUsername($data['from_username'] ?? '');
                $msg->setMessageType($data['message_type']);
                $msg->setContent($data['content']);
                $msg->setStatus($data['status']);
                $msg->setCreatedAt($data['created_at']);
                if (!empty($data['file_id'])) {
                    $msg->setFileId($data['file_id']);
                }
                
                $messages[] = $msg;
            }
            
            $response->setSuccess(true);
            $response->setMessages($messages);
            
        } catch (\Exception $e) {
            $this->logger->error("Error listing messages: " . $e->getMessage());
            $response->setSuccess(false);
        }
        
        return $response;
    }
    
    public function MarkAsRead(MarkAsReadRequest $request): MarkAsReadResponse {
        return new MarkAsReadResponse();
    }
    
    public function UpdateMessageStatus(UpdateMessageStatusRequest $request): UpdateMessageStatusResponse {
        return new UpdateMessageStatusResponse();
    }
}
