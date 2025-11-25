<?php

namespace Chat4All\Api\Middleware;

use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Psr\Http\Message\ResponseInterface as Response;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Slim\Psr7\Response as SlimResponse;
use Monolog\Logger;

/**
 * Middleware de autenticação JWT
 * Valida o token e adiciona dados do usuário no request
 */
class AuthMiddleware
{
    private string $jwtSecret;
    private Logger $logger;

    public function __construct(string $jwtSecret, Logger $logger)
    {
        $this->jwtSecret = $jwtSecret;
        $this->logger = $logger;
    }

    /**
     * Processar request - validar JWT
     */
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        // Pegar header de autorização
        $authHeader = $request->getHeaderLine('Authorization');

        if (!$authHeader) {
            return $this->unauthorizedResponse('Token não fornecido');
        }

        // Extrair token (formato: "Bearer <token>")
        if (!preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $this->unauthorizedResponse('Formato de token inválido');
        }

        $token = $matches[1];

        try {
            // Decodificar e validar token
            $decoded = JWT::decode($token, new Key($this->jwtSecret, 'HS256'));

            // Log para debug
            $this->logger->info('Token decoded', [
                'decoded' => (array) $decoded
            ]);

            // Verificar se user_id existe no token
            $userId = $decoded->user_id ?? $decoded->sub ?? null;
            $username = $decoded->username ?? $decoded->name ?? null;
            $email = $decoded->email ?? null;

            if (!$userId) {
                $this->logger->error('Token does not contain user_id or sub', [
                    'token_data' => (array) $decoded
                ]);
                return $this->unauthorizedResponse('Token inválido: user_id não encontrado');
            }

            // Adicionar dados do usuário ao request
            $request = $request->withAttribute('user_id', $userId);
            $request = $request->withAttribute('username', $username);
            $request = $request->withAttribute('email', $email);

            $this->logger->info('Request authenticated', [
                'user_id' => $userId,
                'username' => $username
            ]);

            // Continuar processamento
            return $handler->handle($request);
        } catch (\Exception $e) {
            $this->logger->warning('JWT validation failed: ' . $e->getMessage());
            return $this->unauthorizedResponse('Token inválido ou expirado');
        }
    }

    /**
     * Retornar resposta de não autorizado
     */
    private function unauthorizedResponse(string $message): Response
    {
        $response = new SlimResponse();
        $response->getBody()->write(json_encode([
            'error' => 'Unauthorized',
            'message' => $message
        ]));

        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }
}
