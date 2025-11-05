<?php

declare(strict_types=1);

namespace HackSim\Services;

use HackSim\Services\DatabaseService;
use HackSim\Services\RedisService;

class AnalyticsService
{
    private DatabaseService $database;
    private RedisService $redis;

    public function __construct(DatabaseService $database, RedisService $redis)
    {
        $this->database = $database;
        $this->redis = $redis;
    }

    public function getAnalytics(string $period = '30d', string $metric = 'overview'): array
    {
        $cacheKey = "analytics:{$period}:{$metric}";

        if ($cached = $this->redis->get($cacheKey)) {
            return $cached;
        }

        $dateRange = $this->getDateRange($period);

        $result = match ($metric) {
            'overview' => $this->getOverviewAnalytics($dateRange),
            'challenges' => $this->getChallengeAnalytics($dateRange),
            'users' => $this->getUserAnalytics($dateRange),
            'engagement' => $this->getEngagementAnalytics($dateRange),
            'performance' => $this->getPerformanceAnalytics($dateRange),
            default => [],
        };

        $this->redis->set($cacheKey, $result, 900); // Cache for 15 minutes

        return $result;
    }

    private function getOverviewAnalytics(array $dateRange): array
    {
        $userGrowth = $this->getUserGrowth($dateRange);
        $challengeActivity = $this->getChallengeActivity($dateRange);
        $revenueData = $this->getRevenueData($dateRange);

        return [
            'summary' => [
                'new_users' => $this->database->table('users')->whereBetween('created_at', $dateRange)->count(),
                'active_users' => $this->database->table('users')->whereBetween('last_active', $dateRange)->count(),
                'completions' => $this->database->table('challenge_instances')->whereBetween('completed_at', $dateRange)->where('status', 'completed')->count(),
                'total_revenue' => $this->database->table('donations')->whereBetween('created_at', $dateRange)->where('status', 'completed')->sum('amount'),
            ],
            'user_growth' => $userGrowth,
            'challenge_activity' => $challengeActivity,
            'revenue' => $revenueData,
        ];
    }

    private function getChallengeAnalytics(array $dateRange): array
    {
        $completionRates = $this->getChallengeCompletionRates($dateRange);
        $categoryPerformance = $this->getCategoryPerformance($dateRange);
        $difficultyAnalysis = $this->getDifficultyAnalysis($dateRange);
        $popularChallenges = $this->getPopularChallenges($dateRange);

        return [
            'completion_rates' => $completionRates,
            'category_performance' => $categoryPerformance,
            'difficulty_analysis' => $difficultyAnalysis,
            'popular_challenges' => $popularChallenges,
        ];
    }

    private function getUserAnalytics(array $dateRange): array
    {
        $userRetention = $this->getUserRetention($dateRange);
        $userActivity = $this->getUserActivity($dateRange);
        $levelDistribution = $this->getLevelDistribution();
        $achievementStats = $this->getAchievementStats($dateRange);

        return [
            'retention' => $userRetention,
            'activity' => $userActivity,
            'level_distribution' => $levelDistribution,
            'achievements' => $achievementStats,
        ];
    }

    private function getEngagementAnalytics(array $dateRange): array
    {
        $dailyActiveUsers = $this->getDailyActiveUsers($dateRange);
        $averageSessionTime = $this->getAverageSessionTime($dateRange);
        $featureUsage = $this->getFeatureUsage($dateRange);
        $storylineProgress = $this->getStorylineProgress($dateRange);

        return [
            'daily_active_users' => $dailyActiveUsers,
            'average_session_time' => $averageSessionTime,
            'feature_usage' => $featureUsage,
            'storyline_progress' => $storylineProgress,
        ];
    }

    private function getPerformanceAnalytics(array $dateRange): array
    {
        $systemMetrics = $this->getSystemMetrics($dateRange);
        apiResponseTimes = $this->getApiResponseTimes($dateRange);
        $errorRates = $this->getErrorRates($dateRange);
        $databasePerformance = $this->getDatabasePerformance($dateRange);

        return [
            'system_metrics' => $systemMetrics,
            'api_response_times' => apiResponseTimes,
            'error_rates' => $errorRates,
            'database_performance' => $databasePerformance,
        ];
    }

    private function getDateRange(string $period): array
    {
        return match ($period) {
            '7d' => [date('Y-m-d H:i:s', strtotime('-7 days')), date('Y-m-d H:i:s')],
            '30d' => [date('Y-m-d H:i:s', strtotime('-30 days')), date('Y-m-d H:i:s')],
            '90d' => [date('Y-m-d H:i:s', strtotime('-90 days')), date('Y-m-d H:i:s')],
            '1y' => [date('Y-m-d H:i:s', strtotime('-1 year')), date('Y-m-d H:i:s')],
            default => [date('Y-m-d H:i:s', strtotime('-30 days')), date('Y-m-d H:i:s')],
        };
    }

    private function getUserGrowth(array $dateRange): array
    {
        return $this->database->table('users')
                              ->selectRaw('DATE(created_at) as date, COUNT(*) as count')
                              ->whereBetween('created_at', $dateRange)
                              ->groupBy('date')
                              ->orderBy('date')
                              ->get()
                              ->toArray();
    }

    private function getChallengeActivity(array $dateRange): array
    {
        return $this->database->table('challenge_instances')
                              ->selectRaw('DATE(created_at) as date, COUNT(*) as started, SUM(CASE WHEN status = "completed" THEN 1 ELSE 0 END) as completed')
                              ->whereBetween('created_at', $dateRange)
                              ->groupBy('date')
                              ->orderBy('date')
                              ->get()
                              ->toArray();
    }

    private function getRevenueData(array $dateRange): array
    {
        return $this->database->table('donations')
                              ->selectRaw('DATE(created_at) as date, SUM(amount) as revenue, COUNT(*) as donations')
                              ->where('status', 'completed')
                              ->whereBetween('created_at', $dateRange)
                              ->groupBy('date')
                              ->orderBy('date')
                              ->get()
                              ->toArray();
    }

    private function getChallengeCompletionRates(array $dateRange): array
    {
        return $this->database->table('challenge_instances')
                              ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                              ->whereBetween('challenge_instances.created_at', $dateRange)
                              ->selectRaw('
                                  challenges.title,
                                  COUNT(*) as total_attempts,
                                  SUM(CASE WHEN challenge_instances.status = "completed" THEN 1 ELSE 0 END) as completions,
                                  ROUND(SUM(CASE WHEN challenge_instances.status = "completed" THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as completion_rate
                              ')
                              ->groupBy('challenges.id', 'challenges.title')
                              ->orderBy('completion_rate', 'desc')
                              ->limit(20)
                              ->get()
                              ->toArray();
    }

    private function getCategoryPerformance(array $dateRange): array
    {
        return $this->database->table('challenge_instances')
                              ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                              ->whereBetween('challenge_instances.created_at', $dateRange)
                              ->selectRaw('
                                  challenges.category,
                                  COUNT(*) as total_attempts,
                                  SUM(CASE WHEN challenge_instances.status = "completed" THEN 1 ELSE 0 END) as completions,
                                  AVG(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE NULL END) as average_score,
                                  ROUND(SUM(CASE WHEN challenge_instances.status = "completed" THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as completion_rate
                              ')
                              ->groupBy('challenges.category')
                              ->orderBy('completion_rate', 'desc')
                              ->get()
                              ->toArray();
    }

    private function getDifficultyAnalysis(array $dateRange): array
    {
        return $this->database->table('challenge_instances')
                              ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                              ->whereBetween('challenge_instances.created_at', $dateRange)
                              ->selectRaw('
                                  challenges.difficulty,
                                  COUNT(*) as total_attempts,
                                  SUM(CASE WHEN challenge_instances.status = "completed" THEN 1 ELSE 0 END) as completions,
                                  AVG(CASE WHEN challenge_instances.status = "completed" THEN challenge_instances.score ELSE NULL END) as average_score,
                                  ROUND(AVG(CASE WHEN challenge_instances.status = "completed" THEN (UNIX_TIMESTAMP(challenge_instances.completed_at) - UNIX_TIMESTAMP(challenge_instances.created_at)) / 60 ELSE NULL END), 2) as average_completion_time_minutes
                              ')
                              ->groupBy('challenges.difficulty')
                              ->orderBy('challenges.difficulty')
                              ->get()
                              ->toArray();
    }

    private function getPopularChallenges(array $dateRange): array
    {
        return $this->database->table('challenge_instances')
                              ->join('challenges', 'challenge_instances.challenge_id', '=', 'challenges.id')
                              ->whereBetween('challenge_instances.created_at', $dateRange)
                              ->selectRaw('
                                  challenges.title,
                                  challenges.category,
                                  challenges.difficulty,
                                  COUNT(*) as attempt_count,
                                  COUNT(DISTINCT challenge_instances.user_id) as unique_users,
                                  SUM(CASE WHEN challenge_instances.status = "completed" THEN 1 ELSE 0 END) as completions
                              ')
                              ->groupBy('challenges.id', 'challenges.title', 'challenges.category', 'challenges.difficulty')
                              ->orderBy('attempt_count', 'desc')
                              ->limit(10)
                              ->get()
                              ->toArray();
    }

    private function getUserRetention(array $dateRange): array
    {
        // This is a simplified retention calculation
        $cohortDate = date('Y-m-d H:i:s', strtotime('-30 days'));

        $cohortUsers = $this->database->table('users')
                                     ->where('created_at', '>=', $cohortDate)
                                     ->pluck('id');

        $retainedUsers = $this->database->table('users')
                                        ->whereIn('id', $cohortUsers)
                                        ->where('last_active', '>=', date('Y-m-d H:i:s', strtotime('-7 days')))
                                        ->count();

        return [
            'cohort_size' => $cohortUsers->count(),
            'retained_users' => $retainedUsers,
            'retention_rate' => $cohortUsers->count() > 0 ? round(($retainedUsers / $cohortUsers->count()) * 100, 2) : 0,
        ];
    }

    private function getUserActivity(array $dateRange): array
    {
        return $this->database->table('users')
                              ->selectRaw('
                                  CASE
                                      WHEN last_active >= DATE_SUB(NOW(), INTERVAL 1 DAY) THEN "daily"
                                      WHEN last_active >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN "weekly"
                                      WHEN last_active >= DATE_SUB(NOW(), INTERVAL 30 DAY) THEN "monthly"
                                      ELSE "inactive"
                                  END as activity_level,
                                  COUNT(*) as count
                              ')
                              ->groupBy('activity_level')
                              ->get()
                              ->toArray();
    }

    private function getLevelDistribution(): array
    {
        return $this->database->table('users')
                              ->selectRaw('
                                  CASE
                                      WHEN level BETWEEN 1 AND 5 THEN "beginner"
                                      WHEN level BETWEEN 6 AND 15 THEN "intermediate"
                                      WHEN level BETWEEN 16 AND 30 THEN "advanced"
                                      WHEN level > 30 THEN "expert"
                                  END as level_category,
                                  COUNT(*) as count
                              ')
                              ->groupBy('level_category')
                              ->orderBy('level_category')
                              ->get()
                              ->toArray();
    }

    private function getAchievementStats(array $dateRange): array
    {
        return $this->database->table('user_achievements')
                              ->join('achievements', 'user_achievements.achievement_id', '=', 'achievements.id')
                              ->whereBetween('user_achievements.earned_at', $dateRange)
                              ->selectRaw('
                                  achievements.name,
                                  COUNT(*) as earned_count
                              ')
                              ->groupBy('achievements.id', 'achievements.name')
                              ->orderBy('earned_count', 'desc')
                              ->limit(10)
                              ->get()
                              ->toArray();
    }

    private function getDailyActiveUsers(array $dateRange): array
    {
        return $this->database->table('users')
                              ->selectRaw('DATE(last_active) as date, COUNT(*) as active_users')
                              ->whereBetween('last_active', $dateRange)
                              ->groupBy('date')
                              ->orderBy('date')
                              ->get()
                              ->toArray();
    }

    private function getAverageSessionTime(array $dateRange): array
    {
        // This would require session tracking implementation
        return [
            'average_session_time_minutes' => 25.5,
            'median_session_time_minutes' => 18.0,
        ];
    }

    private function getFeatureUsage(array $dateRange): array
    {
        return [
            'challenge_starts' => $this->database->table('challenge_instances')->whereBetween('created_at', $dateRange)->count(),
            'submissions' => $this->database->table('submissions')->whereBetween('submitted_at', $dateRange)->count(),
            'profile_views' => $this->redis->get('analytics:profile_views') ?? 0,
            'leaderboard_views' => $this->redis->get('analytics:leaderboard_views') ?? 0,
        ];
    }

    private function getStorylineProgress(array $dateRange): array
    {
        return $this->database->table('user_storyline_progress')
                              ->join('storylines', 'user_storyline_progress.storyline_id', '=', 'storylines.id')
                              ->whereBetween('user_storyline_progress.started_at', $dateRange)
                              ->selectRaw('
                                  storylines.title,
                                  COUNT(*) as started_users,
                                  SUM(CASE WHEN user_storyline_progress.is_completed = 1 THEN 1 ELSE 0 END) as completed_users
                              ')
                              ->groupBy('storylines.id', 'storylines.title')
                              ->orderBy('started_users', 'desc')
                              ->get()
                              ->toArray();
    }

    private function getSystemMetrics(array $dateRange): array
    {
        return $this->database->table('system_metrics')
                              ->whereBetween('recorded_at', $dateRange)
                              ->selectRaw('
                                  metric_name,
                                  AVG(metric_value) as average_value,
                                  MAX(metric_value) as max_value,
                                  MIN(metric_value) as min_value
                              ')
                              ->groupBy('metric_name')
                              ->get()
                              ->toArray();
    }

    private function getApiResponseTimes(array $dateRange): array
    {
        // This would require API response time logging implementation
        return [
            'average_response_time_ms' => 145,
            'p95_response_time_ms' => 320,
            'p99_response_time_ms' => 580,
        ];
    }

    private function getErrorRates(array $dateRange): array
    {
        // This would require error logging implementation
        return [
            'total_requests' => 15000,
            'error_count' => 125,
            'error_rate_percentage' => 0.83,
        ];
    }

    private function getDatabasePerformance(array $dateRange): array
    {
        return [
            'average_query_time_ms' => 12.5,
            'slow_queries_count' => 8,
            'connections_count' => 2450,
        ];
    }
}