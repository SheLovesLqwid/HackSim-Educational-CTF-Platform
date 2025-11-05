<?php

declare(strict_types=1);

use Slim\App;
use HackSim\Controllers\AuthController;
use HackSim\Controllers\UserController;
use HackSim\Controllers\ChallengeController;
use HackSim\Controllers\LeaderboardController;
use HackSim\Controllers\AdminController;
use HackSim\Middleware\AuthMiddleware;

return function (App $app) {
    $container = $app->getContainer();

    // Authentication Routes
    $app->group('/api/auth', function ($group) {
        $group->get('/discord-url', AuthController::class . ':getDiscordAuthUrl');
        $group->post('/callback', AuthController::class . ':handleDiscordCallback');
        $group->post('/refresh', AuthController::class . ':refreshToken');
        $group->post('/logout', AuthController::class . ':logout');
        $group->get('/me', AuthController::class . ':getCurrentUser');
    });

    // User Routes (Protected)
    $app->group('/api/users', function ($group) {
        $group->get('/profile', UserController::class . ':getProfile');
        $group->put('/profile', UserController::class . ':updateProfile');
        $group->get('/stats', UserController::class . ':getStats');
        $group->get('/progress', UserController::class . ':getProgress');
        $group->get('/achievements', UserController::class . ':getAchievements');
        $group->get('/submissions', UserController::class . ':getSubmissions');
    })->add(new AuthMiddleware($container->get(DatabaseService::class), $container->get('settings')['jwt']));

    // Challenge Routes (Mixed)
    $app->group('/api/challenges', function ($group) {
        // Public routes
        $group->get('', ChallengeController::class . ':getChallenges');
        $group->get('/categories', ChallengeController::class . ':getCategories');
        $group->get('/{id}', ChallengeController::class . ':getChallengeById');

        // Protected routes
        $group->post('/{id}/start', ChallengeController::class . ':startChallenge');
        $group->post('/{id}/submit', ChallengeController::class . ':submitSolution');
        $group->get('/instances/{id}', ChallengeController::class . ':getChallengeInstance');
        $group->get('/user/progress', ChallengeController::class . ':getUserProgress');
    })->add(new AuthMiddleware($container->get(DatabaseService::class), $container->get('settings')['jwt']));

    // Leaderboard Routes (Public)
    $app->group('/api/leaderboard', function ($group) {
        $group->get('', LeaderboardController::class . ':getLeaderboard');
        $group->get('/top', LeaderboardController::class . ':getTopPerformers');
        $group->get('/stats', LeaderboardController::class . ':getCategoryStats');

        // Protected route
        $group->get('/rank', LeaderboardController::class . ':getUserRank');
    })->add(new AuthMiddleware($container->get(DatabaseService::class), $container->get('settings')['jwt']));

    // Admin Routes (Protected)
    $app->group('/api/admin', function ($group) {
        $group->get('/dashboard', AdminController::class . ':getDashboard');
        $group->get('/analytics', AdminController::class . ':getAnalytics');

        // User management
        $group->get('/users', AdminController::class . ':getUsers');

        // Challenge management
        $group->get('/challenges', AdminController::class . ':getChallenges');
        $group->post('/challenges', AdminController::class . ':createChallenge');
        $group->put('/challenges/{id}', AdminController::class . ':updateChallenge');
        $group->delete('/challenges/{id}', AdminController::class . ':deleteChallenge');

        // Submission monitoring
        $group->get('/submissions', AdminController::class . ':getSubmissions');
    })->add(new AuthMiddleware($container->get(DatabaseService::class), $container->get('settings')['jwt']));

    // Health Check Route
    $app->get('/api/health', function ($request, $response, $args) use ($container) {
        $status = [
            'status' => 'healthy',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => $container->get('settings')['version'],
            'environment' => $container->get('settings')['debug'] ? 'development' : 'production',
        ];

        // Check database connection
        try {
            $container->get(DatabaseService::class)->getPdo()->query('SELECT 1');
            $status['database'] = 'connected';
        } catch (\Exception $e) {
            $status['database'] = 'error';
            $status['status'] = 'unhealthy';
        }

        // Check Redis connection
        try {
            $container->get(RedisService::class)->getRedis()->ping();
            $status['redis'] = 'connected';
        } catch (\Exception $e) {
            $status['redis'] = 'error';
            $status['status'] = 'unhealthy';
        }

        $response->getBody()->write(json_encode($status));
        return $response->withHeader('Content-Type', 'application/json')
                       ->withStatus($status['status'] === 'healthy' ? 200 : 503);
    });

    // API Documentation Route
    $app->get('/api/docs', function ($request, $response, $args) {
        $docs = [
            'title' => 'HackSim CTF Platform API',
            'version' => '1.0.0',
            'description' => 'RESTful API for the HackSim CTF educational platform',
            'base_url' => $_ENV['APP_URL'] ?? 'http://localhost:8000',
            'endpoints' => [
                'authentication' => [
                    'GET /api/auth/discord-url' => 'Get Discord OAuth authorization URL',
                    'POST /api/auth/callback' => 'Handle Discord OAuth callback',
                    'POST /api/auth/refresh' => 'Refresh JWT token',
                    'POST /api/auth/logout' => 'Logout user',
                    'GET /api/auth/me' => 'Get current user info',
                ],
                'users' => [
                    'GET /api/users/profile' => 'Get user profile',
                    'PUT /api/users/profile' => 'Update user profile',
                    'GET /api/users/stats' => 'Get user statistics',
                    'GET /api/users/progress' => 'Get user progress',
                    'GET /api/users/achievements' => 'Get user achievements',
                    'GET /api/users/submissions' => 'Get user submissions',
                ],
                'challenges' => [
                    'GET /api/challenges' => 'List challenges',
                    'GET /api/challenges/categories' => 'Get challenge categories',
                    'GET /api/challenges/{id}' => 'Get challenge details',
                    'POST /api/challenges/{id}/start' => 'Start a challenge',
                    'POST /api/challenges/{id}/submit' => 'Submit solution',
                    'GET /api/challenges/user/progress' => 'Get user challenge progress',
                ],
                'leaderboard' => [
                    'GET /api/leaderboard' => 'Get leaderboard',
                    'GET /api/leaderboard/top' => 'Get top performers',
                    'GET /api/leaderboard/stats' => 'Get leaderboard stats',
                    'GET /api/leaderboard/rank' => 'Get user rank',
                ],
                'admin' => [
                    'GET /api/admin/dashboard' => 'Get admin dashboard',
                    'GET /api/admin/analytics' => 'Get platform analytics',
                    'GET /api/admin/users' => 'User management',
                    'GET /api/admin/challenges' => 'Challenge management',
                    'POST /api/admin/challenges' => 'Create challenge',
                    'PUT /api/admin/challenges/{id}' => 'Update challenge',
                    'DELETE /api/admin/challenges/{id}' => 'Delete challenge',
                    'GET /api/admin/submissions' => 'Submission monitoring',
                ],
            ],
        ];

        $response->getBody()->write(json_encode($docs, JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json');
    });

    // Fallback route for undefined API endpoints
    $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/api/{routes:.+}', function ($request, $response) {
        $response->getBody()->write(json_encode([
            'error' => 'Endpoint not found',
            'message' => 'The requested API endpoint does not exist',
            'status' => 404,
        ]));
        return $response->withHeader('Content-Type', 'application/json')
                       ->withStatus(404);
    });
};