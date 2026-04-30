<?php

namespace App\Support\Observability;

use App\Jobs\ImportExcelDataSourceJob;
use App\Jobs\ProcessIncomingMessageJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class HealthCheckService
{
    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        $checks = [
            'database' => $this->databaseCheck(),
            'cache' => $this->cacheCheck(),
            'queue' => $this->queueCheck(),
            'horizon' => $this->horizonCheck(),
        ];

        $status = collect($checks)->contains(
            static fn (array $check): bool => $check['status'] === 'failed',
        ) ? 'degraded' : 'ok';

        return [
            'status' => $status,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function databaseCheck(): array
    {
        $startedAt = microtime(true);

        try {
            DB::select('select 1');

            return [
                'status' => 'ok',
                'connection' => config('database.default'),
                'latency_ms' => $this->durationMs($startedAt),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'connection' => config('database.default'),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function cacheCheck(): array
    {
        $startedAt = microtime(true);
        $key = 'healthcheck:'.bin2hex(random_bytes(8));

        try {
            Cache::put($key, 'ok', now()->addSeconds(10));
            $value = Cache::get($key);
            Cache::forget($key);

            if ($value !== 'ok') {
                return [
                    'status' => 'failed',
                    'store' => config('cache.default'),
                    'error' => 'Cache round-trip returned an unexpected value.',
                ];
            }

            return [
                'status' => 'ok',
                'store' => config('cache.default'),
                'latency_ms' => $this->durationMs($startedAt),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'store' => config('cache.default'),
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function queueCheck(): array
    {
        return [
            'status' => config('queue.failed.driver') === 'null' ? 'failed' : 'ok',
            'connection' => config('queue.default'),
            'failed_job_driver' => config('queue.failed.driver'),
            'queues' => [
                ProcessIncomingMessageJob::QUEUE,
                ImportExcelDataSourceJob::QUEUE,
                'default',
            ],
            'processing_job' => [
                'tries' => 3,
                'backoff_seconds' => [5, 15, 45],
            ],
            'import_job' => [
                'tries' => 3,
                'backoff_seconds' => [5, 15, 45],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function horizonCheck(): array
    {
        return [
            'status' => config('queue.default') === 'redis' ? 'ok' : 'warning',
            'prefix' => config('horizon.prefix'),
            'supervisors' => array_keys(config('horizon.defaults', [])),
            'trim' => config('horizon.trim', []),
        ];
    }

    protected function durationMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
