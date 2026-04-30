<?php

namespace App\Logging;

use App\Support\Observability\ObservabilityPayloadSanitizer;
use Illuminate\Log\Logger as IlluminateLogger;
use Monolog\LogRecord;
use Monolog\Logger as MonologLogger;

class SanitizeContextTap
{
    public function __construct(
        protected ObservabilityPayloadSanitizer $sanitizer,
    ) {
    }

    public function __invoke(IlluminateLogger|MonologLogger $logger): void
    {
        $monolog = $logger instanceof IlluminateLogger
            ? $logger->getLogger()
            : $logger;

        $monolog->pushProcessor(function (LogRecord $record): LogRecord {
            return $record->with(
                message: $this->sanitizedMessage($record->message),
                context: $this->sanitizeRecordSection($record->context),
                extra: $this->sanitizeRecordSection($record->extra),
            );
        });
    }

    /**
     * @param  array<string, mixed>  $section
     * @return array<string, mixed>
     */
    protected function sanitizeRecordSection(array $section): array
    {
        $sanitized = $this->sanitizer->sanitizeForLogs($section);

        return is_array($sanitized) ? $sanitized : ['value' => $sanitized];
    }

    protected function sanitizedMessage(string $message): string
    {
        return $this->sanitizer->previewString($message, 180);
    }
}
