<?php

declare(strict_types=1);

namespace HackSim\Controllers;

use HackSim\Services\DatabaseService;
use HackSim\Services\RedisService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class LeaderboardController
{
    private DatabaseService $database;
    private RedisService $redis;

    public function __construct(DatabaseService $database, RedisService $redis)
    {
        $this->database = $database;
        $this->redis = $redis;
    }

    public function getLeaderboard(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $type = $params['type'] ?? 'all-time';
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 50);
        $category = $params['category'] ?? null;

        $cacheKey = "leaderboard:{$type}:" . ($category ? "category:{$category}:" : '') . "page:{$page}:per_page:{$perPage}";

        if ($cached = $this->redis->get($cacheKey)) {
            $response->getBody()->write(json_encode($cached));
            return $response->withHeader('Content-Type', 'application/json');
        }

        try {
            $query = $this->database->table('users')
                                    ->leftJoin('challenge_instances', function ($join) use ($type) {
                                        $join->on('users.id', '=', 'challenge_instances.user_id')
                                             ->where('challenge_instances.status', '=', 'completed');

                                        if ($type === 'weekly') {
                                            $join->where('challenge_instances.completed_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')));
                                        } elseif ($type === 'monthly') {
                                            $join->where('challenge_instances.completed_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')));
                                        }
                                    })
                                    ->leftJoin('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                    ->leftJoin('donations', 'users.id', '=', 'donations.user_id');

            if ($category) {
                $query->where('challenges.category', $category);
            }

            $leaderboard = $query->selectRaw('
                    users.id,
                    users.username,
                    users.avatar_url,
                    users.level,
                    COALESCE(SUM(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE 0 END), 0) as total_score,
                    COALESCE(COUNT(CASE WHEN challenge_instances.status = "completed" THEN 1 END), 0) as completed_challenges,
                    COALESCE(SUM(CASE WHEN donations.status = "completed" THEN donations.amount ELSE 0 END), 0) as total_donations,
                    users.xp_total
                ')
                ->groupBy('users.id', 'users.username', 'users.avatar_url', 'users.level', 'users.xp_total')
                ->orderBy('total_score', 'desc')
                ->orderBy('users.xp_total', 'desc')
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->get();

            $total = $this->getLeaderboardTotal($type, $category);

            $result = [
                'data' => $leaderboard->toArray(),
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
                'type' => $type,
                'updated_at' => date('Y-m-d H:i:s'),
            ];

            $this->redis->set($cacheKey, $result, 300); // Cache for 5 minutes

            $response->getBody()->write(json_encode($result));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getUserRank(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');
        $params = $request->getQueryParams();
        $type = $params['type'] ?? 'all-time';
        $category = $params['category'] ?? null;

        $cacheKey = "user_rank:{$userId}:{$type}:" . ($category ? "category:{$category}" : '');

        if ($cached = $this->redis->get($cacheKey)) {
            $response->getBody()->write(json_encode(['rank' => $cached]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        try {
            $query = $this->database->table('users')
                                    ->leftJoin('challenge_instances', function ($join) use ($type) {
                                        $join->on('users.id', '=', 'challenge_instances.user_id')
                                             ->where('challenge_instances.status', '=', 'completed');

                                        if ($type === 'weekly') {
                                            $join->where('challenge_instances.completed_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')));
                                        } elseif ($type === 'monthly') {
                                            $join->where('challenge_instances.completed_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')));
                                        }
                                    })
                                    ->leftJoin('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                    ->leftJoin('donations', 'users.id', '=', 'donations.user_id');

            if ($category) {
                $query->where('challenges.category', $category);
            }

            $userScore = $query->selectRaw('
                    COALESCE(SUM(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE 0 END), 0) as total_score,
                    COALESCE(SUM(CASE WHEN donations.status = "completed" THEN donations.amount ELSE 0 END), 0) as total_donations
                ')
                ->where('users.id', $userId)
                ->first();

            if (!$userScore) {
                return $this->errorResponse($response, 'User not found', 404);
            }

            $rankQuery = $this->database->table('users')
                                        ->leftJoin('challenge_instances', function ($join) use ($type) {
                                            $join->on('users.id', '=', 'challenge_instances.user_id')
                                                 ->where('challenge_instances.status', '=', 'completed');

                                            if ($type === 'weekly') {
                                                $join->where('challenge_instances.completed_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')));
                                            } elseif ($type === 'monthly') {
                                                $join->where('challenge_instances.completed_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')));
                                            }
                                        })
                                        ->leftJoin('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                        ->leftJoin('donations', 'users.id', '=', 'donations.user_id');

            if ($category) {
                $rankQuery->where('challenges.category', $category);
            }

            $rank = $rankQuery->selectRaw('
                    COUNT(*) + 1 as rank
                ')
                ->havingRaw('
                    COALESCE(SUM(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE 0 END), 0) > ?
                    OR (
                        COALESCE(SUM(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE 0 END), 0) = ?
                        AND (
                            COALESCE(SUM(CASE WHEN donations.status = "completed" THEN donations.amount ELSE 0 END), 0) > ?
                            OR (
                                COALESCE(SUM(CASE WHEN donations.status = "completed" THEN donations.amount ELSE 0 END), 0) = ?
                                AND users.xp_total > ?
                            )
                        )
                    )
                ', [
                    $userScore->total_score,
                    $userScore->total_score,
                    $userScore->total_donations,
                    $userScore->total_donations,
                    $this->database->table('users')->where('id', $userId)->value('xp_total')
                ])
                ->value('rank');

            $rankData = [
                'rank' => $rank ?? 1,
                'total_score' => (int) $userScore->total_score,
                'total_donations' => (float) $userScore->total_donations,
                'type' => $type,
            ];

            $this->redis->set($cacheKey, $rankData, 300); // Cache for 5 minutes

            $response->getBody()->write(json_encode(['rank' => $rankData]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getTopPerformers(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $limit = min((int) ($params['limit'] ?? 10), 100); // Cap at 100

        $cacheKey = "top_performers:limit:{$limit}";

        if ($cached = $this->redis->get($cacheKey)) {
            $response->getBody()->write(json_encode(['performers' => $cached]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        try {
            $performers = $this->database->table('users')
                                         ->leftJoin('challenge_instances', 'users.id', '=', 'challenge_instances.user_id')
                                         ->where('challenge_instances.status', 'completed')
                                         ->selectRaw('
                                             users.id,
                                             users.username,
                                             users.avatar_url,
                                             users.level,
                                             COUNT(CASE WHEN challenge_instances.status = "completed" THEN 1 END) as completed_challenges,
                                             SUM(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE 0 END) as total_score,
                                             users.xp_total
                                         ')
                                         ->groupBy('users.id', 'users.username', 'users.avatar_url', 'users.level', 'users.xp_total')
                                         ->orderBy('total_score', 'desc')
                                         ->orderBy('users.xp_total', 'desc')
                                         ->limit($limit)
                                         ->get();

            $result = $performers->toArray();
            $this->redis->set($cacheKey, $result, 600); // Cache for 10 minutes

            $response->getBody()->write(json_encode(['performers' => $result]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getCategoryStats(Request $request, Response $response): Response
    {
        $cacheKey = 'leaderboard_category_stats';

        if ($cached = $this->redis->get($cacheKey)) {
            $response->getBody()->write(json_encode(['stats' => $cached]));
            return $response->withHeader('Content-Type', 'application/json');
        }

        try {
            $stats = $this->database->table('challenge_instances')
                                    ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                    ->where('challenge_instances.status', 'completed')
                                    ->selectRaw('
                                        challenges.category,
                                        COUNT(*) as total_completions,
                                        COUNT(DISTINCT challenge_instances.user_id) as unique_users,
                                        AVG(challenge_instances.score) as average_score,
                                        MAX(challenge_instances.score) as highest_score
                                    ')
                                    ->groupBy('challenges.category')
                                    ->orderBy('total_completions', 'desc')
                                    ->get();

            $result = $stats->toArray();
            $this->redis->set($cacheKey, $result, 3600); // Cache for 1 hour

            $response->getBody()->write(json_encode(['stats' => $result]));
            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    private function getLeaderboardTotal(string $type, ?string $category): int
    {
        $query = $this->database->table('users')
                                ->leftJoin('challenge_instances', function ($join) use ($type) {
                                    $join->on('users.id', '=', 'challenge_instances.user_id')
                                         ->where('challenge_instances.status', '=', 'completed');

                                    if ($type === 'weekly') {
                                        $join->where('challenge_instances.completed_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')));
                                    } elseif ($type === 'monthly') {
                                        $join->where('challenge_instances.completed_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')));
                                    }
                                })
                                ->leftJoin('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id');

        if ($category) {
            $query->where('challenges.category', $category);
        }

        return $query->whereRaw('COALESCE(SUM(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE 0 END), 0) > 0')
                     ->count();
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