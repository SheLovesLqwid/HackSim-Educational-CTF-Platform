<?php

declare(strict_types=1);

namespace HackSim\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;

class DiscordAuthService
{
    private Client $client;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->client = new Client([
            'base_uri' => $config['api_url'],
            'timeout' => 30,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
    }

    public function getAuthorizationUrl(string $state): string
    {
        $params = http_build_query([
            'client_id' => $this->config['client_id'],
            'redirect_uri' => $this->config['redirect_uri'],
            'response_type' => 'code',
            'scope' => 'identify email',
            'state' => $state,
        ]);

        return "https://discord.com/oauth2/authorize?{$params}";
    }

    public function exchangeCodeForToken(string $code): array
    {
        try {
            $response = $this->client->post('/oauth2/token', [
                'form_params' => [
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->config['redirect_uri'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new \RuntimeException("Discord OAuth error: " . $data['error']);
            }

            return $data;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);
            $error = $body['error'] ?? 'unknown_error';
            $description = $body['error_description'] ?? 'Unknown error occurred';

            throw new \RuntimeException("Discord OAuth error: {$error} - {$description}");
        }
    }

    public function refreshAccessToken(string $refreshToken): array
    {
        try {
            $response = $this->client->post('/oauth2/token', [
                'form_params' => [
                    'client_id' => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new \RuntimeException("Discord refresh error: " . $data['error']);
            }

            return $data;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);
            $error = $body['error'] ?? 'unknown_error';
            $description = $body['error_description'] ?? 'Unknown error occurred';

            throw new \RuntimeException("Discord refresh error: {$error} - {$description}");
        }
    }

    public function getUserData(string $accessToken): array
    {
        try {
            $response = $this->client->get('/users/@me', [
                'headers' => [
                    'Authorization' => "Bearer {$accessToken}",
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new \RuntimeException("Discord user data error: " . $data['error']);
            }

            return [
                'discord_id' => $data['id'],
                'username' => $data['username'],
                'discriminator' => $data['discriminator'],
                'global_name' => $data['global_name'] ?? $data['username'],
                'email' => $data['email'],
                'verified' => $data['verified'],
                'avatar_url' => $this->getAvatarUrl($data['id'], $data['avatar']),
                'banner_url' => !empty($data['banner']) ? $this->getBannerUrl($data['id'], $data['banner']) : null,
                'accent_color' => $data['accent_color'] ?? null,
            ];
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);
            $error = $body['error'] ?? 'unknown_error';
            $description = $body['error_description'] ?? 'Unknown error occurred';

            throw new \RuntimeException("Discord user data error: {$error} - {$description}");
        }
    }

    private function getAvatarUrl(string $userId, ?string $avatarHash): ?string
    {
        if (empty($avatarHash)) {
            return null;
        }

        $format = str_starts_with($avatarHash, 'a_') ? 'gif' : 'png';
        return "https://cdn.discordapp.com/avatars/{$userId}/{$avatarHash}.{$format}";
    }

    private function getBannerUrl(string $userId, ?string $bannerHash): ?string
    {
        if (empty($bannerHash)) {
            return null;
        }

        $format = str_starts_with($bannerHash, 'a_') ? 'gif' : 'png';
        return "https://cdn.discordapp.com/banners/{$userId}/{$bannerHash}.{$format}";
    }

    public function validateState(string $receivedState, string $storedState): bool
    {
        return hash_equals($storedState, $receivedState);
    }

    public function generateState(): string
    {
        return bin2hex(random_bytes(32));
    }
}