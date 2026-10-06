<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Models\WorkspaceMailSettings;
use Symfony\Component\Mailer\Transport\TransportInterface;

/**
 * Opens a connection to a workspace's own email server. The single seam
 * between invoice delivery and the network, so tests can stand in a fake
 * server (accepting, refusing, or dropping after the message is handed over).
 */
interface MailTransportFactory
{
    public function make(WorkspaceMailSettings $settings): TransportInterface;
}
