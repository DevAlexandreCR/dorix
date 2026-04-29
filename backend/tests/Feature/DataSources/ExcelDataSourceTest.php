<?php

namespace Tests\Feature\DataSources;

use App\Domain\DataSources\Contracts\DataSourceImporter;
use App\Jobs\ImportExcelDataSourceJob;
use App\Domain\DataSources\Contracts\DataSourceReader;
use App\Models\DataSource;
use App\Models\DataSourceChunk;
use App\Models\DataSourceImport;
use App\Models\Tenant;
use App\Models\UploadedFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile as TestUploadedFile;
use Illuminate\Support\Facades\Storage;
use App\Support\Tenancy\TenantContextManager;
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

        $response = $this->withHeader('X-Tenant-Id', (string) $tenant->id)
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
    }

    public function test_failed_import_can_be_retried_after_fixing_the_uploaded_file(): void
    {
        Storage::fake('local');

        $tenant = Tenant::query()->create([
            'name' => 'Acme',
            'slug' => 'acme',
        ]);

        $uploadResponse = $this->withHeader('X-Tenant-Id', (string) $tenant->id)
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

        $retryResponse = $this->withHeader('X-Tenant-Id', (string) $tenant->id)
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
    }

    protected function runImportJob(Tenant $tenant, DataSourceImport $import): void
    {
        $job = new ImportExcelDataSourceJob($tenant->id, $import->id);
        $job->handle(
            app(TenantContextManager::class),
            app(DataSourceImporter::class),
        );
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
}
