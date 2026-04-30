<?php

namespace App\Http\Requests\Api;

use App\Enums\TenantRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantUserRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'password' => ['nullable', 'string', 'min:8', 'max:120'],
            'role' => ['required', Rule::enum(TenantRole::class)],
        ];
    }
}
