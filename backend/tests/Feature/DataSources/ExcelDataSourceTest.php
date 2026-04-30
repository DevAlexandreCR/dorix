<?php

namespace Tests\Feature\DataSources;

use App\Domain\DataSources\Contracts\DataSourceImporter;
use App\Domain\DataSources\Contracts\DataSourceReader;
use App\Enums\TenantRole;
use App\Jobs\ImportExcelDataSourceJob;
use App\Models\DataSource;
use App\Models\DataSourceChunk;
use App\Models\DataSourceImport;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\UploadedFile;
use App\Models\User;
use App\Support\AgentEvents\AgentEventRecorder;
use App\Support\Tenancy\TenantContextManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as TestUploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\BuildsXlsxWorkbook;
use Tests\TestCase;

class ExcelDataSourceTest extends TestCase
{
    use BuildsXlsxWorkbook;
    use RefreshDatabase;

    public function test_tenant_can_upload_an_excel_file_and_index_retrievable_chunks(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = $this->tenantAdmin($tenant);

        $response = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources/excel', [
                'name' => 'Catalogo Abril',
                'file' => TestUploadedFile::fake()->createWithContent(
                    'catalogo.xlsx',
                    $this->validWorkbookContent(),
                ),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.name', 'Catalogo Abril')
            ->assertJsonPath('data.type', 'excel')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.chunk_count', 0)
            ->assertJsonPath('data.latest_import.status', 'pending');

        $import = DataSourceImport::query()->firstOrFail();
        $this->runImportJob($tenant, $import);

        $dataSource = DataSource::query()->firstOrFail();
        $dataSource->refresh();

        $this->assertSame('ready', $dataSource->status);
        $this->assertGreaterThanOrEqual(4, $dataSource->chunks()->count());
        $this->assertSame('imports', $import->fresh()->metadata['queue']);
        $this->assertEqualsCanonicalizing(['inventory', 'knowledge'], $import->fresh()->metadata['datasets']);
        $this->assertNotEmpty($import->fresh()->metadata['sheets']);
        $this->assertDatabaseHas('data_source_chunks', [
            'tenant_id' => $tenant->id,
            'data_source_id' => $dataSource->id,
            'chunk_type' => 'table_row',
            'sheet_name' => 'Inventario',
        ]);
        $this->assertDatabaseHas('data_source_chunks', [
            'tenant_id' => $tenant->id,
            'data_source_id' => $dataSource->id,
            'sheet_name' => 'FAQ',
        ]);

        $matches = app(DataSourceReader::class)->search($dataSource, [
            'mode' => 'inventory',
            'query' => 'mesa plegable',
            'limit' => 2,
        ]);
        $knowledgeMatches = app(DataSourceReader::class)->search($dataSource, [
            'mode' => 'knowledge',
            'question' => 'envios nacionales',
            'limit' => 1,
        ]);

        $this->assertStringContainsString('Mesa plegable', $matches[0]['content_text']);
        $this->assertStringContainsString('Sí, hacemos envíos a todo Colombia.', $knowledgeMatches[0]['content_text']);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'data_source_import_started',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'data_source_import_succeeded',
        ]);
    }

    public function test_failed_import_can_be_retried_after_fixing_the_uploaded_file(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = $this->tenantAdmin($tenant);

        $uploadResponse = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources/excel', [
                'name' => 'Catalogo Invalido',
                'file' => TestUploadedFile::fake()->createWithContent(
                    'catalogo.xlsx',
                    $this->invalidWorkbookContent(),
                ),
            ]);

        $uploadResponse->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.latest_import.status', 'pending');

        $dataSource = DataSource::query()->firstOrFail();
        $import = DataSourceImport::query()->firstOrFail();
        $storedFile = UploadedFile::query()->firstOrFail();

        try {
            $this->runImportJob($tenant, $import);
        } catch (\Throwable) {
            // The import status is persisted as failed by the importer itself.
        }

        $dataSource->refresh();
        $import->refresh();

        $this->assertSame('failed', $dataSource->status);
        $this->assertSame('failed', $import->status);

        Storage::disk('local')->put($storedFile->path, $this->validWorkbookContent());

        $retryResponse = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post(sprintf('/api/v1/data-sources/%d/imports/%d/retry', $dataSource->id, $import->id));

        $retryResponse->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.latest_import.status', 'pending');

        $this->runImportJob($tenant, $import->fresh());
        $dataSource->refresh();
        $import->refresh();

        $this->assertSame('ready', $dataSource->status);
        $this->assertSame('succeeded', $import->status);
        $this->assertSame(2, $import->attempts_count);
        $this->assertGreaterThan(0, DataSourceChunk::query()->count());
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'data_source_import_failed',
        ]);
        $this->assertDatabaseHas('agent_events', [
            'tenant_id' => $tenant->id,
            'event_type' => 'data_source_import_queued',
        ]);
    }

    public function test_retry_rejects_imports_that_have_not_failed_yet(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = $this->tenantAdmin($tenant);

        $this->actingAs($user)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources/excel', [
                'name' => 'Catalogo Pendiente',
                'file' => TestUploadedFile::fake()->createWithContent(
                    'catalogo.xlsx',
                    $this->validWorkbookContent(),
                ),
            ])
            ->assertCreated();

        $dataSource = DataSource::query()->firstOrFail();
        $import = DataSourceImport::query()->firstOrFail();

        $this->actingAs($user)
            ->withCsrf()
            ->withHeader('Accept-Language', 'es-CO')
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post(sprintf('/api/v1/data-sources/%d/imports/%d/retry', $dataSource->id, $import->id))
            ->assertConflict()
            ->assertJsonPath('code', 'data_source_import_retry_not_allowed')
            ->assertJsonPath('message', 'Solo puedes reintentar importaciones que hayan fallado.');
    }

    public function test_tenant_can_upload_txt_and_retrieve_knowledge_context(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = $this->tenantAdmin($tenant);

        $response = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources', [
                'name' => 'Politicas',
                'file' => TestUploadedFile::fake()->createWithContent(
                    'politicas.txt',
                    "Envios nacionales\nHacemos envíos a todo Colombia desde la bodega principal.",
                ),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'txt')
            ->assertJsonPath('data.status', 'pending');

        $import = DataSourceImport::query()->firstOrFail();
        $this->runImportJob($tenant, $import);

        $dataSource = DataSource::query()->firstOrFail()->refresh();
        $matches = app(DataSourceReader::class)->search($dataSource, [
            'mode' => 'knowledge',
            'question' => 'envios Colombia',
            'limit' => 1,
        ]);

        $this->assertSame('ready', $dataSource->status);
        $this->assertSame('txt', $dataSource->type);
        $this->assertStringContainsString('envíos a todo Colombia', $matches[0]['content_text']);
    }

    public function test_tenant_can_upload_csv_and_retrieve_table_rows(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = $this->tenantAdmin($tenant);

        $response = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources', [
                'name' => 'Inventario CSV',
                'file' => TestUploadedFile::fake()->createWithContent(
                    'inventario.csv',
                    "SKU,Nombre,Categoria,Precio,Disponibilidad\nSKU-777,Lampara solar,iluminacion,89900,Disponible\n",
                ),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'csv')
            ->assertJsonPath('data.status', 'pending');

        $import = DataSourceImport::query()->firstOrFail();
        $this->runImportJob($tenant, $import);

        $dataSource = DataSource::query()->firstOrFail()->refresh();
        $matches = app(DataSourceReader::class)->search($dataSource, [
            'mode' => 'inventory',
            'query' => 'lampara solar',
            'limit' => 1,
        ]);

        $this->assertSame('ready', $dataSource->status);
        $this->assertDatabaseHas('data_source_chunks', [
            'tenant_id' => $tenant->id,
            'data_source_id' => $dataSource->id,
            'chunk_type' => 'table_row',
        ]);
        $this->assertStringContainsString('Lampara solar', $matches[0]['content_text']);
        $this->assertSame('SKU-777', $matches[0]['structured_payload']['sku']);
    }

    public function test_tenant_can_upload_pdf_and_retrieve_extracted_text(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = $this->tenantAdmin($tenant);

        $response = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources', [
                'name' => 'Manual PDF',
                'file' => TestUploadedFile::fake()->createWithContent(
                    'manual.pdf',
                    $this->validPdfContent('Garantia extendida por doce meses para equipos comprados en tienda.'),
                ),
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.type', 'pdf')
            ->assertJsonPath('data.status', 'pending');

        $import = DataSourceImport::query()->firstOrFail();
        $this->runImportJob($tenant, $import);

        $dataSource = DataSource::query()->firstOrFail()->refresh();
        $matches = app(DataSourceReader::class)->search($dataSource, [
            'mode' => 'knowledge',
            'question' => 'garantia extendida',
            'limit' => 1,
        ]);

        $this->assertSame('ready', $dataSource->status);
        $this->assertStringContainsString('Garantia extendida', $matches[0]['content_text']);
    }

    public function test_upload_rejects_unsupported_source_extensions(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = $this->tenantAdmin($tenant);

        $response = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('Accept', 'application/json')
            ->withHeader('Accept-Language', 'es-CO')
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources', [
                'name' => 'Documento Word',
                'file' => TestUploadedFile::fake()->create('manual.docx', 12),
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('message', 'Revisa la información enviada.')
            ->assertJsonPath('errors.file.0', 'archivo debe ser un archivo de tipo: pdf, txt, csv, xlsx.');
    }

    public function test_upload_validation_errors_are_returned_in_english_when_requested(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $user = $this->tenantAdmin($tenant);

        $response = $this->actingAs($user)
            ->withCsrf()
            ->withHeader('Accept', 'application/json')
            ->withHeader('Accept-Language', 'en')
            ->withHeader('X-Tenant-Id', (string) $tenant->id)
            ->post('/api/v1/data-sources', [
                'name' => 'Word Document',
                'file' => TestUploadedFile::fake()->create('manual.docx', 12),
            ]);

        $response->assertUnprocessable()
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('message', 'Please review the submitted information.')
            ->assertJsonPath('errors.file.0', 'The file must be a file of type: pdf, txt, csv, xlsx.');
    }

    protected function runImportJob(Tenant $tenant, DataSourceImport $import): void
    {
        $job = new ImportExcelDataSourceJob($tenant->id, $import->id);

        try {
            $job->handle(
                app(TenantContextManager::class),
                app(DataSourceImporter::class),
                app(AgentEventRecorder::class),
            );
        } catch (\Throwable $exception) {
            $job->failed($exception);

            throw $exception;
        }
    }

    protected function validWorkbookContent(): string
    {
        return $this->buildXlsxWorkbook([
            'Inventario' => [
                ['SKU', 'Nombre', 'Categoria', 'Precio', 'Moneda', 'Cantidad', 'Disponibilidad', 'Descripcion'],
                ['SKU-001', 'Mesa plegable', 'mobiliario', '199900', 'COP', '5', 'Disponible', 'Mesa plegable de 4 puestos'],
                ['SKU-002', 'Silla ergonomica', 'mobiliario', '289900', 'COP', '2', 'Últimas unidades', 'Silla de oficina ajustable'],
            ],
            'FAQ' => [
                ['Tema', 'Pregunta', 'Respuesta', 'Keywords'],
                ['envios', '¿Hacen envíos nacionales?', 'Sí, hacemos envíos a todo Colombia.', 'envios, despacho, cobertura'],
                ['pagos', '¿Qué medios de pago aceptan?', 'Aceptamos transferencia, tarjeta y contraentrega según la ciudad.', 'pagos, tarjeta, transferencia'],
            ],
        ]);
    }

    protected function invalidWorkbookContent(): string
    {
        return 'this-is-not-a-valid-xlsx-workbook';
    }

    protected function validPdfContent(string $text): string
    {
        $escapedText = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
        $stream = 'BT /F1 18 Tf 72 720 Td ('.$escapedText.') Tj ET';
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /Resources << /Font << /F1 4 0 R >> >> /MediaBox [0 0 612 792] /Contents 5 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $objectNumber = $index + 1;
            $offsets[$objectNumber] = strlen($pdf);
            $pdf .= $objectNumber." 0 obj\n".$object."\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        return $pdf."trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n".$xrefOffset."\n%%EOF\n";
    }

    protected function tenantAdmin(Tenant $tenant): User
    {
        $user = User::factory()->create();

        TenantUser::query()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => TenantRole::TenantAdmin,
        ]);

        return $user;
    }
}
