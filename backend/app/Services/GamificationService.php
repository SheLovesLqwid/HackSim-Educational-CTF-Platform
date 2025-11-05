<?php

declare(strict_types=1);

namespace HackSim\Services;

use HackSim\Services\DatabaseService;
use HackSim\Services\RedisService;

class GamificationService
{
    private DatabaseService $database;
    private RedisService $redis;

    public function __construct(DatabaseService $database, RedisService $redis)
    {
        $this->database = $database;
        $this->redis = $redis;
    }

    public function awardXP(string $userId, int $xpAmount, string $reason = ''): array
    {
        $this->database->transaction(function () use ($userId, $xpAmount, $reason) {
            $this->database->table('users')
                           ->where('id', $userId)
                           ->increment('xp_total', $xpAmount);

            $currentXP = $this->database->table('users')
                                        ->where('id', $userId)
                                        ->value('xp_total');

            $newLevel = $this->calculateLevel($currentXP);
            $currentLevel = $this->database->table('users')
                                           ->where('id', $userId)
                                           ->value('level');

            if ($newLevel > $currentLevel) {
                $this->database->table('users')
                               ->where('id', $userId)
                               ->update(['level' => $newLevel]);

                $this->checkLevelAchievements($userId, $newLevel);
            }

            $this->logXPActivity($userId, $xpAmount, $reason);
        });

        $user = $this->database->table('users')->where('id', $userId)->first();

        $this->redis->delete("user:{$userId}:profile");
        $this->redis->delete("leaderboard:*");

        return [
            'xp_awarded' => $xpAmount,
            'total_xp' => $user->xp_total,
            'level' => $user->level,
            'level_up' => $this->didUserLevelUp($userId),
        ];
    }

    public function checkAchievements(string $userId, array $context = []): array
    {
        $newAchievements = [];

        $achievements = $this->database->table('achievements')->get();

        foreach ($achievements as $achievement) {
            if ($this->hasUserEarnedAchievement($userId, $achievement->id)) {
                continue;
            }

            if ($this->meetsAchievementCriteria($userId, $achievement, $context)) {
                $this->awardAchievement($userId, $achievement);
                $newAchievements[] = $achievement;

                if ($achievement->xp_reward > 0) {
                    $this->awardXP($userId, $achievement->xp_reward, "achievement:{$achievement->name}");
                }
            }
        }

        return $newAchievements;
    }

    public function getUserAchievements(string $userId): array
    {
        $cacheKey = "user:{$userId}:achievements";

        if ($cached = $this->redis->get($cacheKey)) {
            return $cached;
        }

        $achievements = $this->database->table('user_achievements')
                                       ->join('achievements', 'user_achievements.achievement_id', '=', 'achievements.id')
                                       ->where('user_achievements.user_id', $userId)
                                       ->orderBy('user_achievements.earned_at', 'desc')
                                       ->select('achievements.*', 'user_achievements.earned_at')
                                       ->get();

        $result = $achievements->toArray();
        $this->redis->set($cacheKey, $result, 600); // Cache for 10 minutes

        return $result;
    }

    public function getUserStats(string $userId): array
    {
        $cacheKey = "user:{$userId}:stats";

        if ($cached = $this->redis->get($cacheKey)) {
            return $cached;
        }

        $user = $this->database->table('users')->where('id', $userId)->first();

        $challengeStats = $this->database->table('challenge_instances')
                                         ->where('user_id', $userId)
                                         ->selectRaw('
                                             COUNT(*) as total_challenges,
                                             SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed_challenges,
                                             SUM(CASE WHEN status = "completed" THEN score ELSE 0 END) as total_score,
                                             AVG(CASE WHEN status = "completed" THEN score ELSE NULL END) as average_score
                                         ')
                                         ->first();

        $achievementCount = $this->database->table('user_achievements')
                                            ->where('user_id', $userId)
                                            ->count();

        $currentStoryline = $this->database->table('user_storyline_progress')
                                            ->join('storylines', 'user_storyline_progress.storyline_id', '=', 'storylines.id')
                                            ->where('user_storyline_progress.user_id', $userId)
                                            ->where('user_storyline_progress.is_completed', false)
                                            ->orderBy('storylines.chapter_order', 'asc')
                                            ->first();

        $result = [
            'user' => (array) $user,
            'challenge_stats' => (array) $challengeStats,
            'achievement_count' => $achievementCount,
            'current_storyline' => $currentStoryline ? (array) $currentStoryline : null,
            'xp_to_next_level' => $this->getXPToNextLevel($user->xp_total),
            'level_progress' => $this->getLevelProgress($user->xp_total),
        ];

        $this->redis->set($cacheKey, $result, 300); // Cache for 5 minutes

        return $result;
    }

    private function calculateLevel(int $xp): int
    {
        $level = 1;
        $xpRequired = 100;

        while ($xp >= $xpRequired) {
            $level++;
            $xpRequired = $this->getXPRequiredForLevel($level);
        }

        return $level;
    }

    private function getXPRequiredForLevel(int $level): int
    {
        return (int) (100 * pow(1.5, $level - 1));
    }

    private function getXPToNextLevel(int $currentXP): int
    {
        $currentLevel = $this->calculateLevel($currentXP);
        $requiredXP = $this->getXPRequiredForLevel($currentLevel + 1);

        return max(0, $requiredXP - $currentXP);
    }

    private function getLevelProgress(int $currentXP): array
    {
        $currentLevel = $this->calculateLevel($currentXP);
        $previousLevelXP = $this->getXPRequiredForLevel($currentLevel);
        $nextLevelXP = $this->getXPRequiredForLevel($currentLevel + 1);

        $levelRange = $nextLevelXP - $previousLevelXP;
        $progressInLevel = $currentXP - $previousLevelXP;

        return [
            'current_level' => $currentLevel,
            'progress_percentage' => ($progressInLevel / $levelRange) * 100,
            'xp_in_level' => $progressInLevel,
            'xp_required' => $levelRange,
        ];
    }

    private function hasUserEarnedAchievement(string $userId, string $achievementId): bool
    {
        return $this->database->table('user_achievements')
                              ->where('user_id', $userId)
                              ->where('achievement_id', $achievementId)
                              ->exists();
    }

    private function meetsAchievementCriteria(string $userId, object $achievement, array $context): bool
    {
        $criteria = json_decode($achievement->criteria, true);

        return match ($criteria['type']) {
            'first_challenge' => $this->hasCompletedChallenges($userId, 1),
            'level' => $this->getUserLevel($userId) >= $criteria['value'],
            'challenges_completed' => $this->hasCompletedChallenges($userId, $criteria['value']),
            'speed_challenge' => isset($context['completion_time']) && $context['completion_time'] <= $criteria['time_seconds'],
            'perfect_streak' => $this->hasPerfectStreak($userId, $criteria['value']),
            default => false,
        };
    }

    private function hasCompletedChallenges(string $userId, int $count): bool
    {
        return $this->database->table('challenge_instances')
                              ->where('user_id', $userId)
                              ->where('status', 'completed')
                              ->count() >= $count;
    }

    private function getUserLevel(string $userId): int
    {
        return $this->database->table('users')->where('id', $userId)->value('level');
    }

    private function hasPerfectStreak(string $userId, int $requiredStreak): bool
    {
        $recentSubmissions = $this->database->table('submissions')
                                            ->join('challenge_instances', 'submissions.challenge_instance_id', '=', 'challenge_instances.id')
                                            ->where('challenge_instances.user_id', $userId)
                                            ->where('submissions.result', 'correct')
                                            ->orderBy('submissions.submitted_at', 'desc')
                                            ->limit($requiredStreak)
                                            ->pluck('challenge_instances.id')
                                            ->unique();

        if ($recentSubmissions->count() < $requiredStreak) {
            return false;
        }

        foreach ($recentSubmissions as $instanceId) {
            $hasIncorrect = $this->database->table('submissions')
                                           ->where('challenge_instance_id', $instanceId)
                                           ->where('result', '!=', 'correct')
                                           ->exists();

            if ($hasIncorrect) {
                return false;
            }
        }

        return true;
    }

    private function awardAchievement(string $userId, object $achievement): void
    {
        $this->database->table('user_achievements')->insert([
            'id' => \Ramsey\Uuid\Uuid::uuid4()->toString(),
            'user_id' => $userId,
            'achievement_id' => $achievement->id,
            'earned_at' => date('Y-m-d H:i:s'),
        ]);

        $this->redis->delete("user:{$userId}:achievements");
    }

    private function checkLevelAchievements(string $userId, int $newLevel): void
    {
        $achievements = $this->database->table('achievements')
                                       ->whereRaw('JSON_EXTRACT(criteria, "$.type") = "level"')
                                       ->get();

        foreach ($achievements as $achievement) {
            $criteria = json_decode($achievement->criteria, true);
            if ($criteria['value'] <= $newLevel && !$this->hasUserEarnedAchievement($userId, $achievement->id)) {
                $this->awardAchievement($userId, $achievement);
            }
        }
    }

    private function didUserLevelUp(string $userId): bool
    {
        return $this->redis->get("user:{$userId}:level_up") ?? false;
    }

    private function logXPActivity(string $userId, int $xpAmount, string $reason): void
    {
        $logKey = "xp_log:{$userId}:" . date('Y-m-d');
        $logs = $this->redis->get($logKey) ?? [];

        $logs[] = [
            'amount' => $xpAmount,
            'reason' => $reason,
            'timestamp' => time(),
        ];

        $this->redis->set($logKey, $logs, 86400 * 30); // Keep for 30 days
    }
}