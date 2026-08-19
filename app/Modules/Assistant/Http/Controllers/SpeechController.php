<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Http\Controllers;

use App\Modules\Assistant\Contracts\SpeechProvider;
use App\Modules\Assistant\Exceptions\SpeechException;
use App\Modules\Assistant\Http\Requests\SpeakRequest;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Voices one chunk of an assistant reply.
 *
 * The client sends sentence-sized chunks as the reply streams in, so playback
 * starts before the full answer exists. A non-2xx here is not an error state
 * for the user: the client falls back to browser speech synthesis.
 */
final class SpeechController
{
    public function __invoke(SpeakRequest $request, SpeechProvider $speech): SymfonyResponse
    {
        if (! $speech->isConfigured()) {
            return response()->json(['message' => 'Speech output is not available.'], 503);
        }

        try {
            $audio = $speech->synthesize(
                $request->string('text')->toString(),
                $request->input('voice'),
            );
        } catch (SpeechException $e) {
            report($e);

            return response()->json(['message' => 'Speech output failed.'], 502);
        }

        return new Response($audio, 200, [
            'Content-Type' => $speech->contentType(),
            'Content-Length' => (string) strlen($audio),
            // Utterances are user-specific and short-lived; never let a shared
            // cache hold on to what the assistant said to someone.
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
