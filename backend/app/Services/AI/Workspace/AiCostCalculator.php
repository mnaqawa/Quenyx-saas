<?php

namespace App\Services\AI\Workspace;

/**
 * Sprint 20 — derives cost from REAL token counts using an optional, config-driven price table
 * (config('ai.workspace.pricing')). It NEVER fabricates a cost: when no price is configured for a
 * provider the result is flagged pricing_configured=false and carries token totals only (no amount).
 */
class AiCostCalculator
{
    /**
     * @return array<string, array{prompt: float, completion: float}>
     */
    private function pricing(): array
    {
        $raw = (array) config('ai.workspace.pricing', []);
        $out = [];
        foreach ($raw as $provider => $prices) {
            if (! is_array($prices)) {
                continue;
            }
            $out[(string) $provider] = [
                'prompt' => (float) ($prices['prompt'] ?? 0),
                'completion' => (float) ($prices['completion'] ?? 0),
            ];
        }

        return $out;
    }

    public function isProviderPriced(string $provider): bool
    {
        $pricing = $this->pricing();

        return isset($pricing[$provider]) && ($pricing[$provider]['prompt'] > 0 || $pricing[$provider]['completion'] > 0);
    }

    /**
     * Compute cost for a single provider's token totals. Returns null amount when not priced.
     *
     * @return array{provider: string, prompt_tokens: int, completion_tokens: int, total_tokens: int, pricing_configured: bool, cost: float|null, currency: string}
     */
    public function forProvider(string $provider, int $promptTokens, int $completionTokens): array
    {
        $priced = $this->isProviderPriced($provider);
        $cost = null;

        if ($priced) {
            $prices = $this->pricing()[$provider];
            $cost = round((($promptTokens / 1000) * $prices['prompt']) + (($completionTokens / 1000) * $prices['completion']), 6);
        }

        return [
            'provider' => $provider,
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'total_tokens' => $promptTokens + $completionTokens,
            'pricing_configured' => $priced,
            'cost' => $cost,
            'currency' => (string) config('ai.workspace.currency', 'USD'),
        ];
    }
}
