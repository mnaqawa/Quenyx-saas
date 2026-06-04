<?php

namespace App\Http\Requests;

use App\Services\AI\Personas;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AiChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Project-level authorization is handled in the controller via policy.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'persona' => ['required', 'string', Rule::in(array_keys(Personas::all()))],
            'message' => ['required', 'string', 'min:1', 'max:'.(int) config('ai.message_max_chars', 4000)],
            'host' => ['nullable', 'string', 'max:255'],
            'history' => ['nullable', 'array', 'max:50'],
            'history.*.role' => ['required_with:history', 'string', Rule::in(['user', 'assistant'])],
            'history.*.content' => ['required_with:history', 'string', 'max:'.(int) config('ai.message_max_chars', 4000)],
        ];
    }
}
