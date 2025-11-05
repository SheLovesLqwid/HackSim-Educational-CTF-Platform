<?php

declare(strict_types=1);

namespace HackSim\Controllers;

use HackSim\Services\DatabaseService;
use HackSim\Services\AnalyticsService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Ramsey\Uuid\Uuid;

class AdminController
{
    private DatabaseService $database;
    private AnalyticsService $analytics;

    public function __construct(DatabaseService $database, AnalyticsService $analytics)
    {
        $this->database = $database;
        $this->analytics = $analytics;
    }

    public function getDashboard(Request $request, Response $response): Response
    {
        try {
            $stats = [
                'users' => $this->getUserStats(),
                'challenges' => $this->getChallengeStats(),
                'submissions' => $this->getSubmissionStats(),
                'donations' => $this->getDonationStats(),
                'system' => $this->getSystemStats(),
            ];

            $response->getBody()->write(json_encode(['dashboard' => $stats]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getAnalytics(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $period = $params['period'] ?? '30d';
        $metric = $params['metric'] ?? 'overview';

        try {
            $analytics = $this->analytics->getAnalytics($period, $metric);

            $response->getBody()->write(json_encode(['analytics' => $analytics]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getUsers(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 50);
        $search = $params['search'] ?? null;
        $status = $params['status'] ?? null;

        $query = $this->database->table('users');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('username', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($status === 'active') {
            $query->where('last_active', '>=', date('Y-m-d H:i:s', strtotime('-7 days')));
        } elseif ($status === 'inactive') {
            $query->where('last_active', '<', date('Y-m-d H:i:s', strtotime('-30 days')));
        }

        $total = $query->count();
        $users = $query->select('id', 'username', 'email', 'avatar_url', 'xp_total', 'level', 'created_at', 'last_active')
                       ->orderBy('xp_total', 'desc')
                       ->offset(($page - 1) * $perPage)
                       ->limit($perPage)
                       ->get();

        $response->getBody()->write(json_encode([
            'data' => $users->toArray(),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ],
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function getChallenges(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 50);
        $category = $params['category'] ?? null;
        $difficulty = $params['difficulty'] ?? null;
        $status = $params['status'] ?? null;

        $query = $this->database->table('challenges')
                                ->leftJoin('users', 'challenges.created_by', '=', 'users.id');

        if ($category) {
            $query->where('challenges.category', $category);
        }

        if ($difficulty) {
            $query->where('challenges.difficulty', $difficulty);
        }

        if ($status !== null) {
            $query->where('challenges.is_active', $status === 'active');
        }

        $total = $query->count();
        $challenges = $query->select(
                'challenges.*',
                'users.username as creator_name'
            )
            ->orderBy('challenges.created_at', 'desc')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $response->getBody()->write(json_encode([
            'data' => $challenges->toArray(),
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ],
        ]));

        return $response->withHeader('Content-Type', 'application/json');
    }

    public function createChallenge(Request $request, Response $response): Response
    {
        $data = json_decode($request->getBody()->getContents(), true);
        $adminId = $request->getAttribute('user_id');

        $required = ['title', 'description', 'category', 'difficulty', 'base_score'];
        foreach ($required as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return $this->errorResponse($response, "Field '{$field}' is required", 400);
            }
        }

        try {
            $challengeId = Uuid::uuid4()->toString();

            $this->database->table('challenges')->insert([
                'id' => $challengeId,
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'],
                'difficulty' => $data['difficulty'],
                'base_score' => (int) $data['base_score'],
                'template_data' => json_encode($data['template_data'] ?? []),
                'generator_script' => $data['generator_script'] ?? null,
                'validator_script' => $data['validator_script'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $adminId,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $this->logAdminAction($adminId, 'create_challenge', 'challenge', $challengeId, $data);

            $challenge = $this->database->table('challenges')
                                       ->where('id', $challengeId)
                                       ->first();

            $response->getBody()->write(json_encode([
                'message' => 'Challenge created successfully',
                'challenge' => $challenge,
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function updateChallenge(Request $request, Response $response, array $args): Response
    {
        $challengeId = $args['id'];
        $data = json_decode($request->getBody()->getContents(), true);
        $adminId = $request->getAttribute('user_id');

        $challenge = $this->database->table('challenges')->where('id', $challengeId)->first();
        if (!$challenge) {
            return $this->errorResponse($response, 'Challenge not found', 404);
        }

        try {
            $updateData = [];
            $allowedFields = ['title', 'description', 'category', 'difficulty', 'base_score', 'template_data', 'generator_script', 'validator_script', 'is_active'];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateData[$field] = $field === 'template_data' ? json_encode($data[$field]) : $data[$field];
                }
            }

            $updateData['updated_at'] = date('Y-m-d H:i:s');

            $this->database->table('challenges')
                           ->where('id', $challengeId)
                           ->update($updateData);

            $this->logAdminAction($adminId, 'update_challenge', 'challenge', $challengeId, $updateData);

            $updatedChallenge = $this->database->table('challenges')->where('id', $challengeId)->first();

            $response->getBody()->write(json_encode([
                'message' => 'Challenge updated successfully',
                'challenge' => $updatedChallenge,
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function deleteChallenge(Request $request, Response $response, array $args): Response
    {
        $challengeId = $args['id'];
        $adminId = $request->getAttribute('user_id');

        $challenge = $this->database->table('challenges')->where('id', $challengeId)->first();
        if (!$challenge) {
            return $this->errorResponse($response, 'Challenge not found', 404);
        }

        try {
            $this->database->table('challenges')->where('id', $challengeId)->delete();

            $this->logAdminAction($adminId, 'delete_challenge', 'challenge', $challengeId, ['title' => $challenge->title]);

            $response->getBody()->write(json_encode([
                'message' => 'Challenge deleted successfully',
            ]));

            return $response->withHeader('Content-Type', 'application/json');

        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getSubmissions(Request $request, Response $response): Response
    {
        $params = $request->getQueryParams();
        $page = (int) ($params['page'] ?? 1);
        $perPage = (int) ($params['per_page'] ?? 50);
        $result = $params['result'] ?? null;
        $userId = $params['user_id'] ?? null;

        $query = $this->database->table('submissions')
                                ->join('users', 'submissions.user_id', '=', 'users.id')
                                ->join('challenge_instances', 'submissions.challenge_instance_id', '=', 'challenge_instances.id')
                                ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id');

        if ($result) {
            $query->where('submissions.result', $result);
        }

        if ($userId) {
            $query->where('submissions.user_id', $userId);
        }

        $total = $query->count();
        $submissions = $query->select(
                'submissions.*',
                'users.username',
                'challenges.title as challenge_title',
                'challenges.category',
                'challenges.difficulty'
            )
            ->orderBy('submissions.submitted_at', 'desc')
            ->offset(($page - 1) * $perPage)
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

    private function getUserStats(): array
    {
        return [
            'total' => $this->database->table('users')->count(),
            'active' => $this->database->table('users')->where('last_active', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))->count(),
            'new_this_month' => $this->database->table('users')->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))->count(),
            'average_level' => $this->database->table('users')->avg('level'),
        ];
    }

    private function getChallengeStats(): array
    {
        return [
            'total' => $this->database->table('challenges')->count(),
            'active' => $this->database->table('challenges')->where('is_active', true)->count(),
            'by_category' => $this->database->table('challenges')->selectRaw('category, COUNT(*) as count')->groupBy('category')->get()->toArray(),
            'by_difficulty' => $this->database->table('challenges')->selectRaw('difficulty, COUNT(*) as count')->groupBy('difficulty')->get()->toArray(),
        ];
    }

    private function getSubmissionStats(): array
    {
        return [
            'total' => $this->database->table('submissions')->count(),
            'correct' => $this->database->table('submissions')->where('result', 'correct')->count(),
            'today' => $this->database->table('submissions')->where('submitted_at', '>=', date('Y-m-d H:i:s', strtotime('-1 day')))->count(),
            'this_week' => $this->database->table('submissions')->where('submitted_at', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))->count(),
        ];
    }

    private function getDonationStats(): array
    {
        return [
            'total' => $this->database->table('donations')->sum('amount'),
            'completed' => $this->database->table('donations')->where('status', 'completed')->sum('amount'),
            'this_month' => $this->database->table('donations')->where('status', 'completed')->where('created_at', '>=', date('Y-m-d H:i:s', strtotime('-30 days')))->sum('amount'),
            'count' => $this->database->table('donations')->where('status', 'completed')->count(),
        ];
    }

    private function getSystemStats(): array
    {
        return [
            'uptime' => shell_exec('uptime -p') ?: 'Unknown',
            'disk_usage' => $this->getDiskUsage(),
            'memory_usage' => $this->getMemoryUsage(),
        ];
    }

    private function getDiskUsage(): string
    {
        $output = shell_exec('df -h / | awk \'NR==2{print $5}\'');
        return trim($output) ?: 'Unknown';
    }

    private function getMemoryUsage(): string
    {
        $output = shell_exec('free | grep Mem | awk \'{printf "%.1f%%", $3/$2 * 100.0}\'');
        return trim($output) ?: 'Unknown';
    }

    private function logAdminAction(string $adminId, string $action, string $resourceType, ?string $resourceId, array $details): void
    {
        $this->database->table('admin_logs')->insert([
            'id' => Uuid::uuid4()->toString(),
            'admin_id' => $adminId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'details' => json_encode($details),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
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