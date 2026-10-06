<?php

declare(strict_types=1);

namespace App\Modules\Billing\Models;

use App\Modules\Billing\Data\MailEncryption;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The workspace's own email server for client invoice emails. The password is
 * encrypted at rest and hidden from every array/JSON form of this model; it is
 * only read when a connection is opened.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $host
 * @property int $port
 * @property MailEncryption $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string $from_email
 * @property string $from_name
 * @property string|null $reply_to_email
 * @property Carbon|null $verified_at last successful test email; cleared when settings change
 * @property Carbon|null $last_tested_at
 * @property string|null $last_test_error sanitised, never contains credentials
 */
final class WorkspaceMailSettings extends Model
{
    protected $fillable = ['host', 'port', 'encryption', 'username', 'from_email', 'from_name', 'reply_to_email'];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'encryption' => MailEncryption::class,
            'password' => 'encrypted',
            'verified_at' => 'datetime',
            'last_tested_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
