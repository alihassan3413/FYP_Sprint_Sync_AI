<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How the connection to the workspace's email server is secured.
 * TLS = STARTTLS on a plain port (usually 587), required, never silently
 * downgraded. SSL = encrypted from the first byte (usually 465).
 */
#[TypeScript]
enum MailEncryption: string
{
    case Tls = 'tls';
    case Ssl = 'ssl';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::Tls => 'TLS (port 587)',
            self::Ssl => 'SSL (port 465)',
            self::None => 'None',
        };
    }
}
