<?php

namespace App\Http\Requests\Api;

use App\Models\WhatsAppLine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertWhatsAppLineRequest extends FormRequest
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
        /** @var WhatsAppLine|null $line */
        $line = $this->route('whatsappLine');
        $required = $this->isMethod('post') ? ['required'] : ['sometimes'];

        return [
            'name' => [...$required, 'string', 'max:120'],
            'phone_number_id' => [
                ...$required,
                'string',
                'max:190',
                Rule::unique('whatsapp_lines', 'phone_number_id')->ignore($line?->getKey()),
            ],
            'display_phone_number' => ['nullable', 'string', 'max:40'],
            'waba_id' => ['nullable', 'string', 'max:190'],
            'status' => [...$required, 'string', 'max:40'],
            'is_enabled' => [...$required, 'boolean'],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
