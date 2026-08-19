<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Contracts;

use App\Modules\Meetings\Data\TranscriptionResult;
use App\Modules\Meetings\Exceptions\TranscriptionException;

interface TranscriptionProvider
{
    /**
     * @throws TranscriptionException
     */
    public function transcribe(string $absolutePath, string $filename): TranscriptionResult;

    public function name(): string;

    public function isConfigured(): bool;
}
