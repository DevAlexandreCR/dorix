<?php

namespace App\Http\Requests\Api;

use App\Domain\Agent\AgentPackRegistry;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'agent_pack_key' => [
                'nullable',
                'string',
                Rule::in(app(AgentPackRegistry::class)->keys()),
            ],
            'handoff_customer_message' => ['nullable', 'string', 'max:1000'],
        ];
    }
}
