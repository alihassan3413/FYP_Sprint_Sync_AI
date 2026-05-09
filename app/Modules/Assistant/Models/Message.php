<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $conversation_id
 * @property string $role  user | assistant | tool | system
 * @property string|null $content
 * @property array|null $tool_calls
 * @property string|null $tool_call_id
 * @property string|null $tool_status  pending | confirmed | rejected | executed | failed
 * @property string|null $provider
 * @property string|null $model
 * @property int $input_tokens
 * @property int $output_tokens
 * @property array|null $metadata
 */
class Message extends Model
{
    use HasFactory;

    protected $table = 'assistant_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'tool_status',
        'provider',
        'model',
        'input_tokens',
        'output_tokens',
        'metadata',
    ];

    protected $casts = [
        'tool_calls' => 'array',
        'metadata' => 'array',
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
    ];

    // Status constants — keep these here so refactoring is easy.
    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXECUTED = 'executed';
    public const STATUS_FAILED = 'failed';

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Convert this message into the OpenAI-compatible array format.
     * Used when building the messages array for the next LLM call.
     */
    public function toApiFormat(): array
    {
        $payload = ['role' => $this->role];

        if ($this->content !== null) {
            $payload['content'] = $this->content;
        }

        if ($this->role === 'assistant' && $this->tool_calls) {
            $payload['tool_calls'] = $this->tool_calls;
        }

        if ($this->role === 'tool' && $this->tool_call_id) {
            $payload['tool_call_id'] = $this->tool_call_id;
        }

        return $payload;
    }
}
