<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Actions;

use App\Models\User;
use App\Modules\Attachments\Models\Attachment;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class StoreAttachmentAction
{
    private const PREVIEW_MAX_EDGE = 1568;

    private const PREVIEW_SKIP_EDGE = 1100;

    public function handle(Workspace $workspace, User $uploader, UploadedFile $file, string $disk = 'local'): Attachment
    {
        $directory = "workspaces/{$workspace->id}/attachments";
        $path = $file->store($directory, $disk);

        $attachment = new Attachment([
            'workspace_id' => $workspace->id,
            'uploaded_by' => $uploader->id,
            'disk' => $disk,
            'path' => $path,
            'name' => mb_substr($file->getClientOriginalName() ?: 'upload', 0, 180),
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size' => (int) $file->getSize(),
        ]);

        if ($attachment->isImage()) {
            $this->measureAndDownscale($attachment, $disk, $directory);
        }

        $attachment->save();

        return $attachment;
    }

    private function measureAndDownscale(Attachment $attachment, string $disk, string $directory): void
    {
        $storage = Storage::disk($disk);
        $absolute = $storage->path($attachment->path);

        $size = @getimagesize($absolute);

        if ($size === false) {
            return;
        }

        [$width, $height] = $size;
        $attachment->width = $width;
        $attachment->height = $height;

        if (max($width, $height) <= self::PREVIEW_SKIP_EDGE) {
            return;
        }

        try {
            $image = @imagecreatefromstring((string) file_get_contents($absolute));

            if ($image === false) {
                return;
            }

            $scale = self::PREVIEW_MAX_EDGE / max($width, $height);
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            imagedestroy($image);

            if ($resized === false) {
                return;
            }

            ob_start();
            imagejpeg($resized, null, 82);
            $bytes = (string) ob_get_clean();
            imagedestroy($resized);

            $previewPath = $directory.'/preview-'.pathinfo($attachment->path, PATHINFO_FILENAME).'.jpg';
            $storage->put($previewPath, $bytes);

            $attachment->preview_path = $previewPath;
        } catch (Throwable $e) {
            Log::warning('Could not build a preview copy of an upload', [
                'path' => $attachment->path,
                'exception' => $e,
            ]);
        }
    }
}
