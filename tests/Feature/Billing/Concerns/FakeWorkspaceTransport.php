<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Concerns;

use App\Modules\Billing\Contracts\TracksMessageHandOff;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

/**
 * An in-memory SMTP server for tests. See FakesWorkspaceMail.
 */
final class FakeWorkspaceTransport extends AbstractTransport implements TracksMessageHandOff
{
    /** @var list<Email> */
    public array $sent = [];

    public bool $handedOver = false;

    public function __construct(public string $mode, public string $error)
    {
        parent::__construct();
    }

    public function messageWasHandedOver(): bool
    {
        return $this->handedOver;
    }

    protected function doSend(SentMessage $message): void
    {
        if ($this->mode === 'fail') {
            throw new TransportException($this->error);
        }

        if ($this->mode === 'ambiguous') {
            $this->handedOver = true;

            throw new TransportException('Connection to "smtp.example.com:587" has been closed unexpectedly.');
        }

        if ($this->mode === 'explode') {
            throw new RuntimeException('Unexpected');
        }

        $original = $message->getOriginalMessage();
        assert($original instanceof Email);
        $this->sent[] = $original;
    }

    public function __toString(): string
    {
        return 'fake://workspace';
    }
}
