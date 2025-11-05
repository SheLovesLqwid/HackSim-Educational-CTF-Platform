<?php

declare(strict_types=1);

namespace HackSim\Controllers;

use HackSim\Services\ChallengeService;
use HackSim\Services\GamificationService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class ChallengeController
{
    private ChallengeService $challengeService;
    private GamificationService $gamification;

    public function __construct(ChallengeService $challengeService, GamificationService $gamification)
    {
        $this->challengeService = $challengeService;
        $this->gamification = $gamification;
    }

    public function getChallenges(Request $request, Response $response): Response
    {
        $filters = $request->getQueryParams();

        try {
            $result = $this->challengeService->getChallenges($filters);

            $response->getBody()->write(json_encode($result));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getChallengeById(Request $request, Response $response, array $args): Response
    {
        $challengeId = $args['id'];

        try {
            $challenge = $this->challengeService->getChallengeById($challengeId);

            if (!$challenge) {
                return $this->errorResponse($response, 'Challenge not found', 404);
            }

            $response->getBody()->write(json_encode(['challenge' => $challenge]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function startChallenge(Request $request, Response $response, array $args): Response
    {
        $challengeId = $args['id'];
        $userId = $request->getAttribute('user_id');

        try {
            $instance = $this->challengeService->startChallenge($challengeId, $userId);

            $response->getBody()->write(json_encode([
                'message' => 'Challenge started successfully',
                'instance' => $instance,
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        }
    }

    public function submitSolution(Request $request, Response $response, array $args): Response
    {
        $instanceId = $args['id'];
        $userId = $request->getAttribute('user_id');
        $data = json_decode($request->getBody()->getContents(), true);

        if (!isset($data['submission'])) {
            return $this->errorResponse($response, 'Missing submission data', 400);
        }

        try {
            $result = $this->challengeService->submitSolution($instanceId, $userId, $data['submission']);

            if ($result['is_correct']) {
                $this->gamification->awardXP($userId, $result['score_earned'], "challenge:{$instanceId}");
                $this->gamification->checkAchievements($userId, [
                    'challenge_completed' => true,
                    'score_earned' => $result['score_earned'],
                ]);
            }

            $response->getBody()->write(json_encode([
                'message' => 'Solution submitted successfully',
                'result' => $result,
            ]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 400);
        }
    }

    public function getCategories(Request $request, Response $response): Response
    {
        try {
            $categories = $this->challengeService->getChallengeCategories();

            $response->getBody()->write(json_encode(['categories' => $categories]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getUserProgress(Request $request, Response $response): Response
    {
        $userId = $request->getAttribute('user_id');

        try {
            $progress = $this->challengeService->getUserProgress($userId);

            $response->getBody()->write(json_encode(['progress' => $progress]));

            return $response->withHeader('Content-Type', 'application/json');
        } catch (\Exception $e) {
            return $this->errorResponse($response, $e->getMessage(), 500);
        }
    }

    public function getChallengeInstance(Request $request, Response $response, array $args): Response
    {
        $instanceId = $args['id'];
        $userId = $request->getAttribute('user_id');

        $instance = $this->challengeService->database->table('challenge_instances')
                                                     ->where('id', $instanceId)
                                                     ->where('user_id', $userId)
                                                     ->first();

        if (!$instance) {
            return $this->errorResponse($response, 'Challenge instance not found', 404);
        }

        $challenge = $this->challengeService->getChallengeById($instance->challenge_id);

        $response->getBody()->write(json_encode([
            'instance' => $instance,
            'challenge' => $challenge,
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