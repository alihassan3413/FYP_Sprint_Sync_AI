<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Http\Controllers;

use App\Modules\Attachments\Actions\StoreAttachmentAction;
use App\Modules\Attachments\Http\Requests\StoreAttachmentRequest;
use App\Modules\Attachments\Models\Attachment;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AttachmentController
{
    public function store(StoreAttachmentRequest $request, StoreAttachmentAction $action): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->activeWorkspace();

        if ($workspace === null) {
            return response()->json(['message' => 'No active workspace is selected.'], 422);
        }

        $attachment = $action->handle(
            $workspace,
            $user,
            $request->file('file'),
            (string) config('attachments.disk'),
        );

        return response()->json([
            'id' => $attachment->id,
            'name' => $attachment->name,
            'mime' => $attachment->mime,
            'size' => $attachment->size,
            'width' => $attachment->width,
            'height' => $attachment->height,
            'url' => route('attachments.show', $attachment),
        ], 201);
    }

    public function show(Attachment $attachment): StreamedResponse
    {
        abort_unless(request()->user()?->can('view', $attachment) === true, 403);

        $disk = Storage::disk($attachment->disk);

        abort_unless($disk->exists($attachment->path), 404);

        return $disk->response($attachment->path, $attachment->name, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, max-age=3600',
            // Without this a browser is free to ignore the declared type and
            // sniff the bytes instead, which turns a mislabelled upload back
            // into whatever it really is.
            'X-Content-Type-Options' => 'nosniff',
        ], $this->dispositionFor($attachment));
    }

    /**
     * Only the types a browser renders harmlessly are shown in place. Anything
     * else downloads, so an upload can never execute in the app's own origin
     * on the way to being read.
     */
    private function dispositionFor(Attachment $attachment): string
    {
        $mime = strtolower($attachment->mime);

        $isInlineSafe = $mime === 'application/pdf'
            || $mime === 'text/plain'
            || str_starts_with($mime, 'audio/')
            || str_starts_with($mime, 'video/')
            // SVG is an image that can carry script, so it downloads like a document.
            || (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml');

        return $isInlineSafe
            ? ResponseHeaderBag::DISPOSITION_INLINE
            : ResponseHeaderBag::DISPOSITION_ATTACHMENT;
    }

    public function destroy(Attachment $attachment): SymfonyResponse
    {
        abort_unless(request()->user()?->can('delete', $attachment) === true, 403);

        $attachment->delete();

        return response()->noContent();
    }
}
