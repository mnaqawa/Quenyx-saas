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
