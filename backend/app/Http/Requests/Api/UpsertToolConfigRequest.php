<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class UpsertToolConfigRequest extends FormRequest
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
            'enabled' => ['required', 'boolean'],
            'timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:120'],
            'data_source_id' => ['nullable', 'integer'],
        ];
    }
}
