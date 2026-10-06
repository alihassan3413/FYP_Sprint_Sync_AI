<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Support;

use App\Models\User;
use App\Modules\Desktop\Models\DesktopDevice;
use Illuminate\Support\Str;

/**
 * Issues and checks desktop tokens.
 *
 * - The token is 48 random bytes (base64url) with a recognisable prefix; it is
 *   shown to the app once and only its SHA-256 hash is stored. A plain hash is
 *   enough here (unlike passwords) because the token is long and random.
 * - A token stops working when revoked (sign out, or later by the owner) or
 *   after IDLE_DAYS without use. last_used_at is written at most once a minute.
 */
final class DesktopTokens
{
    public const PREFIX = 'ssd_';

    public const IDLE_DAYS = 60;

    /**
     * @return array{0: DesktopDevice, 1: string} the device and the plain token (returned once, never stored)
     */
    public function issue(User $user, string $deviceName, string $platform): array
    {
        $token = self::PREFIX.rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');

        $device = DesktopDevice::query()->forceCreate([
            'user_id' => $user->id,
            'name' => Str::limit(trim($deviceName) ?: 'Desktop', 100, ''),
            'platform' => $platform,
            'token_hash' => hash('sha256', $token),
            'last_used_at' => now(),
        ]);

        return [$device, $token];
    }

    public function resolve(?string $token): ?DesktopDevice
    {
        if ($token === null || ! str_starts_with($token, self::PREFIX) || strlen($token) > 200) {
            return null;
        }

        $device = DesktopDevice::query()->where('token_hash', hash('sha256', $token))->with('user')->first();

        if ($device === null || $device->isRevoked() || $device->user === null) {
            return null;
        }

        if ($device->last_used_at !== null && $device->last_used_at->lt(now()->subDays(self::IDLE_DAYS))) {
            return null;
        }

        if ($device->last_used_at === null || $device->last_used_at->lt(now()->subMinute())) {
            DesktopDevice::query()->whereKey($device->getKey())->update(['last_used_at' => now()]);
        }

        return $device;
    }

    public function revoke(DesktopDevice $device): void
    {
        DesktopDevice::query()->whereKey($device->getKey())->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
