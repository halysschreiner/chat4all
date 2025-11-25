<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Message;

/**
 * Serviço de Mensagens
 */
class MessageServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Enviar mensagem
     * @param \Message\SendMessageRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function SendMessage(\Message\SendMessageRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/message.MessageService/SendMessage',
        $argument,
        ['\Message\SendMessageResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Listar mensagens de uma conversa
     * @param \Message\ListMessagesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ListMessages(\Message\ListMessagesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/message.MessageService/ListMessages',
        $argument,
        ['\Message\ListMessagesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Marcar mensagem como lida
     * @param \Message\MarkAsReadRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function MarkAsRead(\Message\MarkAsReadRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/message.MessageService/MarkAsRead',
        $argument,
        ['\Message\MarkAsReadResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Atualizar status da mensagem
     * @param \Message\UpdateMessageStatusRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function UpdateMessageStatus(\Message\UpdateMessageStatusRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/message.MessageService/UpdateMessageStatus',
        $argument,
        ['\Message\UpdateMessageStatusResponse', 'decode'],
        $metadata, $options);
    }

}
