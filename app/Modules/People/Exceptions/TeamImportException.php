<?php

declare(strict_types=1);

namespace App\Modules\People\Exceptions;

use App\Exceptions\AppException;
use App\Support\Errors\ErrorCode;

final class TeamImportException extends AppException
{
    public static function unsupportedFile(): self
    {
        return self::invalid('Upload an .xlsx or .csv file.');
    }

    public static function unreadable(): self
    {
        return self::invalid('That file could not be read. Save it again as .xlsx or .csv and retry.');
    }

    public static function missingColumns(array $columns): self
    {
        return self::invalid('Missing required columns: '.implode(', ', $columns).'. Start from the template.');
    }

    public static function tooManyRows(int $max): self
    {
        return self::invalid("A file can have at most {$max} teammates. Split it into smaller files.");
    }

    public static function empty(): self
    {
        return self::invalid('That file has no teammate rows.');
    }

    public static function duplicateFile(): self
    {
        return new self(
            code: ErrorCode::PEOPLE_IMPORT_DUPLICATE,
            status: 409,
            message: 'This file is already being imported. Open that import instead.',
        );
    }

    public static function notConfirmable(): self
    {
        return new self(
            code: ErrorCode::PEOPLE_IMPORT_NOT_CONFIRMABLE,
            status: 409,
            message: 'This import has already been confirmed, cancelled or expired.',
        );
    }

    private static function invalid(string $message): self
    {
        return new self(code: ErrorCode::PEOPLE_IMPORT_FILE_INVALID, status: 422, message: $message);
    }
}
