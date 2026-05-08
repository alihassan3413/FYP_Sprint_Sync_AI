<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Support\Errors\ErrorCode;
use Exception;
use Throwable;

class AppException extends Exception
{
    protected string $errorCode;

    protected int $statusCode;

    protected array $meta;

    protected ?bool $shouldReport;

    public function __construct(
        string $code = ErrorCode::INTERNAL_ERROR,
        int $status = 500,
        string $message = '',
        array $meta = [],
        ?bool $shouldReport = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
        $this->errorCode = $code;
        $this->statusCode = $status;
        $this->meta = $meta;
        $this->shouldReport = $shouldReport;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function shouldReport(): bool
    {
        return $this->shouldReport ?? $this->statusCode >= 500;
    }
}
