<?php

namespace Tests\Unit;

use App\DataTransferObjects\Ai\AiSkillExecution;
use App\DataTransferObjects\Ai\AiSkillRequest;
use App\DataTransferObjects\Ai\AiSkillResponse;
use App\DataTransferObjects\Ai\AiSkillResult;
use App\Services\Ai\CompliancePromptOrchestrator;
use App\Services\Ai\Skills\AbstractAiSkill;
use App\Services\Ai\Skills\AiSkillRegistry;
use App\Services\Ai\Skills\AiSkillRouter;
use Tests\TestCase;

/**
 * DB-free, AI-free unit tests for the AI Skills Framework: registry registration/discovery/
 * priority/flags, router selection + execution + failure capture, and the orchestrator's
 * multi-skill prompt composition. Uses an in-memory fake skill so no compliance service or
 * database is touched.
 */
class AiSkillsFrameworkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Isolate the registry from the config-registered production skills so these tests use
        // only the in-memory fakes (and never touch a compliance service or the database).
        config(['ai.skills.registered' => [], 'ai.skills.enabled' => true]);
    }

    public function test_registry_registers_discovers_and_orders_by_priority(): void
    {
        $registry = new AiSkillRegistry();
        $registry->register(new FakeSkill('alpha'), priority: 50);
        $registry->register(new FakeSkill('beta'), priority: 200);

        $this->assertTrue($registry->has('alpha'));
        $this->assertTrue($registry->has('beta'));
        // Higher priority first.
        $this->assertSame(['beta', 'alpha'], $registry->keys());
        $this->assertSame('beta', $registry->all()[0]->key());
    }

    public function test_registry_enable_disable_and_feature_flags(): void
    {
        $registry = new AiSkillRegistry();
        $registry->register(new FakeSkill('alpha'));

        $this->assertTrue($registry->isEnabled('alpha'));

        $registry->disable('alpha');
        $this->assertFalse($registry->isEnabled('alpha'));
        $registry->enable('alpha');
        $this->assertTrue($registry->isEnabled('alpha'));

        config(['ai.skills.enabled' => false]);
        $this->assertFalse($registry->isEnabled('alpha'));
        config(['ai.skills.enabled' => true]);

        $registry2 = new AiSkillRegistry();
        $registry2->register(new FakeSkill('beta'), enabled: false);
        $this->assertFalse($registry2->isEnabled('beta'));
    }

    public function test_router_selects_explicit_skill_and_executes(): void
    {
        $registry = new AiSkillRegistry();
        $registry->register(new FakeSkill('alpha'));
        $router = new AiSkillRouter($registry);

        $response = $router->execute(new AiSkillRequest(skill: 'alpha'));

        $this->assertTrue($response->success);
        $this->assertSame('alpha', $response->skillKey);
        $this->assertSame('completed', $response->execution->status);
        $this->assertNotNull($response->result);
        $this->assertSame('fake_context', $response->result->contextType);
    }

    public function test_router_auto_selects_by_context_type(): void
    {
        $registry = new AiSkillRegistry();
        $registry->register(new FakeSkill('alpha'), priority: 10);
        $router = new AiSkillRouter($registry);

        $response = $router->execute(new AiSkillRequest(contextType: 'fake_context'));
        $this->assertTrue($response->success);
        $this->assertSame('alpha', $response->skillKey);
    }

    public function test_router_throws_when_no_skill_matches(): void
    {
        $registry = new AiSkillRegistry();
        $router = new AiSkillRouter($registry);

        $this->expectException(\App\Exceptions\Ai\AiSkillException::class);
        $router->execute(new AiSkillRequest(contextType: 'nonexistent'));
    }

    public function test_router_captures_skill_failure_as_failed_response(): void
    {
        $registry = new AiSkillRegistry();
        $registry->register(new ExplodingSkill());
        $router = new AiSkillRouter($registry);

        $response = $router->execute(new AiSkillRequest(skill: 'exploding'));

        $this->assertFalse($response->success);
        $this->assertSame('failed', $response->execution->status);
        $this->assertNull($response->result);
        $this->assertNotNull($response->error);
    }

    public function test_orchestrator_composes_one_prompt_from_multiple_skill_responses(): void
    {
        $resultA = new AiSkillResult('alpha', 'fake_context', ['a' => 1], [['source_document_key' => 'doc-1']], ['cite_every_claim' => true]);
        $resultB = new AiSkillResult('beta', 'graph_context', ['b' => 2], [['source_document_key' => 'doc-2']], ['use_only_provided_context' => true]);

        $responses = [
            AiSkillResponse::completed($this->execution('alpha'), $resultA),
            AiSkillResponse::completed($this->execution('beta'), $resultB),
            AiSkillResponse::failed($this->execution('gamma'), 'nope', 'err'),
        ];

        $prompt = (new CompliancePromptOrchestrator())->composeFromSkills($responses, 'Question?');

        $this->assertStringContainsString('CONTEXT 1', $prompt->systemPrompt);
        $this->assertStringContainsString('CONTEXT 2', $prompt->systemPrompt);
        $this->assertStringContainsString('doc-1', $prompt->systemPrompt);
        $this->assertStringContainsString('doc-2', $prompt->systemPrompt);
        $this->assertSame('Question?', $prompt->userPrompt);
        $this->assertCount(2, $prompt->citations);
        $this->assertTrue($prompt->guardrails['cite_every_claim']);
        $this->assertTrue($prompt->guardrails['use_only_provided_context']);
        $this->assertSame(['alpha', 'beta'], $prompt->metadata['skills']);
    }

    private function execution(string $key): AiSkillExecution
    {
        return new AiSkillExecution('exec-'.$key, $key, 'completed', 1.0, now()->toIso8601String(), now()->toIso8601String());
    }
}

class FakeSkill extends AbstractAiSkill
{
    public function __construct(private readonly string $skillKey = 'fake') {}

    public function key(): string
    {
        return $this->skillKey;
    }

    public function displayName(): string
    {
        return 'Fake';
    }

    public function description(): string
    {
        return 'In-memory test skill.';
    }

    public function supportedContextTypes(): array
    {
        return ['fake_context'];
    }

    public function execute(AiSkillRequest $request): AiSkillResult
    {
        return new AiSkillResult($this->key(), 'fake_context', ['ok' => true], [], $this->standardGuardrails());
    }
}

class ExplodingSkill extends AbstractAiSkill
{
    public function key(): string
    {
        return 'exploding';
    }

    public function displayName(): string
    {
        return 'Exploding';
    }

    public function description(): string
    {
        return 'Always throws.';
    }

    public function supportedContextTypes(): array
    {
        return ['boom'];
    }

    public function execute(AiSkillRequest $request): AiSkillResult
    {
        throw new \RuntimeException('boom');
    }
}
