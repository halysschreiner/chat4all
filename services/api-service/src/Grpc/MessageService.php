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
                'message_id' => $messageId,
                'conversation_id' => $conversationId,
                'from_user_id' => $fromUserId,
                'content' => $content,
                'message_type' => $messageType,
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
