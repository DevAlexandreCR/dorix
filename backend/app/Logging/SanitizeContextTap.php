<?php

namespace App\Logging;

use App\Support\Observability\ObservabilityPayloadSanitizer;
use Monolog\Logger;
use Monolog\LogRecord;

class SanitizeContextTap
{
    public function __construct(
        protected ObservabilityPayloadSanitizer $sanitizer,
    ) {
    }

    public function __invoke(Logger $logger): void
    {
        $logger->pushProcessor(function (LogRecord $record): LogRecord {
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
