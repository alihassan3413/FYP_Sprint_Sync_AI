<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Data;

final readonly class TranscriptionResult
{
    public function __construct(
        public string $text,
        public ?string $language,
        public ?int $confidence,
        public string $provider,
        public ?string $model,
    ) {}
}
