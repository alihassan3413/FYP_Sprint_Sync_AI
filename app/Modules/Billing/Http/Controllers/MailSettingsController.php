<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers;

use App\Modules\Billing\Actions\SaveMailSettingsAction;
use App\Modules\Billing\Actions\SendMailSettingsTestAction;
use App\Modules\Billing\Data\MailEncryption;
use App\Modules\Billing\Http\Requests\SaveMailSettingsRequest;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use App\Modules\Billing\Support\SmtpHostPolicy;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings → Email delivery: the workspace's own email server for invoices.
 * The saved password is never sent to the browser, only whether one exists.
 */
final class MailSettingsController
{
    public function edit(Request $request, Workspace $workspace): Response
    {
        $this->authorize($request, $workspace);

        $settings = $workspace->mailSettings;

        return Inertia::render('workspace/settings/EmailDelivery', [
            'settings' => $settings?->only(['host', 'port', 'encryption', 'username', 'from_email', 'from_name', 'reply_to_email']),
            'hasPassword' => $settings?->password !== null,
            'verifiedAt' => $settings?->verified_at?->toIso8601String(),
            'lastTestedAt' => $settings?->last_tested_at?->toIso8601String(),
            'lastTestError' => $settings?->last_test_error,
            'encryptionOptions' => array_map(fn (MailEncryption $option) => ['value' => $option->value, 'label' => $option->label()], MailEncryption::cases()),
            'allowedPorts' => SmtpHostPolicy::allowedPorts(),
            'testRecipient' => $request->user()->email,
        ]);
    }

    public function update(SaveMailSettingsRequest $request, Workspace $workspace, SaveMailSettingsAction $action): RedirectResponse
    {
        $action->handle($workspace, $request->details(), $request->password(), $request->user());

        return back()->with('success', 'Email delivery settings saved. Send a test email to check them.');
    }

    public function test(Request $request, Workspace $workspace, SendMailSettingsTestAction $action): RedirectResponse
    {
        $this->authorize($request, $workspace);

        $validated = $request->validate(['recipient' => ['required', 'string', 'email', 'max:254']]);
        $settings = $workspace->mailSettings;

        if ($settings === null) {
            return back()->withErrors(['recipient' => 'Save your email settings first.']);
        }

        $reason = $action->handle($settings, $validated['recipient'], $request->user());

        return $reason === null
            ? back()->with('success', 'Test email sent successfully.')
            : back()->withErrors(['recipient' => "Couldn't send the test email. {$reason}"]);
    }

    private function authorize(Request $request, Workspace $workspace): void
    {
        abort_unless($request->user()->can('manage', [WorkspaceMailSettings::class, $workspace]), 403);
    }
}
