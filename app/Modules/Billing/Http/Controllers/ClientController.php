<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\ArchiveClientAction;
use App\Modules\Billing\Actions\CreateClientAction;
use App\Modules\Billing\Actions\RestoreClientAction;
use App\Modules\Billing\Actions\UpdateClientAction;
use App\Modules\Billing\Data\ClientData;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Http\Requests\StoreClientRequest;
use App\Modules\Billing\Http\Requests\UpdateClientRequest;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

final class ClientController
{
    public function index(Request $request, Workspace $workspace): Response
    {
        abort_unless($request->user()->can('viewAny', [Client::class, $workspace]), 403);

        return Inertia::render('clients/index', [
            'clients' => $workspace->clients()
                ->orderBy('name')
                ->get()
                ->map(ClientData::fromModel(...))
                ->values(),
            'currencies' => Currency::options(),
            'canManageClients' => $request->user()->can('create', [Client::class, $workspace]),
        ]);
    }

    public function show(Request $request, Workspace $workspace, Client $client): Response
    {
        abort_unless($request->user()->can('view', $client), 403);

        return Inertia::render('clients/show', [
            'client' => ClientData::fromModel($client),
            'currencies' => Currency::options(),
            'canManageClients' => $request->user()->can('update', $client),
        ]);
    }

    public function store(StoreClientRequest $request, Workspace $workspace, CreateClientAction $action): RedirectResponse
    {
        $client = $action->handle($workspace, $request->toDTO(), $request->user());

        return to_route('workspace.clients.show', ['workspace' => $workspace->slug, 'client' => $client])
            ->with('success', "{$client->name} added.");
    }

    public function update(UpdateClientRequest $request, Workspace $workspace, Client $client, UpdateClientAction $action): RedirectResponse
    {
        $client = $action->handle($client, $request->toDTO(), $request->user());

        return back()->with('success', "{$client->name} updated.");
    }

    public function archive(Request $request, Workspace $workspace, Client $client, ArchiveClientAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('archive', $client), 403);

        $action->handle($client, $request->user());

        return to_route('workspace.clients.index', ['workspace' => $workspace->slug])
            ->with('success', "{$client->name} archived.");
    }

    public function restore(Request $request, Workspace $workspace, Client $client, RestoreClientAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('archive', $client), 403);

        $action->handle($client, $request->user());

        return back()->with('success', "{$client->name} restored.");
    }
}
