<?php

namespace App\Support\Http;

use RuntimeException;
use Throwable;

class ApiException extends RuntimeException
{
    public function __construct(
        protected string $errorCode,
        protected string $translationKey,
        protected array $translationContext = [],
        protected int $status = 422,
        ?Throwable $previous = null,
    ) {
        parent::__construct((string) __($translationKey, $translationContext), previous: $previous);
    }

    public function codeName(): string
    {
        return $this->errorCode;
    }

    public function translationKey(): string
    {
        return $this->translationKey;
    }

    /**
     * @return array<string, scalar|null>
     */
    public function translationContext(): array
    {
        return $this->translationContext;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function translatedMessage(): string
    {
        return (string) __($this->translationKey, $this->translationContext);
    }
}
