<?php

namespace App\Http\Requests;

use App\Services\OpenAI\OpenAIService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AIAgentQueryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'agent' => ['required', 'string', Rule::in(OpenAIService::supportedAgents())],
            'question' => ['required', 'string', 'max:5000'],

            // Quick mode: shorter, cheaper answers (predefined actions set this).
            'quick' => ['sometimes', 'boolean'],

            // Optional workspace context. Membership is verified in the controller.
            'workspace_id' => ['sometimes', 'nullable', 'integer'],

            // Optional QynSight operational context (free-form but bounded).
            'context' => ['sometimes', 'nullable', 'array'],
            'context.source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'context.host' => ['sometimes', 'nullable', 'string', 'max:255'],
            'context.metrics' => ['sometimes', 'nullable', 'array'],
            'context.services' => ['sometimes', 'nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'agent.in' => 'The agent must be one of: '.implode(', ', OpenAIService::supportedAgents()).'.',
        ];
    }
}
