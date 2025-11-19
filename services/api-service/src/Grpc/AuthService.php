<?php

namespace Chat4All\Api\Grpc;

use Chat4All\Api\Database\Database;
use Auth\RegisterRequest;
use Auth\RegisterResponse;
use Auth\LoginRequest;
use Auth\LoginResponse;
use Auth\User;
use Monolog\Logger;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService
{
    private Database $database;
    private Logger $logger;
    private string $jwtSecret;

    public function __construct(
        Database $database,
        Logger $logger,
        string $jwtSecret
    ) {
        $this->database = $database;
        $this->logger = $logger;
        $this->jwtSecret = $jwtSecret;
    }

    public function Register(RegisterRequest $request): RegisterResponse
    {
        $response = new RegisterResponse();
        
        try {
            $username = $request->getUsername();
            $email = $request->getEmail() ?: null;
            $phone = $request->getPhone() ?: null;
            $password = $request->getPassword();
            
            if (!$email && !$phone) {
                throw new \Exception("Email or Phone is required");
            }
            
            // Check if user exists
            $identifier = $email ?: $phone;
            $existingUser = $this->database->getUserByEmailOrPhone($identifier);
            if ($existingUser) {
                $response->setSuccess(false);
                $response->setMessage("User already exists");
                return $response;
            }
            
            $passwordHash = password_hash($password, PASSWORD_BCRYPT);
            
            $userData = $this->database->createUser($username, $email, $phone, $passwordHash);
            
            $user = new User();
            $user->setUserId($userData['user_id']);
            $user->setUsername($userData['username']);
            if ($userData['email']) $user->setEmail($userData['email']);
            if ($userData['phone']) $user->setPhone($userData['phone']);
            $user->setCreatedAt($userData['created_at']);
            $user->setStatus($userData['status']);
            
            $response->setSuccess(true);
            $response->setMessage("User registered successfully");
            $response->setUser($user);
            
        } catch (\Exception $e) {
            $this->logger->error("Registration error: " . $e->getMessage());
            $response->setSuccess(false);
            $response->setMessage($e->getMessage());
        }
        
        return $response;
    }

    public function Login(LoginRequest $request): LoginResponse
    {
        $response = new LoginResponse();
        
        try {
            $email = $request->getEmail();
            $phone = $request->getPhone();
            $password = $request->getPassword();
            
            $identifier = $email ?: $phone;
            if (!$identifier) {
                throw new \Exception("Email or Phone is required");
            }
            
            $user = $this->database->getUserByEmailOrPhone($identifier);
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                throw new \Exception("Invalid credentials");
            }
            
            // Generate JWT
            $payload = [
                'iss' => 'chat4all-api',
                'sub' => $user['user_id'],
                'iat' => time(),
                'exp' => time() + 3600, // 1 hour
                'username' => $user['username'],
                'email' => $user['email']
            ];
            
            $token = JWT::encode($payload, $this->jwtSecret, 'HS256');
            
            $userMsg = new User();
            $userMsg->setUserId($user['user_id']);
            $userMsg->setUsername($user['username']);
            if ($user['email']) $userMsg->setEmail($user['email']);
            if ($user['phone']) $userMsg->setPhone($user['phone']);
            $userMsg->setStatus($user['status']);
            
            $response->setSuccess(true);
            $response->setMessage("Login successful");
            $response->setToken($token);
            $response->setUser($userMsg);
            
        } catch (\Exception $e) {
            $this->logger->error("Login error: " . $e->getMessage());
            $response->setSuccess(false);
            $response->setMessage($e->getMessage());
        }
        
        return $response;
    }

    public function ValidateToken(\Auth\ValidateTokenRequest $request): \Auth\ValidateTokenResponse
    {
        $response = new \Auth\ValidateTokenResponse();
        
        try {
            $token = $request->getToken();
            
            $decoded = JWT::decode($token, new \Firebase\JWT\Key($this->jwtSecret, 'HS256'));
            
            $response->setValid(true);
            $response->setUserId($decoded->sub);
            $response->setMessage("Token is valid");
            
        } catch (\Exception $e) {
            $this->logger->warning("Token validation failed: " . $e->getMessage());
            $response->setValid(false);
            $response->setMessage($e->getMessage());
        }
        
        return $response;
    }
}
