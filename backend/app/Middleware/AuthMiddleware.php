<?php

declare(strict_types=1);

namespace HackSim\Middleware;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use HackSim\Services\DatabaseService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class AuthMiddleware
{
    private DatabaseService $database;
    private array $jwtConfig;

    public function __construct(DatabaseService $database, array $jwtConfig)
    {
        $this->database = $database;
        $this->jwtConfig = $jwtConfig;
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $token = $this->extractTokenFromRequest($request);

        if (!$token) {
            return $this->errorResponse('Missing authorization token', 401);
        }

        $payload = $this->validateToken($token);
        if (!$payload) {
            return $this->errorResponse('Invalid or expired token', 401);
        }

        $request = $request->withAttribute('user_id', $payload->sub);
        $request = $request->withAttribute('user_data', $payload->user);

        return $handler->handle($request);
    }

    private function extractTokenFromRequest(Request $request): ?string
    {
        $authHeader = $request->getHeaderLine('Authorization');
        if (empty($authHeader)) {
            return null;
        }

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    private function validateToken(string $token): ?object
    {
        try {
            $payload = JWT::decode($token, new Key($this->jwtConfig['secret'], $this->jwtConfig['algorithm']));

            $tokenHash = hash('sha256', $token);
            $session = $this->database->table('user_sessions')
                                      ->where('user_id', $payload->sub)
                                      ->where('token_hash', $tokenHash)
                                      ->where('expires_at', '>', date('Y-m-d H:i:s'))
                                      ->first();

            return $session ? $payload : null;

        } catch (\Exception $e) {
            return null;
        }
    }

    private function errorResponse(string $message, int $status): Response
    {
        $responseFactory = new \Slim\Psr7\Factory\ResponseFactory();
        $response = $responseFactory->createResponse($status);
        $response->getBody()->write(json_encode([
            'error' => $message,
            'status' => $status,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}