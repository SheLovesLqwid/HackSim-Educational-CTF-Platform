<?php

declare(strict_types=1);

namespace HackSim\Middleware;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as RequestHandler;

class CORSMiddleware
{
    public function __invoke(Request $request, RequestHandler $handler): Response
    {
        $response = $handler->handle($request);

        $origin = $_ENV['CORS_ORIGIN'] ?? '*';

        $response = $response->withHeader('Access-Control-Allow-Origin', $origin);
        $response = $response->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response = $response->withHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
        $response = $response->withHeader('Access-Control-Max-Age', '86400');

        if ($request->getMethod() === 'OPTIONS') {
            return $response->withStatus(204);
        }

        return $response;
    }
}