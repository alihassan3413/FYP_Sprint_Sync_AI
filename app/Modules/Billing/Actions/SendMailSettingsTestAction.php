<?php

declare(strict_types=1);

namespace App\Modules\Billing\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Billing\Mail\MailSettingsTestEmail;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use App\Modules\Billing\Support\MailDeliveryFailure;
use App\Modules\Billing\Support\WorkspaceMailer;

/**
 * Sends "SprintSync email delivery test" through the saved settings and
 * records the result. Only a short, sanitised reason is stored or shown.
 *
 * @return string|null null on success, otherwise the reason it failed
 */
final class SendMailSettingsTestAction
{
    public function __construct(
        private readonly WorkspaceMailer $mailer,
        private readonly RecordAuditLogAction $auditLogger,
    ) {}

    public function handle(WorkspaceMailSettings $settings, string $recipient, User $actor): ?string
    {
        $reason = null;

        try {
            $this->mailer->send($settings, new MailSettingsTestEmail($recipient, $settings->from_email, $settings->from_name));
        } catch (MailDeliveryFailure $failure) {
            $reason = $failure->reason;
        }

        $settings->forceFill([
            'last_tested_at' => now(),
            'verified_at' => $reason === null ? now() : $settings->verified_at,
            'last_test_error' => $reason,
        ])->save();

        $this->auditLogger->handle(
            $settings->workspace,
            null,
            $actor,
            $reason === null ? AuditAction::MAIL_TEST_SUCCEEDED : AuditAction::MAIL_TEST_FAILED,
            $reason === null ? "{$actor->name} sent a test email to {$recipient}." : "{$actor->name}'s test email to {$recipient} failed.",
            $settings,
            ['recipient' => $recipient, 'reason' => $reason],
        );

        return $reason;
    }
}
