<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

final class UntrustedText
{
    public const INLINE_LIMIT = 200;

    public const BLOCK_LIMIT = 500;

    public static function inline(?string $value, int $limit = self::INLINE_LIMIT): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = self::neutralize($value, keepNewlines: false);
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : mb_strimwidth($value, 0, $limit, '…');
    }

    public static function block(?string $value, int $limit = self::BLOCK_LIMIT): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = self::neutralize($value, keepNewlines: true);
        $value = trim((string) preg_replace("/\n{3,}/u", "\n\n", $value));

        return $value === '' ? null : mb_strimwidth($value, 0, $limit, '…');
    }

    private static function neutralize(string $value, bool $keepNewlines): string
    {
        $controlPattern = $keepNewlines ? '/[^\P{Cc}\n]/u' : '/\p{Cc}/u';

        $value = (string) preg_replace($controlPattern, ' ', $value);
        $value = (string) preg_replace('/\p{Cf}/u', '', $value);

        return str_replace(['<|', '|>'], ['<│', '│>'], $value);
    }
}
