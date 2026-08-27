<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Models;

use App\Models\User;
use App\Modules\Attachments\Database\Factories\AttachmentFactory;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $uploaded_by
 * @property string|null $attachable_type
 * @property int|null $attachable_id
 * @property string $disk
 * @property string $path
 * @property string|null $preview_path
 * @property string $name
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 */
final class Attachment extends Model
{
    /** @use HasFactory<AttachmentFactory> */
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'uploaded_by',
        'attachable_type',
        'attachable_id',
        'disk',
        'path',
        'preview_path',
        'name',
        'mime',
        'size',
        'width',
        'height',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }

    public function isClaimed(): bool
    {
        return $this->attachable_type !== null;
    }

    public function previewContents(): ?string
    {
        $path = $this->preview_path ?? $this->path;
        $disk = Storage::disk($this->disk);

        return $disk->exists($path) ? $disk->get($path) : null;
    }

    public function delete(): ?bool
    {
        $disk = Storage::disk($this->disk);

        foreach (array_filter([$this->path, $this->preview_path]) as $path) {
            $disk->delete($path);
        }

        return parent::delete();
    }

    protected static function newFactory()
    {
        return AttachmentFactory::new();
    }
}
