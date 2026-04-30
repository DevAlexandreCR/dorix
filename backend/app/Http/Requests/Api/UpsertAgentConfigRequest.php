<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpsertAgentConfigRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'model' => ['nullable', 'string', 'max:120'],
            'prompt_version' => ['nullable', 'string', 'max:120'],
            'is_active' => ['required', 'boolean'],
            'automation_enabled' => ['required', 'boolean'],
            'system_prompt' => ['nullable', 'string', 'max:8000'],
        ];
    }
}
