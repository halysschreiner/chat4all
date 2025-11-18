<?php
/**
 * Conversation Service - Servidor gRPC
 * Responsável por gerenciamento de conversas (privadas e grupos)
 */

require __DIR__ . '/../vendor/autoload.php';

use Conversation\ConversationServiceInterface;
use Conversation\CreatePrivateConversationRequest;
use Conversation\CreateGroupRequest;
use Conversation\CreateConversationResponse;
use Conversation\AddMembersRequest;
use Conversation\AddMembersResponse;
use Conversation\ListConversationsRequest;
use Conversation\ListConversationsResponse;
use Conversation\GetConversationRequest;
use Conversation\GetConversationResponse;
use Conversation\Conversation;
use Conversation\Member;
use Conversation\ConversationSummary;

class ConversationServiceImpl implements ConversationServiceInterface
{
    private PDO $db;
    private Redis $redis;

    public function __construct()
    {
        // Conexão PostgreSQL
        $this->db = new PDO(
            sprintf(
                "pgsql:host=%s;port=%s;dbname=%s",
                getenv('DB_HOST'),
                getenv('DB_PORT'),
                getenv('DB_NAME')
            ),
            getenv('DB_USER'),
            getenv('DB_PASSWORD')
        );
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Conexão Redis
        $this->redis = new Redis();
        $this->redis->connect(getenv('REDIS_HOST'), getenv('REDIS_PORT'));
    }

    /**
     * Criar conversa privada entre dois usuários
     */
    public function CreatePrivateConversation(CreatePrivateConversationRequest $request): CreateConversationResponse
    {
        $response = new CreateConversationResponse();

        try {
            $userId = $request->getUserId();
            $otherUserId = $request->getOtherUserId();

            // Verificar se já existe uma conversa privada entre os dois usuários
            $stmt = $this->db->prepare("
                SELECT c.conversation_id 
                FROM conversations c
                INNER JOIN conversation_members cm1 ON c.conversation_id = cm1.conversation_id
                INNER JOIN conversation_members cm2 ON c.conversation_id = cm2.conversation_id
                WHERE c.type = 'private'
                AND cm1.user_id = ?
                AND cm2.user_id = ?
            ");
            $stmt->execute([$userId, $otherUserId]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($existing) {
                // Retornar conversa existente
                return $this->GetConversation(
                    (new GetConversationRequest())
                        ->setConversationId($existing['conversation_id'])
                        ->setUserId($userId)
                );
            }

            // Iniciar transação
            $this->db->beginTransaction();

            // Criar nova conversa
            $stmt = $this->db->prepare(
                "INSERT INTO conversations (type, created_by) VALUES ('private', ?) RETURNING conversation_id, type, created_at"
            );
            $stmt->execute([$userId]);
            $conversationData = $stmt->fetch(PDO::FETCH_ASSOC);

            // Adicionar membros
            $stmt = $this->db->prepare(
                "INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, ?)"
            );
            $stmt->execute([$conversationData['conversation_id'], $userId, 'owner']);
            $stmt->execute([$conversationData['conversation_id'], $otherUserId, 'member']);

            $this->db->commit();

            // Buscar dados completos da conversa
            $conversation = $this->loadConversationData($conversationData['conversation_id']);

            $response->setSuccess(true);
            $response->setMessage('Conversa criada com sucesso');
            $response->setConversation($conversation);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $response->setSuccess(false);
            $response->setMessage('Erro ao criar conversa: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Criar grupo
     */
    public function CreateGroup(CreateGroupRequest $request): CreateConversationResponse
    {
        $response = new CreateConversationResponse();

        try {
            $userId = $request->getUserId();
            $groupName = $request->getGroupName();
            $memberIds = iterator_to_array($request->getMemberUserIds());

            // Validar
            if (empty($groupName)) {
                $response->setSuccess(false);
                $response->setMessage('Nome do grupo é obrigatório');
                return $response;
            }

            // Iniciar transação
            $this->db->beginTransaction();

            // Criar grupo
            $stmt = $this->db->prepare(
                "INSERT INTO conversations (type, name, created_by) VALUES ('group', ?, ?) RETURNING conversation_id"
            );
            $stmt->execute([$groupName, $userId]);
            $conversationId = $stmt->fetchColumn();

            // Adicionar criador como owner
            $stmt = $this->db->prepare(
                "INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, 'owner')"
            );
            $stmt->execute([$conversationId, $userId]);

            // Adicionar outros membros
            if (!empty($memberIds)) {
                $stmt = $this->db->prepare(
                    "INSERT INTO conversation_members (conversation_id, user_id, role) VALUES (?, ?, 'member')"
                );
                foreach ($memberIds as $memberId) {
                    if ($memberId !== $userId) {
                        $stmt->execute([$conversationId, $memberId]);
                    }
                }
            }

            $this->db->commit();

            // Buscar dados completos
            $conversation = $this->loadConversationData($conversationId);

            $response->setSuccess(true);
            $response->setMessage('Grupo criado com sucesso');
            $response->setConversation($conversation);

        } catch (Exception $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $response->setSuccess(false);
            $response->setMessage('Erro ao criar grupo: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Adicionar membros a um grupo
     */
    public function AddMembers(AddMembersRequest $request): AddMembersResponse
    {
        $response = new AddMembersResponse();

        try {
            $conversationId = $request->getConversationId();
            $userIds = iterator_to_array($request->getUserIds());
            $addedByUserId = $request->getAddedByUserId();

            // Verificar se quem está adicionando é admin/owner
            $stmt = $this->db->prepare(
                "SELECT role FROM conversation_members WHERE conversation_id = ? AND user_id = ?"
            );
            $stmt->execute([$conversationId, $addedByUserId]);
            $role = $stmt->fetchColumn();

            if (!in_array($role, ['owner', 'admin'])) {
                $response->setSuccess(false);
                $response->setMessage('Apenas admins podem adicionar membros');
                return $response;
            }

            // Adicionar membros
            $stmt = $this->db->prepare(
                "INSERT INTO conversation_members (conversation_id, user_id, role) 
                 VALUES (?, ?, 'member') 
                 ON CONFLICT (conversation_id, user_id) DO NOTHING"
            );

            foreach ($userIds as $userId) {
                $stmt->execute([$conversationId, $userId]);
            }

            $response->setSuccess(true);
            $response->setMessage('Membros adicionados com sucesso');

        } catch (Exception $e) {
            $response->setSuccess(false);
            $response->setMessage('Erro ao adicionar membros: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Listar conversas do usuário
     */
    public function ListConversations(ListConversationsRequest $request): ListConversationsResponse
    {
        $response = new ListConversationsResponse();

        try {
            $userId = $request->getUserId();
            $limit = $request->getLimit() ?: 50;
            $offset = $request->getOffset() ?: 0;

            // Buscar conversas do usuário
            $stmt = $this->db->prepare("
                SELECT 
                    c.conversation_id,
                    c.type,
                    c.name,
                    c.updated_at,
                    (
                        SELECT COUNT(*) 
                        FROM messages m 
                        WHERE m.conversation_id = c.conversation_id 
                        AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01')
                    ) as unread_count
                FROM conversations c
                INNER JOIN conversation_members cm ON c.conversation_id = cm.conversation_id
                WHERE cm.user_id = ?
                AND c.is_active = true
                ORDER BY c.updated_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
            $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($conversations as $conv) {
                $summary = new ConversationSummary();
                $summary->setConversationId($conv['conversation_id']);
                $summary->setType($conv['type']);
                $summary->setName($conv['name'] ?? '');
                $summary->setUnreadCount($conv['unread_count']);

                // Buscar última mensagem
                $stmt = $this->db->prepare(
                    "SELECT content, created_at FROM messages WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 1"
                );
                $stmt->execute([$conv['conversation_id']]);
                $lastMsg = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($lastMsg) {
                    $summary->setLastMessage($lastMsg['content']);
                    $summary->setLastMessageAt($lastMsg['created_at']);
                }

                // Buscar membros
                $members = $this->loadMembers($conv['conversation_id']);
                foreach ($members as $member) {
                    $summary->addMembers($member);
                }

                $response->addConversations($summary);
            }

            $response->setSuccess(true);

        } catch (Exception $e) {
            $response->setSuccess(false);
        }

        return $response;
    }

    /**
     * Obter detalhes de uma conversa
     */
    public function GetConversation(GetConversationRequest $request): GetConversationResponse
    {
        $response = new GetConversationResponse();

        try {
            $conversationId = $request->getConversationId();
            $userId = $request->getUserId();

            // Verificar se usuário é membro
            $stmt = $this->db->prepare(
                "SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?"
            );
            $stmt->execute([$conversationId, $userId]);
            
            if (!$stmt->fetch()) {
                $response->setSuccess(false);
                $response->setMessage('Usuário não é membro desta conversa');
                return $response;
            }

            $conversation = $this->loadConversationData($conversationId);

            $response->setSuccess(true);
            $response->setConversation($conversation);

        } catch (Exception $e) {
            $response->setSuccess(false);
            $response->setMessage('Erro ao buscar conversa: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Carregar dados completos de uma conversa
     */
    private function loadConversationData(string $conversationId): Conversation
    {
        $stmt = $this->db->prepare(
            "SELECT conversation_id, type, name, created_by, created_at, is_active FROM conversations WHERE conversation_id = ?"
        );
        $stmt->execute([$conversationId]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        $conversation = new Conversation();
        $conversation->setConversationId($data['conversation_id']);
        $conversation->setType($data['type']);
        $conversation->setName($data['name'] ?? '');
        $conversation->setCreatedBy($data['created_by']);
        $conversation->setCreatedAt($data['created_at']);
        $conversation->setIsActive($data['is_active']);

        // Carregar membros
        $members = $this->loadMembers($conversationId);
        foreach ($members as $member) {
            $conversation->addMembers($member);
        }

        return $conversation;
    }

    /**
     * Carregar membros de uma conversa
     */
    private function loadMembers(string $conversationId): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                u.user_id,
                u.username,
                cm.role,
                cm.joined_at
            FROM conversation_members cm
            INNER JOIN users u ON cm.user_id = u.user_id
            WHERE cm.conversation_id = ?
        ");
        $stmt->execute([$conversationId]);
        $membersData = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $members = [];
        foreach ($membersData as $data) {
            $member = new Member();
            $member->setUserId($data['user_id']);
            $member->setUsername($data['username']);
            $member->setRole($data['role']);
            $member->setJoinedAt($data['joined_at']);
            $members[] = $member;
        }

        return $members;
    }
}

// Iniciar servidor gRPC
$server = new \Grpc\RpcServer();
$server->addHttp2Port('0.0.0.0:' . getenv('GRPC_PORT'));
$server->handle(new ConversationServiceImpl());

echo "Conversation Service rodando na porta " . getenv('GRPC_PORT') . "\n";
$server->run();