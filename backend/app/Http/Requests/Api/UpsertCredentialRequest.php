<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertCredentialRequest extends FormRequest
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
            'scope_type' => ['required', Rule::in(['tenant', 'whatsapp_line'])],
            'whatsapp_line_id' => ['nullable', 'integer'],
            'provider' => ['required', 'string', 'max:120'],
            'credential_key' => ['required', 'string', 'max:120'],
            'secret' => ['required', 'string', 'max:8000'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
