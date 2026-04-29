<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UploadExcelDataSourceRequest;
use App\Jobs\ImportExcelDataSourceJob;
use App\Models\DataSource;
use App\Models\DataSourceImport;
use App\Models\UploadedFile;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DataSourceController extends Controller
{
    public function index(TenantContextManager $tenantContextManager): JsonResponse
    {
        $tenantId = $tenantContextManager->require()->tenantId();
        $sources = DataSource::query()
            ->forTenant($tenantId)
            ->with(['uploadedFiles' => fn ($query) => $query->latest('id')->limit(1)])
            ->with(['imports' => fn ($query) => $query->latest('id')->limit(1)])
            ->withCount(['chunks'])
            ->orderByDesc('updated_at')
            ->get();

        return response()->json([
            'data' => $sources->map(fn (DataSource $source): array => $this->serializeDataSource($source))->all(),
        ]);
    }

    public function storeExcel(UploadExcelDataSourceRequest $request, TenantContextManager $tenantContextManager): JsonResponse
    {
        $tenantId = $tenantContextManager->require()->tenantId();
        /** @var HttpUploadedFile $file */
        $file = $request->file('file');
        $storagePath = $file->storeAs(
            'data-sources/tenant-'.$tenantId,
            Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
            'local',
        );

        $dataSource = DataSource::query()->create([
            'tenant_id' => $tenantId,
            'name' => trim((string) ($request->string('name')->value() ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME))),
            'type' => 'excel',
            'status' => 'pending',
            'metadata' => [
                'source_kind' => 'excel_upload',
            ],
        ]);

        $uploadedFile = UploadedFile::query()->create([
            'tenant_id' => $tenantId,
            'data_source_id' => $dataSource->getKey(),
            'disk' => 'local',
            'path' => $storagePath,
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize(),
            'checksum' => hash_file('sha256', $file->getRealPath()),
            'metadata' => [
                'extension' => strtolower((string) $file->getClientOriginalExtension()),
            ],
        ]);

        $import = DataSourceImport::query()->create([
            'tenant_id' => $tenantId,
            'data_source_id' => $dataSource->getKey(),
            'uploaded_file_id' => $uploadedFile->getKey(),
            'status' => 'pending',
        ]);

        ImportExcelDataSourceJob::dispatch($tenantId, $import->getKey());

        $dataSource->refresh()->load([
            'uploadedFiles' => fn ($query) => $query->latest('id')->limit(1),
            'imports' => fn ($query) => $query->latest('id')->limit(1),
        ])->loadCount(['chunks']);

        return response()->json([
            'data' => $this->serializeDataSource($dataSource),
        ], Response::HTTP_CREATED);
    }

    public function retryImport(
        Request $request,
        TenantContextManager $tenantContextManager,
        DataSource $dataSource,
        DataSourceImport $import,
    ): JsonResponse {
        $tenantId = $tenantContextManager->require()->tenantId();
        $dataSource = DataSource::query()->forTenant($tenantId)->findOrFail($dataSource->getKey());
        $import = DataSourceImport::query()
            ->forTenant($tenantId)
            ->where('data_source_id', $dataSource->getKey())
            ->findOrFail($import->getKey());

        if ($import->status !== 'failed') {
            return response()->json([
                'message' => 'Only failed imports can be retried.',
            ], Response::HTTP_CONFLICT);
        }

        $import->forceFill([
            'status' => 'pending',
            'error_message' => null,
            'finished_at' => null,
        ])->save();

        $dataSource->forceFill([
            'status' => 'pending',
        ])->save();

        ImportExcelDataSourceJob::dispatch($tenantId, $import->getKey());

        $dataSource->refresh()->load([
            'uploadedFiles' => fn ($query) => $query->latest('id')->limit(1),
            'imports' => fn ($query) => $query->latest('id')->limit(1),
        ])->loadCount(['chunks']);

        return response()->json([
            'data' => $this->serializeDataSource($dataSource),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeDataSource(DataSource $dataSource): array
    {
        $latestImport = $dataSource->imports->first();
        $latestFile = $dataSource->uploadedFiles->first();

        return [
            'id' => $dataSource->getKey(),
            'name' => $dataSource->name,
            'type' => $dataSource->type,
            'status' => $dataSource->status,
            'last_synced_at' => $dataSource->last_synced_at?->toIso8601String(),
            'metadata' => $dataSource->metadata ?? [],
            'chunk_count' => $dataSource->chunks_count ?? 0,
            'latest_upload' => $latestFile ? [
                'id' => $latestFile->getKey(),
                'original_name' => $latestFile->original_name,
                'mime_type' => $latestFile->mime_type,
                'size_bytes' => $latestFile->size_bytes,
                'checksum' => $latestFile->checksum,
                'created_at' => $latestFile->created_at?->toIso8601String(),
            ] : null,
            'latest_import' => $latestImport ? [
                'id' => $latestImport->getKey(),
                'status' => $latestImport->status,
                'attempts_count' => $latestImport->attempts_count,
                'processed_sheet_count' => $latestImport->processed_sheet_count,
                'generated_chunk_count' => $latestImport->generated_chunk_count,
                'error_message' => $latestImport->error_message,
                'started_at' => $latestImport->started_at?->toIso8601String(),
                'finished_at' => $latestImport->finished_at?->toIso8601String(),
                'metadata' => $latestImport->metadata ?? [],
            ] : null,
        ];
    }
}
