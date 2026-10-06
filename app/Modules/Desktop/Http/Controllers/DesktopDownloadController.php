<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Http\Controllers;

use App\Modules\Desktop\Data\ReleaseChannel;
use App\Modules\Desktop\Data\ReleasePlatform;
use App\Modules\Desktop\Models\DesktopRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Desktop downloads. A release is looked up only by channel, platform and
 * architecture (all from fixed lists in the route); the file is streamed from
 * the private disk, never redirected to, and no token is ever in the address.
 */
final class DesktopDownloadController
{
    public function stable(string $platform, string $arch): StreamedResponse
    {
        return $this->stream(ReleaseChannel::Stable, $platform, $arch);
    }

    /** UAT builds: signed-in people only (the route requires a session). */
    public function uat(string $platform, string $arch): StreamedResponse
    {
        return $this->stream(ReleaseChannel::Uat, $platform, $arch);
    }

    /** For the desktop app (Bearer token): the newest build on its channel, for a later update check. */
    public function latest(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'channel' => ['required', Rule::enum(ReleaseChannel::class)],
            'platform' => ['required', Rule::enum(ReleasePlatform::class)],
            'arch' => ['required', Rule::in(ReleasePlatform::ARCHITECTURES)],
        ]);

        $release = DesktopRelease::latest(ReleaseChannel::from($validated['channel']), ReleasePlatform::from($validated['platform']), $validated['arch']);

        return response()->json(['release' => $release?->toDownload()]);
    }

    private function stream(ReleaseChannel $channel, string $platform, string $arch): StreamedResponse
    {
        $release = DesktopRelease::latest($channel, ReleasePlatform::from($platform), $arch);

        abort_if($release === null || ! Storage::disk('local')->exists($release->path), 404);

        return Storage::disk('local')->download($release->path, $release->fileName(), [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'X-Checksum-SHA256' => $release->sha256,
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
