<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Actions;

use App\Modules\Desktop\Data\ReleaseChannel;
use App\Modules\Desktop\Data\ReleasePlatform;
use App\Modules\Desktop\Models\DesktopRelease;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Adds a built installer to the release list: copied onto the private disk
 * under a path SprintSync generates, with its size and SHA-256 recorded.
 * Run by whoever builds the app (desktop:publish-release); never reachable
 * from a web request.
 */
final class PublishDesktopReleaseAction
{
    public const VERSION_PATTERN = '/^\d{1,4}\.\d{1,4}\.\d{1,4}(-[0-9A-Za-z.]{1,20})?$/';

    public function handle(string $file, ReleaseChannel $channel, ReleasePlatform $platform, string $arch, string $version, ?string $minOs, bool $signed): DesktopRelease
    {
        if (! is_file($file) || is_link($file) || ! is_readable($file)) {
            throw new InvalidArgumentException("{$file} is not a readable file.");
        }

        $extension = Str::endsWith(Str::lower($file), '.appimage') ? 'AppImage' : Str::lower(pathinfo($file, PATHINFO_EXTENSION));

        if (! in_array($extension, $platform->extensions(), true)) {
            throw new InvalidArgumentException("A {$platform->label()} release must be one of: ".implode(', ', $platform->extensions()).'.');
        }

        if (! in_array($arch, ReleasePlatform::ARCHITECTURES, true)) {
            throw new InvalidArgumentException('Architecture must be one of: '.implode(', ', ReleasePlatform::ARCHITECTURES).'.');
        }

        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw new InvalidArgumentException('Version must look like 1.2.3 or 1.2.3-uat.1.');
        }

        if (DesktopRelease::query()->where(['channel' => $channel, 'platform' => $platform, 'arch' => $arch, 'version' => $version])->exists()) {
            throw new InvalidArgumentException("{$version} is already published for {$channel->value} {$platform->value} {$arch}.");
        }

        $release = new DesktopRelease;
        $release->forceFill([
            'channel' => $channel,
            'platform' => $platform,
            'arch' => $arch,
            'version' => $version,
            'extension' => $extension,
            'size_bytes' => filesize($file),
            'sha256' => hash_file('sha256', $file),
            'min_os' => $minOs,
            'signed' => $signed,
            'released_at' => now(),
        ]);
        $release->public_id = $release->newUniqueId();
        $release->path = 'desktop-releases/'.$release->public_id.'/'.$release->fileName();

        $stream = fopen($file, 'rb');
        try {
            Storage::disk('local')->writeStream($release->path, $stream);
        } finally {
            fclose($stream);
        }

        $release->save();

        return $release;
    }
}
