<?php

declare(strict_types=1);

use DI\Container;
use Slim\Factory\AppFactory;
use Slim\Middleware\ErrorMiddleware;
use Slim\Middleware\RoutingMiddleware;
use HackSim\Middleware\CORSMiddleware;
use HackSim\Middleware\RateLimitMiddleware;

require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// Create DI container
$container = new Container();

// Set container to create App with on-demand
AppFactory::setContainer($container);

// Create app instance
$app = AppFactory::create();

// Add routing middleware
$app->addRoutingMiddleware();

// Add CORS middleware
$app->add(new CORSMiddleware());

// Add rate limiting middleware
$app->add(new RateLimitMiddleware());

// Add error handling middleware
$errorMiddleware = new ErrorMiddleware(
    $app->getCallableResolver(),
    $app->getResponseFactory(),
    $_ENV['DEBUG'] === 'true',
    true,
    true
);
$app->add($errorMiddleware);

// Register dependencies
require_once __DIR__ . '/../config/container.php';

// Register routes
require_once __DIR__ . '/../routes/api.php';

// Run the app
$app->run();