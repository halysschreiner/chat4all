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
     * POST /v1/auth/register
     * Registra um novo usuário
     * 
     * Body esperado:
     * {
     *   "username": "newuser",
     *   "email": "newuser@chat4all.com",
     *   "password": "password123"
     * }
     */
    public function register(Request $request, Response $response): Response
    {
        try {
            $data = $request->getParsedBody();
            
            // Validar campos obrigatórios
            if (!isset($data['username']) || !isset($data['email']) || !isset($data['password'])) {
                return $this->errorResponse($response, 'Username, email e senha são obrigatórios', 400);
            }

            $username = trim($data['username']);
            $email = trim($data['email']);
            $password = $data['password'];

            // Validar email
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return $this->errorResponse($response, 'Email inválido', 400);
            }

            // Validar senha (mínimo 6 caracteres)
            if (strlen($password) < 6) {
                return $this->errorResponse($response, 'A senha deve ter no mínimo 6 caracteres', 400);
            }

            // Validar username (mínimo 3 caracteres, alfanumérico)
            if (strlen($username) < 3 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                return $this->errorResponse($response, 'Username deve ter no mínimo 3 caracteres e conter apenas letras, números e underscore', 400);
            }

            // Verificar se email já existe
            $existingUser = $this->database->getUserByEmail($email);
            if ($existingUser) {
                return $this->errorResponse($response, 'Email já cadastrado', 409);
            }

            // Verificar se username já existe
            $existingUsername = $this->database->getUserByUsername($username);
            if ($existingUsername) {
                return $this->errorResponse($response, 'Username já cadastrado', 409);
            }

            // Criar hash da senha
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);

            // Inserir usuário no banco (email, sem phone)
            $user = $this->database->createUser($username, $email, null, $passwordHash);

            if (!$user) {
                return $this->errorResponse($response, 'Erro ao criar usuário', 500);
            }

            $this->logger->info('New user registered', [
                'user_id' => $user['user_id'],
                'username' => $user['username'],
                'email' => $user['email']
            ]);

            // Gerar JWT token automaticamente
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

            $responseData = [
                'success' => true,
                'message' => 'Usuário criado com sucesso',
                'token' => $token,
                'expires_in' => $this->jwtExpiration,
                'user' => [
                    'user_id' => $user['user_id'],
                    'username' => $user['username'],
                    'email' => $user['email']
                ]
            ];

            $response->getBody()->write(json_encode($responseData));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
        } catch (\Exception $e) {
            $this->logger->error('Registration error: ' . $e->getMessage());
            return $this->errorResponse($response, 'Erro interno do servidor', 500);
        }
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
