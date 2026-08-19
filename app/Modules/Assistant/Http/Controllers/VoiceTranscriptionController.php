<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Modules\Assistant\Http\Requests\TranscribeVoiceRequest;
use App\Modules\Meetings\Contracts\TranscriptionProvider;
use App\Modules\Meetings\Exceptions\TranscriptionException;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Turns a browser recording into text for the assistant's chat endpoint.
 *
 * Speech-to-text only. The reply is spoken client-side with the browser's
 * speech synthesis, so no audio is ever generated or stored server-side.
 * The recording itself is deleted as soon as the provider has read it.
 */
final class VoiceTranscriptionController
{
    public function __invoke(
        TranscribeVoiceRequest $request,
        TranscriptionProvider $transcriber,
    ): JsonResponse {
        if (! $transcriber->isConfigured()) {
            return response()->json([
                'message' => 'Voice input is not available right now.',
            ], 503);
        }

        $file = $request->file('audio');
        $path = $file->getRealPath();

        try {
            $result = $transcriber->transcribe($path, $file->getClientOriginalName() ?: 'speech.webm');
        } catch (TranscriptionException $e) {
            report($e);

            return response()->json([
                'message' => 'I could not make out that recording. Please try again.',
            ], 422);
        } finally {
            $this->discard($path);
        }

        return response()->json([
            'text' => $result->text,
            'language' => $result->language,
            'confidence' => $result->confidence,
        ]);
    }

    /**
     * Uploaded temp files are cleaned up by PHP at request end, but voice
     * recordings carry whatever the user happened to say near the microphone.
     * Remove them as soon as the transcript exists rather than waiting.
     */
    private function discard(?string $path): void
    {
        if ($path === null || ! is_file($path)) {
            return;
        }

        try {
            @unlink($path);
        } catch (Throwable) {
            // Nothing actionable; PHP removes the temp file at request end.
        }
    }
}
