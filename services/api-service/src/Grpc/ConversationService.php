<?php

namespace Chat4All\Api\Grpc;

use Chat4All\Api\Database\Database;
use Conversation\CreatePrivateConversationRequest;
use Conversation\CreateGroupRequest;
use Conversation\CreateConversationResponse;
use Conversation\ListConversationsRequest;
use Conversation\ListConversationsResponse;
use Conversation\Conversation;
use Conversation\ConversationSummary;
use Conversation\Member;
use Monolog\Logger;

class ConversationService
{
    private Database $database;
    private Logger $logger;

    public function __construct(Database $database, Logger $logger)
    {
        $this->database = $database;
        $this->logger = $logger;
    }

    public function CreatePrivateConversation(CreatePrivateConversationRequest $request): CreateConversationResponse
    {
        $response = new CreateConversationResponse();
        
        try {
            $userId = $request->getUserId();
            $otherUserId = $request->getOtherUserId();
            
            if (!$userId || !$otherUserId) {
                throw new \Exception("Both User IDs are required");
            }
            
            if ($userId === $otherUserId) {
                throw new \Exception("Cannot create conversation with yourself");
            }
            
            // Check if user exists
            $otherUser = null;
            
            // Check if it's a UUID
            if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $otherUserId)) {
                $otherUser = $this->database->getUserById($otherUserId);
            }
            
            if (!$otherUser) {
                // Try by phone/email
                $otherUser = $this->database->getUserByEmailOrPhone($otherUserId);
            }
            
            if (!$otherUser) {
                throw new \Exception("User not found");
            }
            $otherUserId = $otherUser['user_id'];
            
            // Check if conversation already exists
            $existingId = $this->database->checkPrivateConversationExists($userId, $otherUserId);
            
            if ($existingId) {
                $conversationData = $this->database->getConversationById($existingId);
                $response->setMessage("Conversation already exists");
            } else {
                // Create new conversation
                $conversationId = $this->database->createConversation('private', null, $userId);
                $this->database->addConversationMember($conversationId, $userId, 'owner');
                $this->database->addConversationMember($conversationId, $otherUserId, 'member');
                
                $conversationData = $this->database->getConversationById($conversationId);
                $response->setMessage("Conversation created successfully");
            }
            
            $response->setSuccess(true);
            $response->setConversation($this->mapConversation($conversationData));
            
        } catch (\Exception $e) {
            $this->logger->error("CreatePrivateConversation error: " . $e->getMessage());
            $response->setSuccess(false);
            $response->setMessage($e->getMessage());
        }
        
        return $response;
    }

    public function CreateGroup(CreateGroupRequest $request): CreateConversationResponse
    {
        $response = new CreateConversationResponse();
        
        try {
            $userId = $request->getUserId();
            $groupName = $request->getGroupName();
            $memberIds = $request->getMemberUserIds();
            
            if (!$groupName) {
                throw new \Exception("Group name is required");
            }
            
            // Create group
            $conversationId = $this->database->createConversation('group', $groupName, $userId);
            
            // Add creator
            $this->database->addConversationMember($conversationId, $userId, 'owner');
            
            // Add members
            foreach ($memberIds as $memberId) {
                $memberUser = null;
                
                if (preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $memberId)) {
                    $memberUser = $this->database->getUserById($memberId);
                }
                
                if (!$memberUser) {
                     $memberUser = $this->database->getUserByEmailOrPhone($memberId);
                }
                
                if ($memberUser) {
                    $this->database->addConversationMember($conversationId, $memberUser['user_id'], 'member');
                }
            }
            
            $conversationData = $this->database->getConversationById($conversationId);
            
            $response->setSuccess(true);
            $response->setMessage("Group created successfully");
            $response->setConversation($this->mapConversation($conversationData));
            
        } catch (\Exception $e) {
            $this->logger->error("CreateGroup error: " . $e->getMessage());
            $response->setSuccess(false);
            $response->setMessage($e->getMessage());
        }
        
        return $response;
    }

    public function ListConversations(ListConversationsRequest $request): ListConversationsResponse
    {
        $response = new ListConversationsResponse();
        
        try {
            $userId = $request->getUserId();
            $limit = $request->getLimit() ?: 50;
            
            $conversationsData = $this->database->getUserConversations($userId, $limit);
            
            $conversations = [];
            foreach ($conversationsData as $data) {
                $summary = new ConversationSummary();
                $summary->setConversationId($data['conversation_id']);
                $summary->setType($data['type']);
                
                // For private chats, name should be the other user's name
                if ($data['type'] === 'private') {
                    // We need to fetch the other user's name. 
                    // This is a bit inefficient, ideally getUserConversations should join this.
                    // For now, let's leave it or do a quick fix in mapConversation logic if we had full object
                    // But here we have summary.
                    // Let's assume the frontend handles it or we improve the query later.
                    // Actually, let's fetch the members for this conversation to find the other user
                    $fullConv = $this->database->getConversationById($data['conversation_id']);
                    foreach ($fullConv['members'] as $member) {
                        if ($member['user_id'] !== $userId) {
                            $summary->setName($member['username']);
                            break;
                        }
                    }
                } else {
                    // For groups, fetch the name from the conversation details
                     $fullConv = $this->database->getConversationById($data['conversation_id']);
                     $summary->setName($fullConv['name']);
                }
                
                $summary->setLastMessage($data['last_message_snippet'] ?? '');
                $summary->setLastMessageAt($data['last_message_at'] ?? '');
                // $summary->setUnreadCount(0); // TODO: Implement unread count
                
                $conversations[] = $summary;
            }
            
            $response->setSuccess(true);
            $response->setConversations($conversations);
            
        } catch (\Exception $e) {
            $this->logger->error("ListConversations error: " . $e->getMessage());
            $response->setSuccess(false);
        }
        
        return $response;
    }

    private function mapConversation(array $data): Conversation
    {
        $conversation = new Conversation();
        $conversation->setConversationId($data['conversation_id']);
        $conversation->setType($data['type']);
        $conversation->setName($data['name'] ?? '');
        $conversation->setCreatedBy($data['created_by']);
        $conversation->setCreatedAt($data['created_at']);
        $conversation->setIsActive($data['is_active'] ?? true);
        
        if (isset($data['members'])) {
            $members = [];
            foreach ($data['members'] as $m) {
                $member = new Member();
                $member->setUserId($m['user_id']);
                $member->setUsername($m['username']);
                $member->setRole($m['role']);
                $member->setJoinedAt($m['joined_at']);
                $members[] = $member;
            }
            $conversation->setMembers($members);
        }
        
        return $conversation;
    }
}
