<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Desktop\Actions\PublishDesktopReleaseAction;
use App\Modules\Desktop\Data\ReleaseChannel;
use App\Modules\Desktop\Data\ReleasePlatform;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * php artisan desktop:publish-release desktop/dist/SprintSync-0.1.0-macos-arm64.dmg
 *     --platform=macos --arch=arm64 --channel=uat
 *
 * The version defaults to desktop/package.json, the one place it is set (the
 * app reads the same value), and a file named for another version is refused.
 */
final class PublishDesktopReleaseCommand extends Command
{
    protected $signature = 'desktop:publish-release
        {file : The built installer}
        {--platform= : macos, windows or linux}
        {--arch= : arm64, x64 or universal}
        {--release= : The app version (default: desktop/package.json)}
        {--channel=uat : uat (signed-in testers) or stable (public)}
        {--min-os= : e.g. "macOS 12" (optional)}
        {--signed : The build is code-signed (and notarized on macOS)}';

    protected $description = 'Publish a SprintSync Desktop build for download';

    public function handle(PublishDesktopReleaseAction $publish): int
    {
        $channel = ReleaseChannel::tryFrom((string) $this->option('channel'));
        $platform = ReleasePlatform::tryFrom((string) $this->option('platform'));

        if ($channel === null || $platform === null) {
            $this->error('Use --channel=uat|stable and --platform=macos|windows|linux.');

            return self::FAILURE;
        }

        if ($channel === ReleaseChannel::Stable && ! $this->option('signed')) {
            $this->warn('Publishing an unsigned build to the public stable channel. Visitors will see operating-system warnings.');
        }

        $version = (string) ($this->option('release') ?: json_decode((string) @file_get_contents(base_path('desktop/package.json')), true)['version'] ?? '');

        if (preg_match('/-(\d+\.\d+\.\d+(?:-[0-9A-Za-z]*[0-9.][0-9A-Za-z.]*)?)-/', basename((string) $this->argument('file')), $named) === 1 && $named[1] !== $version) {
            $this->error("The file is named for version {$named[1]}, but this release is {$version}.");

            return self::FAILURE;
        }

        try {
            $release = $publish->handle(
                (string) $this->argument('file'),
                $channel,
                $platform,
                (string) $this->option('arch'),
                $version,
                $this->option('min-os') ?: null,
                (bool) $this->option('signed'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Published {$release->fileName()} ({$release->channel->value}), SHA-256 {$release->sha256}.");

        return self::SUCCESS;
    }
}
