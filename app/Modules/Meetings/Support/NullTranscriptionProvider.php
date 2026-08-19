<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Support;

use App\Modules\Meetings\Contracts\TranscriptionProvider;
use App\Modules\Meetings\Data\TranscriptionResult;
use App\Modules\Meetings\Exceptions\TranscriptionException;

final class NullTranscriptionProvider implements TranscriptionProvider
{
    public function name(): string
    {
        return 'null';
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function transcribe(string $absolutePath, string $filename): TranscriptionResult
    {
        throw TranscriptionException::notConfigured($this->name());
    }
}
