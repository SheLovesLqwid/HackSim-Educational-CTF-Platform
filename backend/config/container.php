<?php

declare(strict_types=1);

use DI\ContainerBuilder;
use HackSim\Services\DatabaseService;
use HackSim\Services\RedisService;
use HackSim\Services\DiscordAuthService;
use HackSim\Services\ChallengeService;
use HackSim\Services\GamificationService;
use HackSim\Services\AnalyticsService;
use HackSim\Controllers\AuthController;
use HackSim\Controllers\UserController;
use HackSim\Controllers\ChallengeController;
use HackSim\Controllers\LeaderboardController;
use HackSim\Controllers\AdminController;

$containerBuilder = new ContainerBuilder();

// Add configuration
$containerBuilder->addDefinitions([
    'settings' => require __DIR__ . '/app.php',
    'db_config' => require __DIR__ . '/database.php',
]);

// Database service
$containerBuilder->addDefinitions([
    DatabaseService::class => function ($container) {
        return new DatabaseService($container->get('db_config'));
    },
]);

// Redis service
$containerBuilder->addDefinitions([
    RedisService::class => function ($container) {
        return new RedisService($container->get('settings')['cache'], $container->get('db_config')['redis']);
    },
]);

// Discord auth service
$containerBuilder->addDefinitions([
    DiscordAuthService::class => function ($container) {
        return new DiscordAuthService($container->get('settings')['discord']);
    },
]);

// Challenge service
$containerBuilder->addDefinitions([
    ChallengeService::class => function ($container) {
        return new ChallengeService(
            $container->get(DatabaseService::class),
            $container->get(RedisService::class),
            $container->get('settings')['challenge_engine']
        );
    },
]);

// Gamification service
$containerBuilder->addDefinitions([
    GamificationService::class => function ($container) {
        return new GamificationService(
            $container->get(DatabaseService::class),
            $container->get(RedisService::class)
        );
    },
]);

// Analytics service
$containerBuilder->addDefinitions([
    AnalyticsService::class => function ($container) {
        return new AnalyticsService(
            $container->get(DatabaseService::class),
            $container->get(RedisService::class)
        );
    },
]);

// Controllers
$containerBuilder->addDefinitions([
    AuthController::class => function ($container) {
        return new AuthController(
            $container->get(DatabaseService::class),
            $container->get(RedisService::class),
            $container->get(DiscordAuthService::class),
            $container->get('settings')['jwt']
        );
    },
]);

$containerBuilder->addDefinitions([
    UserController::class => function ($container) {
        return new UserController(
            $container->get(DatabaseService::class),
            $container->get(GamificationService::class)
        );
    },
]);

$containerBuilder->addDefinitions([
    ChallengeController::class => function ($container) {
        return new ChallengeController(
            $container->get(ChallengeService::class),
            $container->get(GamificationService::class)
        );
    },
]);

$containerBuilder->addDefinitions([
    LeaderboardController::class => function ($container) {
        return new LeaderboardController(
            $container->get(DatabaseService::class),
            $container->get(RedisService::class)
        );
    },
]);

$containerBuilder->addDefinitions([
    AdminController::class => function ($container) {
        return new AdminController(
            $container->get(DatabaseService::class),
            $container->get(AnalyticsService::class)
        );
    },
]);

return $containerBuilder->build();