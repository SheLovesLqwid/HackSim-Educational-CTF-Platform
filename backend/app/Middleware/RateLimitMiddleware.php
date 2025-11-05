<?php

declare(strict_types=1);

namespace HackSim\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;
use Slim\Psr7\Factory\ResponseFactory;

class RateLimitMiddleware
{
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'requests_per_minute' => 60,
            'burst_size' => 10,
        ], $config);
    }

    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $clientIp = $this->getClientIp($request);
        $key = "rate_limit:{$clientIp}";

        $redis = $this->getRedis();
        $responseFactory = new ResponseFactory();

        $current = $redis->get($key) ?? 0;
        $remaining = $this->config['requests_per_minute'] - $current;

        if ($current >= $this->config['requests_per_minute']) {
            $response = $responseFactory->createResponse(429);
            $response = $response->withHeader('X-RateLimit-Limit', (string) $this->config['requests_per_minute']);
            $response = $response->withHeader('X-RateLimit-Remaining', '0');
            $response = $response->withHeader('X-RateLimit-Reset', (string) (time() + 60));
            $response = $response->withHeader('Retry-After', '60');

            $response->getBody()->write(json_encode([
                'error' => 'Rate limit exceeded',
                'message' => 'Too many requests. Please try again later.',
                'retry_after' => 60,
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        }

        $redis->incr($key);
        $redis->expire($key, 60);

        $response = $handler->handle($request);

        $response = $response->withHeader('X-RateLimit-Limit', (string) $this->config['requests_per_minute']);
        $response = $response->withHeader('X-RateLimit-Remaining', (string) max(0, $remaining - 1));
        $response = $response->withHeader('X-RateLimit-Reset', (string) (time() + 60));

        return $response;
    }

    private function getClientIp(Request $request): string
    {
        $serverParams = $request->getServerParams();

        $headers = [
            'CF-Connecting-IP',
            'X-Forwarded-For',
            'X-Real-IP',
            'HTTP_X_FORWARDED_FOR',
            'HTTP_X_REAL_IP',
            'HTTP_CLIENT_IP',
        ];

        foreach ($headers as $header) {
            if ($request->hasHeader($header)) {
                $ips = explode(',', $request->getHeaderLine($header));
                $ip = trim($ips[0]);

                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }

        return $serverParams['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function getRedis()
    {
        static $redis = null;

        if ($redis === null) {
            $redis = new \Redis();
            $redis->connect(
                $_ENV['REDIS_HOST'] ?? 'localhost',
                (int) ($_ENV['REDIS_PORT'] ?? 6379)
            );

            if (!empty($_ENV['REDIS_PASSWORD'])) {
                $redis->auth($_ENV['REDIS_PASSWORD']);
            }

            if (isset($_ENV['REDIS_DB'])) {
                $redis->select((int) $_ENV['REDIS_DB']);
            }
        }

        return $redis;
    }
}