<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\ArchiveClientAction;
use App\Modules\Billing\Actions\CreateClientAction;
use App\Modules\Billing\Actions\RestoreClientAction;
use App\Modules\Billing\Actions\UpdateClientAction;
use App\Modules\Billing\Data\BillingPlanData;
use App\Modules\Billing\Data\ClientData;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Data\InvoiceSummaryData;
use App\Modules\Billing\Http\Requests\StoreClientRequest;
use App\Modules\Billing\Http\Requests\UpdateClientRequest;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Billing\Models\Invoice;
use App\Modules\Billing\Support\BillingSchedule;
use App\Modules\Billing\Support\PaymentTotals;
use App\Modules\People\Models\Person;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
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

        $canManage = $request->user()->can('update', $client);
        $today = CarbonImmutable::now($workspace->timezone)->startOfDay();

        return Inertia::render('clients/show', [
            'client' => ClientData::fromModel($client),
            'currencies' => Currency::options(),
            'canManageClients' => $canManage,
            'plans' => $this->plans($client, $today),
            'invoices' => PaymentTotals::withPaid($client->invoices()->getQuery())
                ->orderByDesc('period_start')
                ->orderByDesc('id')
                ->get()
                ->map(InvoiceSummaryData::fromModel(...))
                ->values(),
            'today' => $today->toDateString(),
            ...($canManage ? ['teamMembers' => $this->teamMembers($workspace)] : []),
        ]);
    }

    /**
     * Each recurring invoice with the invoice already prepared for its latest
     * due month, so the card can offer "Open" instead of "Prepare".
     *
     * @return Collection<int, BillingPlanData>
     */
    private function plans(Client $client, CarbonImmutable $today): Collection
    {
        $plans = $client->billingPlans()->with(['lines.person:id,public_id', 'adjustments'])->orderBy('name')->get();
        $schedule = app(BillingSchedule::class);

        $invoices = Invoice::query()
            ->whereIn('billing_plan_id', $plans->modelKeys())
            ->get(['public_id', 'billing_plan_id', 'period_start'])
            ->keyBy(fn (Invoice $invoice) => $invoice->billing_plan_id.'|'.$invoice->period_start->toDateString());

        return $plans->map(function (BillingPlan $plan) use ($today, $schedule, $invoices) {
            $due = $schedule->latestDueCycle($plan, $today)->periodStart->toDateString();

            return BillingPlanData::fromModel($plan, $today, $invoices->get("{$plan->id}|{$due}")?->public_id);
        })->values();
    }

    /**
     * Team profiles that can be put on a recurring invoice, whether or not they
     * can sign in to SprintSync. Addressed by public id only.
     *
     * @return list<array{public_id: string, name: string, title: string|null}>
     */
    private function teamMembers(Workspace $workspace): array
    {
        return $workspace->people()
            ->orderBy('name')
            ->get(['public_id', 'name', 'title'])
            ->map(fn (Person $person) => $person->only(['public_id', 'name', 'title']))
            ->values()
            ->all();
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
