<?php

declare(strict_types=1);

namespace App\Modules\Analytics\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One specific thing found wrong (or right) about a project, with the numbers
 * that justify it. The assistant reads these out; it never has to judge for
 * itself, which is what keeps it from inventing a diagnosis.
 */
#[TypeScript]
final class HealthSignalData extends Data
{
    public function __construct(
        public string $code,
        /** critical | warning | note */
        public string $severity,
        public string $headline,
        public string $detail,
        public ?string $suggestion = null,
    ) {}

    public static function critical(string $code, string $headline, string $detail, ?string $suggestion = null): self
    {
        return new self($code, 'critical', $headline, $detail, $suggestion);
    }

    public static function warning(string $code, string $headline, string $detail, ?string $suggestion = null): self
    {
        return new self($code, 'warning', $headline, $detail, $suggestion);
    }

    public static function note(string $code, string $headline, string $detail, ?string $suggestion = null): self
    {
        return new self($code, 'note', $headline, $detail, $suggestion);
    }
}
