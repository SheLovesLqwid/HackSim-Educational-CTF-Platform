<?php

return [
    'name' => 'HackSim CTF Platform',
    'version' => '1.0.0',
    'debug' => filter_var($_ENV['DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'timezone' => 'UTC',
    'charset' => 'UTF-8',

    // Security settings
    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? 'default-secret-change-in-production',
        'expires_in' => (int) ($_ENV['JWT_EXPIRES_IN'] ?? 86400), // 24 hours
        'algorithm' => 'HS256',
    ],

    // Discord OAuth settings
    'discord' => [
        'client_id' => $_ENV['DISCORD_CLIENT_ID'] ?? '',
        'client_secret' => $_ENV['DISCORD_CLIENT_SECRET'] ?? '',
        'redirect_uri' => $_ENV['DISCORD_REDIRECT_URI'] ?? 'http://localhost/oauth/callback',
        'api_url' => 'https://discord.com/api/v10',
    ],

    // Challenge engine settings
    'challenge_engine' => [
        'url' => $_ENV['CHALLENGE_ENGINE_URL'] ?? 'http://challenge-engine:8001',
        'timeout' => (int) ($_ENV['CHALLENGE_ENGINE_TIMEOUT'] ?? 30),
    ],

    // Rate limiting settings
    'rate_limiting' => [
        'requests_per_minute' => (int) ($_ENV['RATE_LIMIT_REQUESTS_PER_MINUTE'] ?? 60),
        'burst_size' => (int) ($_ENV['RATE_LIMIT_BURST_SIZE'] ?? 10),
    ],

    // File upload settings
    'uploads' => [
        'max_size' => (int) ($_ENV['UPLOAD_MAX_SIZE'] ?? 10485760), // 10MB
        'allowed_types' => ['image/jpeg', 'image/png', 'image/gif', 'text/plain'],
        'path' => $_ENV['UPLOAD_PATH'] ?? __DIR__ . '/../uploads',
    ],

    // Cache settings
    'cache' => [
        'prefix' => 'hacksim:',
        'ttl' => (int) ($_ENV['CACHE_TTL'] ?? 3600), // 1 hour
    ],
];