<?php

namespace App\Http\Requests\Api;

use App\Enums\WhatsAppConnectionMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ConnectWhatsAppLineRequest extends FormRequest
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
            'code' => ['required', 'string'],
            'phone_number_id' => ['required', 'string', 'max:190'],
            'waba_id' => ['required', 'string', 'max:190'],
            'name' => ['nullable', 'string', 'max:120'],
            'connection_mode' => ['required', Rule::enum(WhatsAppConnectionMode::class)],
        ];
    }
}
