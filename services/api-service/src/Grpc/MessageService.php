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
            
            // Create message payload
            $messageId = Uuid::uuid4()->toString();
            $timestamp = date('Y-m-d H:i:s');
            
            $payload = [
                'id' => $messageId,
                'conversation_id' => $conversationId,
                'from_user_id' => $fromUserId,
                'content' => $content,
                'message_type' => $messageType,
                'status' => 'SENT',
                'created_at' => $timestamp
            ];
            
            // Send to Kafka
            $this->kafkaProducer->publish(
                $payload,
                $conversationId // Key (partition by conversation)
            );
            
            $response->setSuccess(true);
            $response->setMessage("Message sent to queue");
            
            // Construct returned message object
            $msg = new Message();
            $msg->setId($messageId);
            $msg->setConversationId($conversationId);
            $msg->setFromUserId($fromUserId);
            $msg->setContent($content);
            $msg->setMessageType($messageType);
            $msg->setStatus('SENT');
            $msg->setCreatedAt($timestamp);
            
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
            $limit = $request->getLimit() ?: 50;
            $offset = $request->getOffset() ?: 0;
            
            $pdo = $this->database->getConnection();
            $stmt = $pdo->prepare("
                SELECT * FROM messages 
                WHERE conversation_id = :conversation_id 
                ORDER BY created_at DESC 
                LIMIT :limit OFFSET :offset
            ");
            $stmt->bindValue(':conversation_id', $conversationId);
            $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            
            $messages = [];
            while ($row = $stmt->fetch()) {
                $msg = new Message();
                $msg->setId($row['id']);
                $msg->setConversationId($row['conversation_id']);
                $msg->setFromUserId($row['from_user_id']);
                $msg->setContent($row['content']);
                $msg->setMessageType($row['message_type']);
                $msg->setStatus($row['status']);
                $msg->setCreatedAt($row['created_at']);
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
