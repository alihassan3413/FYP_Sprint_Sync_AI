<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Concerns;

use App\Modules\Billing\Contracts\MailTransportFactory;
use App\Modules\Billing\Data\MailEncryption;
use App\Modules\Billing\Models\WorkspaceMailSettings;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Replaces the workspace's real SMTP connection with an in-memory one. The
 * workspace mailer bypasses Mail::fake (it never uses the platform mailer),
 * so tests swap the transport factory instead.
 *
 * Modes: 'ok' accepts; 'fail' refuses before the message is handed over;
 * 'ambiguous' breaks after the server accepted DATA.
 */
trait FakesWorkspaceMail
{
    protected FakeWorkspaceTransport $smtp;

    protected function fakeWorkspaceMail(string $mode = 'ok', string $error = 'Connection could not be established with host "smtp.example.com:587": stream_socket_client(): password=hunter2'): void
    {
        $this->smtp = new FakeWorkspaceTransport($mode, $error);
        $factory = new class($this->smtp) implements MailTransportFactory
        {
            /** @var list<WorkspaceMailSettings> */
            public array $usedWith = [];

            public function __construct(private readonly FakeWorkspaceTransport $transport) {}

            public function make(WorkspaceMailSettings $settings): TransportInterface
            {
                $this->usedWith[] = $settings;
                $this->transport->handedOver = false;

                return $this->transport;
            }
        };

        $this->app->instance(MailTransportFactory::class, $factory);
    }

    protected function connectMail(array $overrides = []): WorkspaceMailSettings
    {
        $settings = (new WorkspaceMailSettings)->forceFill([
            'workspace_id' => $this->workspace->id,
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => MailEncryption::Tls,
            'username' => 'billing@sprintsync.test',
            'password' => 'super-secret-smtp-pass',
            'from_email' => 'billing@sprintsync.test',
            'from_name' => 'SprintSync QA',
            'reply_to_email' => 'accounts@sprintsync.test',
            'verified_at' => now(),
            ...$overrides,
        ]);
        $settings->save();
        $this->workspace->unsetRelation('mailSettings');

        return $settings;
    }
}
