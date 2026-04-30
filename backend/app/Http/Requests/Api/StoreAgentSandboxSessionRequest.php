<?php

namespace App\Http\Requests\Api;

use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentSandboxSessionRequest extends FormRequest
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
        /** @var TenantContext|null $context */
        $context = $this->attributes->get(TenantContext::class);
        $tenantId = $context?->tenant->getKey();

        return [
            'whatsapp_line_id' => [
                'required',
                'integer',
                Rule::exists('whatsapp_lines', 'id')->where(
                    fn ($query) => $query->where('tenant_id', $tenantId)
                ),
            ],
            'label' => ['nullable', 'string', 'max:120'],
        ];
    }
}
