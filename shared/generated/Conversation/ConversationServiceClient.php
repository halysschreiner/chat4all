<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Conversation;

/**
 * Serviço de Conversas
 */
class ConversationServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Criar conversa privada
     * @param \Conversation\CreatePrivateConversationRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function CreatePrivateConversation(\Conversation\CreatePrivateConversationRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/conversation.ConversationService/CreatePrivateConversation',
        $argument,
        ['\Conversation\CreateConversationResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Criar grupo
     * @param \Conversation\CreateGroupRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function CreateGroup(\Conversation\CreateGroupRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/conversation.ConversationService/CreateGroup',
        $argument,
        ['\Conversation\CreateConversationResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Adicionar membros ao grupo
     * @param \Conversation\AddMembersRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function AddMembers(\Conversation\AddMembersRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/conversation.ConversationService/AddMembers',
        $argument,
        ['\Conversation\AddMembersResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Listar conversas do usuário
     * @param \Conversation\ListConversationsRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ListConversations(\Conversation\ListConversationsRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/conversation.ConversationService/ListConversations',
        $argument,
        ['\Conversation\ListConversationsResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Obter detalhes de uma conversa
     * @param \Conversation\GetConversationRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function GetConversation(\Conversation\GetConversationRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/conversation.ConversationService/GetConversation',
        $argument,
        ['\Conversation\GetConversationResponse', 'decode'],
        $metadata, $options);
    }

}
