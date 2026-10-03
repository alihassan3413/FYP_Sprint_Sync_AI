<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\SaveBillingPlanAction;
use App\Modules\Billing\Actions\SetBillingPlanPausedAction;
use App\Modules\Billing\Http\Requests\StoreBillingPlanRequest;
use App\Modules\Billing\Http\Requests\UpdateBillingPlanRequest;
use App\Modules\Billing\Models\BillingPlan;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Recurring invoices are edited in a sheet on the client page, so every
 * action returns there.
 */
final class BillingPlanController
{
    public function store(StoreBillingPlanRequest $request, Workspace $workspace, Client $client, SaveBillingPlanAction $action): RedirectResponse
    {
        $plan = $action->handle($client, null, $request->toDTO(), $request->user());

        return back()->with('success', "Recurring invoice \"{$plan->name}\" saved.");
    }

    public function update(UpdateBillingPlanRequest $request, Workspace $workspace, Client $client, BillingPlan $billingPlan, SaveBillingPlanAction $action): RedirectResponse
    {
        $plan = $action->handle($client, $billingPlan, $request->toDTO(), $request->user());

        return back()->with('success', "Recurring invoice \"{$plan->name}\" updated. Future invoices will use the changes.");
    }

    public function pause(Request $request, Workspace $workspace, Client $client, BillingPlan $billingPlan, SetBillingPlanPausedAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('update', $billingPlan), 403);

        $action->handle($billingPlan, true, $request->user());

        return back()->with('success', "\"{$billingPlan->name}\" is paused. No invoices will be prepared until you resume it.");
    }

    public function resume(Request $request, Workspace $workspace, Client $client, BillingPlan $billingPlan, SetBillingPlanPausedAction $action): RedirectResponse
    {
        abort_unless($request->user()->can('update', $billingPlan), 403);

        $action->handle($billingPlan, false, $request->user());

        return back()->with('success', "\"{$billingPlan->name}\" is running again.");
    }
}
