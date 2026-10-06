<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\EnterExchangeRateAction;
use App\Modules\Billing\Actions\SetReportingCurrencyAction;
use App\Modules\Billing\Data\Currency;
use App\Modules\Billing\Http\Requests\EnterExchangeRateRequest;
use App\Modules\Billing\Http\Requests\FinancePeriodRequest;
use App\Modules\Billing\Models\ExchangeRate;
use App\Modules\Billing\Models\WorkspaceFinanceSettings;
use App\Modules\Billing\Support\FinanceOverview;
use App\Modules\Workspace\Data\FinancePermission;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Finance → Overview: invoiced, received and expenses for a period, and the
 * operating result before team compensation. Owner-only.
 */
final class FinanceOverviewController
{
    public function show(FinancePeriodRequest $request, Workspace $workspace, FinanceOverview $overview): Response
    {
        abort_unless($workspace->allowsFinance($request->user(), FinancePermission::View), 403);

        $period = $request->period();
        $reporting = WorkspaceFinanceSettings::reportingCurrency($workspace->id);
        /* Viewing in another currency is just a view: it changes no setting. */
        $display = Currency::tryFrom((string) $request->query('currency')) ?? $reporting;

        return Inertia::render('finance/overview', [
            'period' => ['key' => $period['key'], 'from' => $period['from']->toDateString(), 'to' => $period['to']->toDateString()],
            'reportingCurrency' => $reporting->value,
            'displayCurrency' => $display->value,
            'currencies' => Currency::options(),
            'overview' => $overview->handle($workspace, $period['from'], $period['to'], $display),
            'rates' => ExchangeRate::query()
                ->where('workspace_id', $workspace->id)
                ->with('creator:id,name')
                ->orderByDesc('rate_date')
                ->orderByDesc('id')
                ->limit(20)
                ->get()
                ->map(fn (ExchangeRate $rate) => [
                    'public_id' => $rate->public_id,
                    'base' => $rate->base_currency->value,
                    'quote' => $rate->quote_currency->value,
                    'rate' => $rate->rate,
                    'rate_date' => $rate->rate_date->toDateString(),
                    'entered_by' => $rate->creator?->name,
                ])->values(),
            'canManage' => $workspace->allowsFinance($request->user(), FinancePermission::Manage),
        ]);
    }

    public function updateReportingCurrency(Request $request, Workspace $workspace, SetReportingCurrencyAction $action): RedirectResponse
    {
        abort_unless($workspace->allowsFinance($request->user(), FinancePermission::Manage), 403);

        $validated = $request->validate(['currency' => ['required', Rule::enum(Currency::class)]]);
        $action->handle($workspace, Currency::from($validated['currency']), $request->user());

        return back()->with('success', 'Reporting currency saved. Stored amounts are unchanged.');
    }

    public function storeRate(EnterExchangeRateRequest $request, Workspace $workspace, EnterExchangeRateAction $action): RedirectResponse
    {
        $action->handle(
            $workspace,
            Currency::from($request->validated('base_currency')),
            Currency::from($request->validated('quote_currency')),
            $request->rate(),
            $request->rateDate(),
            $request->user(),
        );

        return back()->with('success', 'Exchange rate saved. Totals already converted keep their rate.');
    }
}
