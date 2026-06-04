<?php

namespace App\Console\Commands;

use App\Exceptions\OpenAIServiceException;
use App\Services\OpenAI\OpenAIService;
use Illuminate\Console\Command;
use Throwable;

class AiAgentTestCommand extends Command
{
    protected $signature = 'ai-agent:test {--question= : Override the default smoke-test question}';

    protected $description = 'Health-check the AI agent (OpenAI Responses API + File Search) without exposing secrets';

    public function handle(OpenAIService $service): int
    {
        $this->info('Quenyx AI Agent — connectivity check');
        $this->line('');

        $apiKey = (string) config('openai.api_key');
        $vectorStoreId = (string) config('openai.vector_store_id');
        $model = (string) (config('openai.model') ?: 'gpt-5-mini');

        $apiKeyOk = trim($apiKey) !== '';
        $vectorOk = trim($vectorStoreId) !== '';

        $this->line('OPENAI_API_KEY        : '.($apiKeyOk ? '<info>present</info>' : '<error>MISSING</error>'));
        $this->line('OPENAI_VECTOR_STORE_ID: '.($vectorOk ? '<info>present</info> ('.$this->maskVectorStore($vectorStoreId).')' : '<error>MISSING</error>'));
        $this->line('OPENAI_MODEL          : <info>'.$model.'</info>');
        $this->line('');

        if (! $apiKeyOk || ! $vectorOk) {
            $this->error('Configuration incomplete. Set the missing values in .env and run `php artisan config:clear`.');

            return self::FAILURE;
        }

        $question = (string) ($this->option('question') ?: 'Give a short 3-bullet summary of Quenyx vOPS HUB.');
        $this->line('Sending test question (quick mode): <comment>'.$question.'</comment>');

        try {
            $answer = $service->askKnowledgeBase($question, 'performance_analyst', [], true);
        } catch (OpenAIServiceException $e) {
            $this->error('AI agent call failed ['.$e->errorCode.', HTTP '.$e->status.']: '.$e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            // Never surface stack traces or secrets; summarize only.
            $this->error('Unexpected failure: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->info('Success.');
        $this->line('Model        : '.$answer->model);
        $this->line('Response ID  : '.($answer->responseId ?? 'n/a'));
        $this->line('Total tokens : '.($answer->totalTokens ?? 'n/a'));
        $this->line('');
        $this->line('Answer preview:');
        $this->line($this->preview($answer->answer));

        return self::SUCCESS;
    }

    private function maskVectorStore(string $id): string
    {
        if (mb_strlen($id) <= 8) {
            return 'set';
        }

        return mb_substr($id, 0, 6).'…'.mb_substr($id, -2);
    }

    private function preview(string $text, int $limit = 280): string
    {
        $text = trim($text);

        return mb_strlen($text) > $limit ? mb_substr($text, 0, $limit).'…' : $text;
    }
}
