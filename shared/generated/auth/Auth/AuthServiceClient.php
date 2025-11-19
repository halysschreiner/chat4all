<?php
// GENERATED CODE -- DO NOT EDIT!

namespace Auth;

/**
 * Serviço de Autenticação
 */
class AuthServiceClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Registrar novo usuário
     * @param \Auth\RegisterRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Auth\RegisterResponse>
     */
    public function Register(\Auth\RegisterRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/auth.AuthService/Register',
        $argument,
        ['\Auth\RegisterResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Login de usuário
     * @param \Auth\LoginRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Auth\LoginResponse>
     */
    public function Login(\Auth\LoginRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/auth.AuthService/Login',
        $argument,
        ['\Auth\LoginResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Validar token JWT
     * @param \Auth\ValidateTokenRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Auth\ValidateTokenResponse>
     */
    public function ValidateToken(\Auth\ValidateTokenRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/auth.AuthService/ValidateToken',
        $argument,
        ['\Auth\ValidateTokenResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Obter informações do usuário
     * @param \Auth\GetUserRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall<\Auth\GetUserResponse>
     */
    public function GetUser(\Auth\GetUserRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/auth.AuthService/GetUser',
        $argument,
        ['\Auth\GetUserResponse', 'decode'],
        $metadata, $options);
    }

}
