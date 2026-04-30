<?php

namespace App\Http\Controllers\Api;

use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Api\Concerns\ResolvesTenantFromRequest;
use App\Http\Requests\Api\UploadExcelDataSourceRequest;
use App\Jobs\ImportExcelDataSourceJob;
use App\Models\DataSource;
use App\Models\DataSourceImport;
use App\Models\UploadedFile;
use App\Support\Admin\AdminPanelDataBuilder;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Audit\AuditEventRecorder;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile as HttpUploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class DataSourceController extends Controller
{
    use ResolvesTenantFromRequest;

    public function __construct(
        protected AdminPanelDataBuilder $builder,
        protected AuditEventRecorder $audit,
        protected AgentEventRecorder $events,
    ) {
    }

    public function index(Request $request, TenantContextManager $tenantContextManager): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

        $tenantId = $tenantContextManager->require()->tenantId();
        $sources = $this->builder->dataSourcesForTenant($tenantId);

        return response()->json([
            'data' => $sources->map(fn (DataSource $source): array => $this->builder->serializeDataSource($source))->all(),
        ]);
    }

    public function storeExcel(UploadExcelDataSourceRequest $request, TenantContextManager $tenantContextManager): JsonResponse
    {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

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
            'uploaded_by_user_id' => $request->user()?->getKey(),
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
            'metadata' => [
                'queue' => ImportExcelDataSourceJob::QUEUE,
                'request_type' => 'initial_upload',
                'requested_by_user_id' => $request->user()?->getKey(),
            ],
        ]);

        ImportExcelDataSourceJob::dispatch($tenantId, $import->getKey());

        $this->events->record($tenantId, 'data_source_import_queued', [
            'payload' => [
                'data_source_id' => $dataSource->getKey(),
                'import_id' => $import->getKey(),
                'uploaded_file_id' => $uploadedFile->getKey(),
                'queue' => ImportExcelDataSourceJob::QUEUE,
                'request_type' => 'initial_upload',
            ],
        ]);

        $this->audit->record(
            tenantId: $tenantId,
            eventType: 'data_source_uploaded',
            actorUserId: $request->user()?->getKey(),
            target: $dataSource,
            payload: [
                'data_source_id' => $dataSource->getKey(),
                'uploaded_file_id' => $uploadedFile->getKey(),
                'import_id' => $import->getKey(),
            ],
        );

        $dataSource = $this->builder->dataSourcesForTenant($tenantId)
            ->firstWhere('id', $dataSource->getKey());

        return response()->json([
            'data' => $this->builder->serializeDataSource($dataSource),
        ], Response::HTTP_CREATED);
    }

    public function retryImport(
        Request $request,
        TenantContextManager $tenantContextManager,
        DataSource $dataSource,
        DataSourceImport $import,
    ): JsonResponse {
        $tenant = $this->tenantFromRequest($request);
        Gate::forUser($request->user())->authorize(Permission::ManageAgentConfig->value, $tenant);

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
            'metadata' => array_merge($import->metadata ?? [], [
                'queue' => ImportExcelDataSourceJob::QUEUE,
                'request_type' => 'manual_retry',
                'requested_by_user_id' => $request->user()?->getKey(),
            ]),
        ])->save();

        $dataSource->forceFill([
            'status' => 'pending',
        ])->save();

        ImportExcelDataSourceJob::dispatch($tenantId, $import->getKey());

        $this->events->record($tenantId, 'data_source_import_queued', [
            'payload' => [
                'data_source_id' => $dataSource->getKey(),
                'import_id' => $import->getKey(),
                'uploaded_file_id' => $import->uploaded_file_id,
                'queue' => ImportExcelDataSourceJob::QUEUE,
                'request_type' => 'manual_retry',
            ],
        ]);

        $this->audit->record(
            tenantId: $tenantId,
            eventType: 'data_source_import_retried',
            actorUserId: $request->user()?->getKey(),
            target: $dataSource,
            payload: [
                'data_source_id' => $dataSource->getKey(),
                'import_id' => $import->getKey(),
            ],
        );

        $dataSource = $this->builder->dataSourcesForTenant($tenantId)
            ->firstWhere('id', $dataSource->getKey());

        return response()->json([
            'data' => $this->builder->serializeDataSource($dataSource),
        ]);
    }
}
