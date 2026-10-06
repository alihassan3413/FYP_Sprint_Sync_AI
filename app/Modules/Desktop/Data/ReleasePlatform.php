<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Data;

enum ReleasePlatform: string
{
    case MacOS = 'macos';
    case Windows = 'windows';
    case Linux = 'linux';

    public const ARCHITECTURES = ['arm64', 'x64', 'universal'];

    public function label(): string
    {
        return match ($this) {
            self::MacOS => 'macOS',
            self::Windows => 'Windows',
            self::Linux => 'Linux',
        };
    }

    /**
     * Installer file types accepted for this platform.
     *
     * @return list<string>
     */
    public function extensions(): array
    {
        return match ($this) {
            self::MacOS => ['dmg', 'zip'],
            self::Windows => ['exe', 'zip'],
            self::Linux => ['AppImage', 'deb', 'zip'],
        };
    }

    public static function archLabel(string $arch): string
    {
        return match ($arch) {
            'arm64' => 'Apple silicon / ARM',
            'x64' => 'Intel / x64',
            default => 'Universal',
        };
    }
}
