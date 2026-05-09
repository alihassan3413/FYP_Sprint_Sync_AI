<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Models;

use App\Models\User;
use App\Modules\Assistant\Models\Message;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $user_id
 * @property int|null $workspace_id
 * @property string|null $title
 * @property bool $is_archived
 * @property int $total_input_tokens
 * @property int $total_output_tokens
 */
class Conversation extends Model
{
    use HasFactory;

    protected $table = 'assistant_conversations';

    protected $fillable = [
        'user_id',
        'workspace_id',
        'title',
        'is_archived',
        'total_input_tokens',
        'total_output_tokens',
    ];

    protected $casts = [
        'is_archived' => 'boolean',
        'total_input_tokens' => 'integer',
        'total_output_tokens' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('created_at');
    }

    /**
     * Increment token totals atomically. Called after each LLM round-trip.
     * Uses raw SQL increment to avoid race conditions when two requests
     * land for the same conversation simultaneously (rare but possible).
     */
    public function recordTokenUsage(int $input, int $output): void
    {
        $this->newQuery()
            ->where('id', $this->id)
            ->update([
                'total_input_tokens' => \DB::raw("total_input_tokens + {$input}"),
                'total_output_tokens' => \DB::raw("total_output_tokens + {$output}"),
            ]);
    }
}
