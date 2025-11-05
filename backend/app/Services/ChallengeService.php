<?php

declare(strict_types=1);

namespace HackSim\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Ramsey\Uuid\Uuid;

class ChallengeService
{
    private DatabaseService $database;
    private RedisService $redis;
    private Client $client;
    private array $config;

    public function __construct(DatabaseService $database, RedisService $redis, array $config)
    {
        $this->database = $database;
        $this->redis = $redis;
        $this->config = $config;
        $this->client = new Client([
            'base_uri' => $config['url'],
            'timeout' => $config['timeout'],
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function getChallenges(array $filters = []): array
    {
        $cacheKey = 'challenges:' . md5(serialize($filters));

        if ($cached = $this->redis->get($cacheKey)) {
            return $cached;
        }

        $query = $this->database->table('challenges')->where('is_active', true);

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['difficulty'])) {
            $query->where('difficulty', $filters['difficulty']);
        }

        if (isset($filters['search'])) {
            $search = '%' . $filters['search'] . '%';
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', $search)
                  ->orWhere('description', 'like', $search);
            });
        }

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);

        $total = $query->count();
        $challenges = $query->orderBy('base_score', 'desc')
                           ->offset(($page - 1) * $perPage)
                           ->limit($perPage)
                           ->get();

        $result = [
            'data' => $challenges,
            'pagination' => [
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
            ],
        ];

        $this->redis->set($cacheKey, $result, 300); // Cache for 5 minutes

        return $result;
    }

    public function getChallengeById(string $id): ?array
    {
        $cacheKey = "challenge:{$id}";

        if ($cached = $this->redis->get($cacheKey)) {
            return $cached;
        }

        $challenge = $this->database->table('challenges')
                                    ->where('id', $id)
                                    ->where('is_active', true)
                                    ->first();

        if ($challenge) {
            $this->redis->set($cacheKey, $challenge, 600); // Cache for 10 minutes
        }

        return $challenge;
    }

    public function startChallenge(string $challengeId, string $userId): array
    {
        $challenge = $this->getChallengeById($challengeId);
        if (!$challenge) {
            throw new \RuntimeException('Challenge not found');
        }

        $existingInstance = $this->database->table('challenge_instances')
                                           ->where('challenge_id', $challengeId)
                                           ->where('user_id', $userId)
                                           ->whereIn('status', ['available', 'in_progress'])
                                           ->first();

        if ($existingInstance) {
            return $existingInstance;
        }

        $instanceData = $this->generateChallengeInstance($challenge);
        $instanceId = Uuid::uuid4()->toString();

        $this->database->table('challenge_instances')->insert([
            'id' => $instanceId,
            'challenge_id' => $challengeId,
            'user_id' => $userId,
            'instance_data' => json_encode($instanceData),
            'solution_hash' => $instanceData['solution_hash'],
            'score' => $instanceData['score'],
            'status' => 'available',
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        $instance = $this->database->table('challenge_instances')
                                   ->where('id', $instanceId)
                                   ->first();

        $this->redis->delete("challenges:" . md5(''));

        return $instance;
    }

    public function submitSolution(string $instanceId, string $userId, array $submission): array
    {
        $instance = $this->database->table('challenge_instances')
                                    ->where('id', $instanceId)
                                    ->where('user_id', $userId)
                                    ->first();

        if (!$instance) {
            throw new \RuntimeException('Challenge instance not found');
        }

        if ($instance->status === 'completed') {
            throw new \RuntimeException('Challenge already completed');
        }

        if ($instance->status === 'expired') {
            throw new \RuntimeException('Challenge has expired');
        }

        $this->database->table('challenge_instances')
                       ->where('id', $instanceId)
                       ->update(['status' => 'in_progress']);

        try {
            $result = $this->validateSubmission($instance, $submission);

            $submissionId = Uuid::uuid4()->toString();
            $this->database->table('submissions')->insert([
                'id' => $submissionId,
                'user_id' => $userId,
                'challenge_instance_id' => $instanceId,
                'submission_data' => json_encode($submission),
                'result' => $result['result'],
                'score_earned' => $result['score_earned'],
                'execution_log' => $result['execution_log'] ?? null,
                'submitted_at' => date('Y-m-d H:i:s'),
            ]);

            if ($result['result'] === 'correct') {
                $this->database->table('challenge_instances')
                               ->where('id', $instanceId)
                               ->update([
                                   'status' => 'completed',
                                   'completed_at' => date('Y-m-d H:i:s'),
                               ]);

                $this->redis->delete("user:{$userId}:progress");
                $this->redis->delete("leaderboard:*");
            }

            return [
                'submission_id' => $submissionId,
                'result' => $result['result'],
                'score_earned' => $result['score_earned'],
                'execution_log' => $result['execution_log'] ?? null,
                'is_correct' => $result['result'] === 'correct',
            ];
        } catch (\Exception $e) {
            $this->database->table('challenge_instances')
                           ->where('id', $instanceId)
                           ->update(['status' => 'available']);

            throw new \RuntimeException('Submission validation failed: ' . $e->getMessage());
        }
    }

    private function generateChallengeInstance(array $challenge): array
    {
        try {
            $response = $this->client->post('/challenge/generate', [
                'json' => [
                    'template_data' => json_decode($challenge['template_data'], true),
                    'generator_script' => $challenge['generator_script'],
                    'difficulty' => $challenge['difficulty'],
                    'base_score' => $challenge['base_score'],
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new \RuntimeException("Challenge generation error: " . $data['error']);
            }

            return $data;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);
            $error = $body['error'] ?? 'unknown_error';

            throw new \RuntimeException("Challenge generation error: {$error}");
        }
    }

    private function validateSubmission(array $instance, array $submission): array
    {
        try {
            $response = $this->client->post('/challenge/validate', [
                'json' => [
                    'instance_data' => json_decode($instance['instance_data'], true),
                    'solution_hash' => $instance['solution_hash'],
                    'submission' => $submission,
                    'validator_script' => $this->getValidatorScript($instance->challenge_id),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['error'])) {
                throw new \RuntimeException("Validation error: " . $data['error']);
            }

            return $data;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = json_decode($response->getBody()->getContents(), true);
            $error = $body['error'] ?? 'unknown_error';

            throw new \RuntimeException("Validation error: {$error}");
        }
    }

    private function getValidatorScript(string $challengeId): ?string
    {
        $challenge = $this->database->table('challenges')
                                    ->where('id', $challengeId)
                                    ->first();

        return $challenge->validator_script ?? null;
    }

    public function getChallengeCategories(): array
    {
        $cacheKey = 'challenge_categories';

        if ($cached = $this->redis->get($cacheKey)) {
            return $cached;
        }

        $categories = $this->database->table('challenges')
                                     ->selectRaw('category, COUNT(*) as count')
                                     ->where('is_active', true)
                                     ->groupBy('category')
                                     ->orderBy('count', 'desc')
                                     ->get();

        $result = $categories->toArray();
        $this->redis->set($cacheKey, $result, 3600); // Cache for 1 hour

        return $result;
    }

    public function getUserProgress(string $userId): array
    {
        $cacheKey = "user:{$userId}:progress";

        if ($cached = $this->redis->get($cacheKey)) {
            return $cached;
        }

        $stats = $this->database->table('challenge_instances')
                                ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                ->where('challenge_instances.user_id', $userId)
                                ->selectRaw('
                                    COUNT(*) as total_challenges,
                                    SUM(CASE WHEN challenge_instances.status = "completed" THEN 1 ELSE 0 END) as completed_challenges,
                                    SUM(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE 0 END) as total_score,
                                    AVG(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE NULL END) as average_score
                                ')
                                ->first();

        $byCategory = $this->database->table('challenge_instances')
                                     ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                     ->where('challenge_instances.user_id', $userId)
                                     ->where('challenge_instances.status', 'completed')
                                     ->selectRaw('
                                         challenges.category,
                                         COUNT(*) as completed,
                                         SUM(challenge_instances.score) as total_score
                                     ')
                                     ->groupBy('challenges.category')
                                     ->get();

        $byDifficulty = $this->database->table('challenge_instances')
                                       ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                                       ->where('challenge_instances.user_id', $userId)
                                       ->where('challenge_instances.status', 'completed')
                                       ->selectRaw('
                                           challenges.difficulty,
                                           COUNT(*) as completed,
                                           SUM(challenge_instances.score) as total_score
                                       ')
                                       ->groupBy('challenges.difficulty')
                                       ->get();

        $result = [
            'stats' => (array) $stats,
            'by_category' => $byCategory->toArray(),
            'by_difficulty' => $byDifficulty->toArray(),
        ];

        $this->redis->set($cacheKey, $result, 300); // Cache for 5 minutes

        return $result;
    }
}