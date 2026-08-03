<?php

namespace App\Http\Requests\Api;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertTenantRequest extends FormRequest
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
        /** @var Tenant|null $tenant */
        $tenant = $this->route('tenant');
        $required = $this->isMethod('post') ? ['required'] : ['sometimes'];

        return [
            'name' => [...$required, 'string', 'max:120'],
            'slug' => [
                ...$required,
                'string',
                'max:120',
                Rule::unique('tenants', 'slug')->ignore($tenant?->getKey()),
            ],
            'status' => [...$required, Rule::enum(TenantStatus::class)],
            'metadata' => ['sometimes', 'array'],
        ];
    }
}
