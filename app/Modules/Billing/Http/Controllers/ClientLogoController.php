<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\RemoveClientLogoAction;
use App\Modules\Billing\Actions\UpdateClientLogoAction;
use App\Modules\Billing\Http\Requests\UpdateClientLogoRequest;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ClientLogoController
{
    /**
     * Logos are private: every request goes through the tenant route and the
     * client policy, and the storage path never leaves the server.
     */
    public function show(Request $request, Workspace $workspace, Client $client): StreamedResponse
    {
        abort_unless($request->user()->can('view', $client), 403);

        $disk = Storage::disk(Client::LOGO_DISK);

        abort_unless($client->hasLogo() && $disk->exists($client->logo_path), 404);

        return $disk->response($client->logo_path, null, [
            // The URL carries a content version, so the browser may keep it, but only privately.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'",
        ], 'inline');
    }

    public function store(UpdateClientLogoRequest $request, Workspace $workspace, Client $client, UpdateClientLogoAction $action): RedirectResponse
    {
        $hadLogo = $client->hasLogo();

        $action->handle($client, $request->logo(), $request->user());

        return back()->with('success', $hadLogo ? 'Logo changed.' : 'Logo added.');
    }

    public function destroy(Request $request, Workspace $workspace, Client $client, RemoveClientLogoAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('update', $client), 403);

        $action->handle($client, $request->user());

        return back()->with('success', 'Logo removed.');
    }
}
