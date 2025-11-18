<?php

namespace Chat4All\Api\Controller;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\ResponseInterface as Response;
use Chat4All\Api\Database\Database;
use Firebase\JWT\JWT;
use Monolog\Logger;

/**
 * Controller de autenticação
 * Responsável por login e geração de tokens JWT
 */
class AuthController
{
    private Database $database;
    private string $jwtSecret;
    private int $jwtExpiration;
    private Logger $logger;

    public function __construct(
        Database $database,
        string $jwtSecret,
        int $jwtExpiration,
        Logger $logger
    ) {
        $this->database = $database;
        $this->jwtSecret = $jwtSecret;
        $this->jwtExpiration = $jwtExpiration;
        $this->logger = $logger;
    }

    /**
     * POST /v1/auth/login
     * Autentica usuário e retorna JWT token
     * 
     * Body esperado:
     * {
     *   "email": "alice@chat4all.com",
     *   "password": "password123"
     * }
     */
    public function login(Request $request, Response $response): Response
    {
        try {
            // Pegar dados do body
            $data = $request->getParsedBody();
            
            if (!isset($data['email']) || !isset($data['password'])) {
                return $this->errorResponse($response, 'Email e senha são obrigatórios', 400);
            }

            $email = $data['email'];
            $password = $data['password'];

            // Buscar usuário no banco
            $user = $this->database->getUserByEmail($email);

            if (!$user) {
                $this->logger->warning('Login attempt with invalid email', ['email' => $email]);
                return $this->errorResponse($response, 'Credenciais inválidas', 401);
            }

            // Verificar senha
            if (!password_verify($password, $user['password_hash'])) {
                $this->logger->warning('Login attempt with invalid password', ['email' => $email]);
                return $this->errorResponse($response, 'Credenciais inválidas', 401);
            }

            // Gerar JWT token
            $issuedAt = time();
            $expirationTime = $issuedAt + $this->jwtExpiration;

            $payload = [
                'iat' => $issuedAt,
                'exp' => $expirationTime,
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email']
            ];

            $token = JWT::encode($payload, $this->jwtSecret, 'HS256');

            $this->logger->info('User logged in successfully', [
                'user_id' => $user['user_id'],
                'username' => $user['username']
            ]);

            // Retornar token
            $responseData = [
                'success' => true,
                'token' => $token,
                'expires_in' => $this->jwtExpiration,
                'user' => [
                    'user_id' => $user['user_id'],
                    'username' => $user['username'],
                    'email' => $user['email']
                ]
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
        } catch (\Exception $e) {
            $this->logger->error('Login error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro interno do servidor', 500);
        }
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
