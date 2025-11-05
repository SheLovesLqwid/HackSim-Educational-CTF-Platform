<?php

declare(strict_types=1);

namespace HackSim\Controllers;

use HackSim\Services\DatabaseService;
use HackSim\Services\GamificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;

class UserController
{
    private DatabaseService $database;
    private GamificationService $gamification;

    public function __construct(DatabaseService $database, GamificationService $gamification)
    {
        $this->database = $database;
        $this->gamification = $gamification;
    }

    public function getProfile(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $user = $this->database->table('users')->where('id', $userId)->first();
        if (!$user) {
            return $this->errorResponse($response, 'User not found', 404);
        }

        $profile = [
            'id' => $user->id,
            'username' => $user->username,
            'email' => $user->email,
            'avatar_url' => $user->avatar_url,
            'xp_total' => $user->xp_total,
            'level' => $user->level,
            'created_at' => $user->created_at,
            'last_active' => $user->last_active,
        ];

        $response->getBody()->write(json_encode(['user' => $profile]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getStats(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        try {
            $stats = $this->gamification->getUserStats($userId);

            $response->getBody()->write(json_encode(['stats' => $stats]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getProgress(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $progress = $this->database->table('challenge_instances')
                                    ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                    ->where('challenge_instances.user_id', $userId)
                                    ->selectRaw('
                                        challenges.category,
                                        challenges.difficulty,
                                        COUNT(*) as total_attempts,
                                        SUM(CASE WHEN challenge_instances.status = "completed" THEN 1 ELSE 0 END) as completed,
                                        AVG(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE NULL END) as average_score,
                                        MAX(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.completed_at ELSE NULL END) as last_completed
                                    ')
                                    ->groupBy('challenges.category', 'challenges.difficulty')
                                    ->orderBy('challenges.category')
                                    ->orderBy('challenges.difficulty')
                                    ->get();

        $storylineProgress = $this->database->table('user_storyline_progress')
                                             ->join('storylines', 'user_storyline_progress.storyline_id', '=', 'storylines.id')
                                             ->where('user_storyline_progress.user_id', $userId)
                                             ->orderBy('storylines.chapter_order')
                                             ->get();

        $response->getBody()->write(json_encode([
            'challenge_progress' => $progress->toArray(),
            'storyline_progress' => $storylineProgress->toArray(),
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getAchievements(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        try {
            $achievements = $this->gamification->getUserAchievements($userId);

            $response->getBody()->write(json_encode(['achievements' => $achievements]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function updateProfile(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $data = json_decode($request->getBody()->getContents(), true);

        if (!isset($data['username'])) {
            return $this->errorResponse($response, 'Username is required', 400);
        }

        if (strlen($data['username']) < 3 || strlen($data['username']) > 50) {
            return $this->errorResponse($response, 'Username must be between 3 and 50 characters', 400);
        }

        $existingUser = $this->database->table('users')
                                       ->where('username', $data['username'])
                                       ->where('id', '!=', $userId)
                                       ->first();

        if ($existingUser) {
            return $this->errorResponse($response, 'Username already taken', 409);
        }

        $this->database->table('users')
                       ->where('id', $userId)
                       ->update([
                           'username' => $data['username'],
                           'updated_at' => date('Y-m-d H:i:s'),
                       ]);

        $updatedUser = $this->database->table('users')->where('id', $userId)->first();

        $response->getBody()->write(json_encode([
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $updatedUser->id,
                'username' => $updatedUser->username,
                'email' => $updatedUser->email,
                'avatar_url' => $updatedUser->avatar_url,
                'xp_total' => $updatedUser->xp_total,
                'level' => $updatedUser->level,
            ],
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getSubmissions(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        $page = (int) ($request->getQueryParams()['page'] ?? 1);
        $perPage = (int) ($request->getQueryParams()['per_page'] ?? 20);
        $status = $request->getQueryParams()['status'] ?? null;

        $query = $this->database->table('submissions')
                                ->join('challenge_instances', 'submissions.challenge_instance_id', '=', 'challenge_instances.id')
                                ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                ->where('submissions.user_id', $userId)
                                ->select(
                                    'submissions.*',
                                    'challenges.title as challenge_title',
                                    'challenges.category',
                                    'challenges.difficulty'
                                )
                                ->orderBy('submissions.submitted_at', 'desc');

        if ($status) {
            $query->where('submissions.result', $status);
        }

        $total = $query->count();
        $submissions = $query->offset(($page - 1) * $perPage)
                            ->limit($perPage)
                            ->get();

        $response->getBody()->write(json_encode([
            'data' => $submissions->toArray(),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ],
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    private function errorResponse(Response $response, string $message, int $status): Response
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