<?php

namespace App\Http\Requests\Api;

class UploadExcelDataSourceRequest extends UploadDataSourceRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'file' => ['required', 'file', 'extensions:xlsx', 'max:10240'],
        ];
    }
}
