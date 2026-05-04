<?php

declare(strict_types=1);

namespace App\Support\Errors;

final class ErrorPayload
{
    public function __construct(
        public readonly string $code,
        public readonly string $message,
        public readonly int $status,
        public readonly ?array $fields = null,
        public readonly array $meta = [],
        public readonly string $traceId = '',
    ) {}

    public function toArray(): array
    {
        return [
            'success' => false,
            'error' => [
                'code' => $this->code,
                'message' => $this->message,
                'status' => $this->status,
                'fields' => $this->fields,
                'meta' => $this->meta,
                'traceId' => $this->traceId,
            ],
        ];
    }
}
