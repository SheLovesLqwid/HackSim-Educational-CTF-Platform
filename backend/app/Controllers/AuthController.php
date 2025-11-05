<?php

declare(strict_types=1);

namespace HackSim\Controllers;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use HackSim\Services\DatabaseService;
use HackSim\Services\RedisService;
use HackSim\Services\DiscordAuthService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;
use Slim\Psr7\Factory\ResponseFactory;

class AuthController
{
    private DatabaseService $database;
    private RedisService $redis;
    private DiscordAuthService $discordAuth;
    private array $jwtConfig;

    public function __construct(
        DatabaseService $database,
        RedisService $redis,
        DiscordAuthService $discordAuth,
        array $jwtConfig
    ) {
        $this->database = $database;
        $this->redis = $redis;
        $this->discordAuth = $discordAuth;
        $this->jwtConfig = $jwtConfig;
    }

    public function getDiscordAuthUrl(Request $request, Response $response): Response
    {
        $state = $this->discordAuth->generateState();
        $this->redis->set("oauth_state:{$state}", $state, 300);

        $authUrl = $this->discordAuth->getAuthorizationUrl($state);

        $response->getBody()->write(json_encode([
            'auth_url' => $authUrl,
            'state' => $state,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function handleDiscordCallback(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);

        if (!isset($data['code'], $data['state'])) {
            return $this->errorResponse($response, 'Missing code or state parameter', 400);
        }

        $storedState = $this->redis->get("oauth_state:{$data['state']}");
        if (!$storedState || !$this->discordAuth->validateState($data['state'], $storedState)) {
            return $this->errorResponse($response, 'Invalid state parameter', 400);
        }

        $this->redis->delete("oauth_state:{$data['state']}");

        try {
            $tokenData = $this->discordAuth->exchangeCodeForToken($data['code']);
            $userData = $this->discordAuth->getUserData($tokenData['access_token']);

            if (!$userData['verified']) {
                return $this->errorResponse($response, 'Discord account must be verified', 400);
            }

            $user = $this->findOrCreateUser($userData);
            $tokens = $this->generateTokens($user);

            $this->storeUserSession($user['id'], $tokens['access_token'], $tokenData['refresh_token']);

            $response->getBody()->write(json_encode([
                'user' => $user,
                'tokens' => $tokens,
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        }
    }

    public function refreshToken(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);

        if (!isset($data['refresh_token'])) {
            return $this->errorResponse($response, 'Missing refresh token', 400);
        }

        try {
            $session = $this->findSessionByRefreshToken($data['refresh_token']);
            if (!$session) {
                return $this->errorResponse($response, 'Invalid refresh token', 401);
            }

            $newTokenData = $this->discordAuth->refreshAccessToken($data['refresh_token']);
            $user = $this->getUserById($session['user_id']);

            $newTokens = $this->generateTokens($user);

            $this->storeUserSession($user['id'], $newTokens['access_token'], $newTokenData['refresh_token']);

            $this->deleteSession($session['id']);

            $response->getBody()->write(json_encode([
                'tokens' => $newTokens,
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 401);
        }
    }

    public function logout(Request $request, Response $response): Response
    {
        $token = $this->extractTokenFromRequest($request);

        if ($token) {
            $payload = $this->validateToken($token);
            if ($payload) {
                $this->deleteUserSessions($payload->sub);
            }
        }

        $response->getBody()->write(json_encode([
            'message' => 'Logged out successfully',
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getCurrentUser(Request $request, Response $response): Response
    {
        $token = $this->extractTokenFromRequest($request);
        if (!$token) {
            return $this->errorResponse($response, 'Missing authorization token', 401);
        }

        $payload = $this->validateToken($token);
        if (!$payload) {
            return $this->errorResponse($response, 'Invalid or expired token', 401);
        }

        $user = $this->getUserById($payload->sub);
        if (!$user) {
            return $this->errorResponse($response, 'User not found', 404);
        }

        $response->getBody()->write(json_encode(['user' => $user]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function findOrCreateUser(array $discordData): array
    {
        $existingUser = $this->database->table('users')
                                       ->where('discord_id', $discordData['discord_id'])
                                       ->first();

        if ($existingUser) {
            $this->database->table('users')
                           ->where('id', $existingUser->id)
                           ->update([
                               'username' => $discordData['global_name'],
                               'email' => $discordData['email'],
                               'avatar_url' => $discordData['avatar_url'],
                               'last_active' => date('Y-m-d H:i:s'),
                               'updated_at' => date('Y-m-d H:i:s'),
                           ]);

            return $this->getUserById($existingUser->id);
        }

        $userId = Uuid::uuid4()->toString();

        $this->database->table('users')->insert([
            'id' => $userId,
            'discord_id' => $discordData['discord_id'],
            'username' => $discordData['global_name'],
            'email' => $discordData['email'],
            'avatar_url' => $discordData['avatar_url'],
            'xp_total' => 0,
            'level' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'last_active' => date('Y-m-d H:i:s'),
        ]);

        return $this->getUserById($userId);
    }

    private function getUserById(string $userId): ?array
    {
        $user = $this->database->table('users')
                               ->where('id', $userId)
                               ->first();

        return $user ? (array) $user : null;
    }

    private function generateTokens(array $user): array
    {
        $accessToken = $this->generateJWT($user);
        $refreshToken = bin2hex(random_bytes(32));

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtConfig['expires_in'],
        ];
    }

    private function generateJWT(array $user): string
    {
        $payload = [
            'iss' => 'hacksim',
            'sub' => $user['id'],
            'iat' => time(),
            'exp' => time() + $this->jwtConfig['expires_in'],
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'level' => $user['level'],
                'xp_total' => $user['xp_total'],
            ],
        ];

        return JWT::encode($payload, $this->jwtConfig['secret'], $this->jwtConfig['algorithm']);
    }

    private function storeUserSession(string $userId, string $accessToken, ?string $refreshToken): void
    {
        $sessionId = Uuid::uuid4()->toString();
        $tokenHash = hash('sha256', $accessToken);
        $expiresAt = date('Y-m-d H:i:s', time() + $this->jwtConfig['expires_in']);

        $this->database->table('user_sessions')->insert([
            'id' => $sessionId,
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        if ($refreshToken) {
            $this->redis->set("refresh_token:{$refreshToken}", [
                'session_id' => $sessionId,
                'user_id' => $userId,
            ], $this->jwtConfig['expires_in'] * 7); // 7 days for refresh token
        }
    }

    private function findSessionByRefreshToken(string $refreshToken): ?array
    {
        $sessionData = $this->redis->get("refresh_token:{$refreshToken}");
        if (!$sessionData) {
            return null;
        }

        $session = $this->database->table('user_sessions')
                                  ->where('id', $sessionData['session_id'])
                                  ->where('expires_at', '>', date('Y-m-d H:i:s'))
                                  ->first();

        return $session ? (array) $session : null;
    }

    private function deleteSession(string $sessionId): void
    {
        $this->database->table('user_sessions')->where('id', $sessionId)->delete();
    }

    private function deleteUserSessions(string $userId): void
    {
        $this->database->table('user_sessions')->where('user_id', $userId)->delete();
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

    private function errorResponse(Response $response, string $message, int $status): Response
    {
        $responseFactory = new ResponseFactory();
        $response = $responseFactory->createResponse($status);
        $response->getBody()->write(json_encode([
            'error' => $message,
            'status' => $status,
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}