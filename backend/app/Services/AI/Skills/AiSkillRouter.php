<?php

namespace App\Services\AI\Skills;

use App\Contracts\Ai\AiSkillInterface;
use App\DataTransferObjects\Ai\AiSkillExecution;
use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResponse;
use App\Exceptions\Ai\AiSkillException;
use Illuminate\Support\Str;

/**
 * Routes an AI request to the right skill and executes it, returning an AiSkillResponse (or a
 * list of them when aggregating). Determines the skill (explicit key, else highest-priority
 * enabled skill that supports the request), times the execution, and captures failures as
 * failed responses. Makes NO OpenAI/provider calls.
 */
class AiSkillRouter
{
    public function __construct(
        private readonly AiSkillRegistry $registry,
    ) {}

    /**
     * Pick the skill for a request. Throws when none is enabled/matches.
     */
    public function route(AiSkillRequest $request): AiSkillInterface
    {
        if ($request->skill !== null && $request->skill !== '') {
            if (! $this->registry->isEnabled($request->skill)) {
                throw new AiSkillException("AI skill '{$request->skill}' is unknown or disabled.", 'ai_skill_unavailable');
            }

            return $this->registry->get($request->skill);
        }

        foreach ($this->registry->enabled() as $skill) {
            if ($skill->supports($request)) {
                return $skill;
            }
        }

        throw new AiSkillException('No enabled skill matches the request.', 'no_skill_matched');
    }

    /**
     * Route + execute a single request.
     */
    public function execute(AiSkillRequest $request): AiSkillResponse
    {
        $skill = $this->route($request);

        return $this->runSkill($skill, $request);
    }

    /**
     * Execute multiple requests and aggregate the responses (order preserved).
     *
     * @param  list<AiSkillRequest>  $requests
     * @return list<AiSkillResponse>
     */
    public function executeMany(array $requests): array
    {
        $responses = [];
        foreach ($requests as $request) {
            try {
                $responses[] = $this->execute($request);
            } catch (AiSkillException $e) {
                $responses[] = AiSkillResponse::failed(
                    new AiSkillExecution((string) Str::uuid(), $request->skill ?? 'unknown', 'skipped', 0.0, now()->toIso8601String(), now()->toIso8601String()),
                    $e->getMessage(),
                    $e->errorCode(),
                );
            }
        }

        return $responses;
    }

    private function runSkill(AiSkillInterface $skill, AiSkillRequest $request): AiSkillResponse
    {
        $startedAt = now()->toIso8601String();
        $start = microtime(true);

        try {
            $result = $skill->execute($request);
            $execution = $this->execution($skill->key(), 'completed', $start, $startedAt);

            return AiSkillResponse::completed($execution, $result);
        } catch (AiSkillException $e) {
            return AiSkillResponse::failed($this->execution($skill->key(), 'failed', $start, $startedAt), $e->getMessage(), $e->errorCode());
        } catch (\Throwable $e) {
            return AiSkillResponse::failed($this->execution($skill->key(), 'failed', $start, $startedAt), $e->getMessage(), 'skill_execution_error');
        }
    }

    private function execution(string $skillKey, string $status, float $start, string $startedAt): AiSkillExecution
    {
        return new AiSkillExecution(
            uuid: (string) Str::uuid(),
            skillKey: $skillKey,
            status: $status,
            durationMs: (microtime(true) - $start) * 1000,
            startedAt: $startedAt,
            finishedAt: now()->toIso8601String(),
        );
    }
}
