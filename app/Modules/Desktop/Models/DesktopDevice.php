<?php

declare(strict_types=1);

namespace App\Modules\Desktop\Models;

use App\Models\User;
use App\Support\Models\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $public_id
 * @property int $user_id
 * @property string $name
 * @property string $platform
 * @property string $token_hash SHA-256 of the token; the token itself is never stored
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 */
final class DesktopDevice extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
