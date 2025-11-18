<?php
/**
 * Auth Service - Servidor gRPC
 * Responsável por autenticação e gerenciamento de usuários
 */

require __DIR__ . '/../vendor/autoload.php';

use Auth\AuthServiceInterface;
use Auth\RegisterRequest;
use Auth\RegisterResponse;
use Auth\LoginRequest;
use Auth\LoginResponse;
use Auth\ValidateTokenRequest;
use Auth\ValidateTokenResponse;
use Auth\GetUserRequest;
use Auth\GetUserResponse;
use Auth\User;

class AuthServiceImpl implements AuthServiceInterface
{
    private PDO $db;
    private Redis $redis;

    public function __construct()
    {
        // Conexão com PostgreSQL
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

        // Conexão com Redis
        $this->redis = new Redis();
        $this->redis->connect(getenv('REDIS_HOST'), getenv('REDIS_PORT'));
    }

    /**
     * Registrar novo usuário
     */
    public function Register(RegisterRequest $request): RegisterResponse
    {
        $response = new RegisterResponse();

        try {
            // Validar entrada
            if (empty($request->getUsername()) || empty($request->getEmail()) || empty($request->getPassword())) {
                $response->setSuccess(false);
                $response->setMessage('Todos os campos são obrigatórios');
                return $response;
            }

            // Verificar se email já existe
            $stmt = $this->db->prepare('SELECT user_id FROM users WHERE email = ?');
            $stmt->execute([$request->getEmail()]);
            if ($stmt->fetch()) {
                $response->setSuccess(false);
                $response->setMessage('Email já cadastrado');
                return $response;
            }

            // Verificar se username já existe
            $stmt = $this->db->prepare('SELECT user_id FROM users WHERE username = ?');
            $stmt->execute([$request->getUsername()]);
            if ($stmt->fetch()) {
                $response->setSuccess(false);
                $response->setMessage('Username já cadastrado');
                return $response;
            }

            // Hash da senha
            $passwordHash = password_hash($request->getPassword(), PASSWORD_BCRYPT);

            // Inserir usuário
            $stmt = $this->db->prepare(
                'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?) RETURNING user_id, username, email, created_at, status'
            );
            $stmt->execute([
                $request->getUsername(),
                $request->getEmail(),
                $passwordHash
            ]);

            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            // Criar objeto User
            $user = new User();
            $user->setUserId($userData['user_id']);
            $user->setUsername($userData['username']);
            $user->setEmail($userData['email']);
            $user->setCreatedAt($userData['created_at']);
            $user->setStatus($userData['status']);

            $response->setSuccess(true);
            $response->setMessage('Usuário registrado com sucesso');
            $response->setUser($user);

        } catch (Exception $e) {
            $response->setSuccess(false);
            $response->setMessage('Erro ao registrar usuário: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Login de usuário
     */
    public function Login(LoginRequest $request): LoginResponse
    {
        $response = new LoginResponse();

        try {
            // Buscar usuário por email
            $stmt = $this->db->prepare(
                'SELECT user_id, username, email, password_hash, created_at, status FROM users WHERE email = ?'
            );
            $stmt->execute([$request->getEmail()]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            // Verificar se usuário existe
            if (!$userData) {
                $response->setSuccess(false);
                $response->setMessage('Credenciais inválidas');
                return $response;
            }

            // Verificar senha
            if (!password_verify($request->getPassword(), $userData['password_hash'])) {
                $response->setSuccess(false);
                $response->setMessage('Credenciais inválidas');
                return $response;
            }

            // Gerar JWT token
            $token = $this->generateJWT($userData['user_id'], $userData['email']);

            // Salvar token no Redis (com TTL de 24h)
            $this->redis->setex(
                "session:{$token}",
                86400, // 24 horas
                json_encode([
                    'user_id' => $userData['user_id'],
                    'email' => $userData['email']
                ])
            );

            // Criar objeto User
            $user = new User();
            $user->setUserId($userData['user_id']);
            $user->setUsername($userData['username']);
            $user->setEmail($userData['email']);
            $user->setCreatedAt($userData['created_at']);
            $user->setStatus($userData['status']);

            $response->setSuccess(true);
            $response->setMessage('Login realizado com sucesso');
            $response->setToken($token);
            $response->setUser($user);

        } catch (Exception $e) {
            $response->setSuccess(false);
            $response->setMessage('Erro ao realizar login: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Validar token JWT
     */
    public function ValidateToken(ValidateTokenRequest $request): ValidateTokenResponse
    {
        $response = new ValidateTokenResponse();

        try {
            $token = $request->getToken();

            // Verificar se token existe no Redis
            $sessionData = $this->redis->get("session:{$token}");

            if (!$sessionData) {
                $response->setValid(false);
                $response->setMessage('Token inválido ou expirado');
                return $response;
            }

            $data = json_decode($sessionData, true);

            $response->setValid(true);
            $response->setUserId($data['user_id']);
            $response->setMessage('Token válido');

        } catch (Exception $e) {
            $response->setValid(false);
            $response->setMessage('Erro ao validar token: ' . $e->getMessage());
        }

        return $response;
    }

    /**
     * Obter informações do usuário
     */
    public function GetUser(GetUserRequest $request): GetUserResponse
    {
        $response = new GetUserResponse();

        try {
            $stmt = $this->db->prepare(
                'SELECT user_id, username, email, created_at, status FROM users WHERE user_id = ?'
            );
            $stmt->execute([$request->getUserId()]);
            $userData = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$userData) {
                $response->setSuccess(false);
                return $response;
            }

            $user = new User();
            $user->setUserId($userData['user_id']);
            $user->setUsername($userData['username']);
            $user->setEmail($userData['email']);
            $user->setCreatedAt($userData['created_at']);
            $user->setStatus($userData['status']);

            $response->setSuccess(true);
            $response->setUser($user);

        } catch (Exception $e) {
            $response->setSuccess(false);
        }

        return $response;
    }

    /**
     * Gerar token JWT simples
     * Nota: Em produção, usar biblioteca como firebase/php-jwt
     */
    private function generateJWT(string $userId, string $email): string
    {
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = base64_encode(json_encode([
            'user_id' => $userId,
            'email' => $email,
            'exp' => time() + 86400 // 24 horas
        ]));
        $signature = hash_hmac('sha256', "$header.$payload", 'secret_key_here', true);
        $signature = base64_encode($signature);

        return "$header.$payload.$signature";
    }
}

// Iniciar servidor gRPC
$server = new \Grpc\RpcServer();
$server->addHttp2Port('0.0.0.0:' . getenv('GRPC_PORT'));
$server->handle(new AuthServiceImpl());

echo "Auth Service rodando na porta " . getenv('GRPC_PORT') . "\n";
$server->run();