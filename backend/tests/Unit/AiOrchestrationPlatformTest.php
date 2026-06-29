<?php

namespace Tests\Unit;

use App\Contracts\Ai\AiProviderInterface;
use App\DataTransferObjects\Ai\AiCompletionRequest;
use App\DataTransferObjects\Ai\AiMessage;
use App\Enums\Ai\AiCapability;
use App\Exceptions\Ai\AiProviderException;
use App\Services\Ai\AiProviderRegistry;
use App\Services\Ai\CompliancePromptOrchestrator;
use App\Services\Ai\Providers\MockAiProvider;
use App\Services\Ai\Providers\OpenAiProvider;
use Tests\TestCase;

/**
 * DB-free, network-free unit tests for the AI Orchestration Platform: registry resolution +
 * provider swap, mock-by-default behavior, capability advertising, and the orchestrator's
 * strict no-corpus/no-DB prompt assembly.
 */
class AiOrchestrationPlatformTest extends TestCase
{
    public function test_default_provider_is_mock_and_ai_is_disabled_by_default(): void
    {
        $this->assertFalse((bool) config('ai.feature_flags.enabled'));
        // RC1.1: ai.default is no longer hardcoded to "mock"; with AI_PROVIDER unset it is null and the
        // registry resolves the default safely (mock only in local/testing, never as a prod default).
        $this->assertNull(config('ai.default'));

        $registry = new AiProviderRegistry();
        $this->assertSame('mock', $registry->defaultKey()); // testing environment

        $provider = $registry->default();
        $this->assertInstanceOf(MockAiProvider::class, $provider);
        $this->assertSame('mock', $provider->key());
    }

    public function test_registry_supports_discovery_and_provider_switching(): void
    {
        $registry = new AiProviderRegistry();

        $this->assertContains('mock', $registry->available());
        $this->assertContains('openai', $registry->available());
        $this->assertTrue($registry->has('openai'));

        $this->assertInstanceOf(MockAiProvider::class, $registry->get('mock'));
        $this->assertInstanceOf(OpenAiProvider::class, $registry->get('openai'));
        $this->assertInstanceOf(AiProviderInterface::class, $registry->get('openai'));
    }

    public function test_unknown_provider_throws(): void
    {
        $this->expectException(AiProviderException::class);
        (new AiProviderRegistry())->get('does-not-exist');
    }

    public function test_mock_provider_executes_without_network_and_is_marked_mocked(): void
    {
        $request = new AiCompletionRequest(messages: [AiMessage::user('hello')]);
        $response = (new MockAiProvider())->responses($request);

        $this->assertTrue($response->mocked);
        $this->assertSame('mock', $response->provider);
        $this->assertNull($response->model);
        $this->assertSame(0, $response->usage->totalTokens);
    }

    public function test_mock_capabilities_are_advertised(): void
    {
        $capabilities = (new MockAiProvider())->supportedCapabilities();
        $this->assertContains(AiCapability::Chat, $capabilities);
        $this->assertContains(AiCapability::Stream, $capabilities);
    }

    public function test_orchestrator_builds_prompt_from_payload_with_guardrails_and_citations(): void
    {
        $aiContext = [
            'context_type' => 'control_profile',
            'payload' => ['control' => ['code' => '1-1-1', 'title_en' => 'EN', 'title_ar' => 'AR']],
            'citations' => [[
                'source_document_key' => 'nca-ecc-2-2024',
                'official_reference' => 'ECC 1-1-1',
                'entity_uuid' => 'ctrl-uuid-1',
            ]],
            'guardrails' => [
                'use_only_provided_context' => true,
                'cite_every_claim' => true,
                'bilingual_required' => true,
            ],
        ];

        $prompt = (new CompliancePromptOrchestrator())->buildPrompt($aiContext, 'What does control 1-1-1 require?');

        $this->assertStringContainsString('GUARDRAILS', $prompt->systemPrompt);
        $this->assertStringContainsString('CITATIONS', $prompt->systemPrompt);
        $this->assertStringContainsString('nca-ecc-2-2024', $prompt->systemPrompt);
        $this->assertSame('What does control 1-1-1 require?', $prompt->userPrompt);
        $this->assertCount(1, $prompt->citations);
        $this->assertTrue($prompt->guardrails['cite_every_claim']);

        $messages = $prompt->toMessages();
        $this->assertCount(2, $messages);
        $this->assertSame('system', $messages[0]->role->value);
        $this->assertSame('user', $messages[1]->role->value);
    }

    public function test_orchestrator_has_no_corpus_or_db_dependencies(): void
    {
        // The orchestrator must be constructable with zero dependencies — proving it neither
        // queries the corpus nor touches the database.
        $constructor = (new \ReflectionClass(CompliancePromptOrchestrator::class))->getConstructor();
        $this->assertTrue($constructor === null || $constructor->getNumberOfRequiredParameters() === 0);

        $source = (string) file_get_contents((new \ReflectionClass(CompliancePromptOrchestrator::class))->getFileName());
        $this->assertStringNotContainsString('use Illuminate\\Support\\Facades\\DB', $source);
        $this->assertStringNotContainsString('use App\\Models', $source);
        $this->assertStringNotContainsString('ComplianceCorpusQueryService', $source);
        $this->assertStringNotContainsString('->get()', $source);
    }
}
