<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\SaveInvoicingProfileAction;
use App\Modules\Billing\Actions\UpdateInvoicingLogoAction;
use App\Modules\Billing\Http\Requests\SaveInvoicingProfileRequest;
use App\Modules\Billing\Http\Requests\UpdateInvoicingLogoRequest;
use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Invoicing: who the workspace's invoices are from.
 */
final class InvoicingSettingsController
{
    public function edit(Request $request, Workspace $workspace): Response
    {
        $this->authorize($request, $workspace);

        $profile = $workspace->invoicingProfile;

        return Inertia::render('workspace/settings/Invoicing', [
            'details' => $profile?->only(InvoicingProfile::DETAIL_FIELDS),
            'logoUrl' => $profile?->logo_path === null ? null : route('workspace.invoicing.logo.show', [
                'workspace' => $workspace->slug,
                'v' => substr(basename($profile->logo_path, '.png'), 0, 12),
            ]),
        ]);
    }

    public function update(SaveInvoicingProfileRequest $request, Workspace $workspace, SaveInvoicingProfileAction $action): RedirectResponse
    {
        $action->handle($workspace, $request->validated(), $request->user());

        return back()->with('success', 'Invoicing details saved. New invoices will use them.');
    }

    public function storeLogo(UpdateInvoicingLogoRequest $request, Workspace $workspace, UpdateInvoicingLogoAction $action): RedirectResponse
    {
        $profile = $workspace->invoicingProfile;

        if ($profile === null) {
            return back()->withErrors(['logo' => 'Save your business details first, then add a logo.']);
        }

        $action->handle($profile, (string) $request->file('logo')?->getContent(), $request->user());

        return back()->with('success', 'Logo updated. Invoices already prepared keep their logo.');
    }

    public function destroyLogo(Request $request, Workspace $workspace, UpdateInvoicingLogoAction $action): RedirectResponse
    {
        $this->authorize($request, $workspace);

        if ($profile = $workspace->invoicingProfile) {
            $action->handle($profile, null, $request->user());
        }

        return back()->with('success', 'Logo removed.');
    }

    public function showLogo(Request $request, Workspace $workspace): StreamedResponse
    {
        $this->authorize($request, $workspace);

        $path = $workspace->invoicingProfile?->logo_path;
        abort_if($path === null || ! Storage::disk(InvoicingProfile::LOGO_DISK)->exists($path), 404);

        return Storage::disk(InvoicingProfile::LOGO_DISK)->response($path, null, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function authorize(Request $request, Workspace $workspace): void
    {
        abort_unless($request->user()->can('manage', [InvoicingProfile::class, $workspace]), 403);
    }
}
