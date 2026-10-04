<?php

declare(strict_types=1);

namespace App\Modules\Billing\Support;

/**
 * Which email servers a workspace may point invoice delivery at.
 *
 * The owner chooses their own server, so private company hosts are allowed.
 * What is refused is what turns "connect to an SMTP server" into a probe of
 * SprintSync's own infrastructure:
 * - only the standard mail ports (and 1025 for local mail capture outside
 *   production);
 * - in production, hosts that are or resolve to this machine (loopback),
 *   link-local or cloud metadata addresses.
 * Connections also time out quickly and the test email is rate limited.
 */
final class SmtpHostPolicy
{
    public const PORTS = [25, 465, 587, 2525];

    public const LOCAL_CAPTURE_PORT = 1025;

    /** @return list<int> */
    public static function allowedPorts(): array
    {
        return app()->isProduction() ? self::PORTS : [...self::PORTS, self::LOCAL_CAPTURE_PORT];
    }

    public static function hostProblem(string $host): ?string
    {
        if (! preg_match('/^[A-Za-z0-9.-]{1,253}$/', $host) || str_starts_with($host, '.') || str_ends_with($host, '.')) {
            return 'Enter a server name like smtp.example.com.';
        }

        if (! app()->isProduction()) {
            return null;
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        foreach ($addresses as $address) {
            if (self::isInternal($address)) {
                return 'This server address is not allowed for sending email.';
            }
        }

        return null;
    }

    private static function isInternal(string $ip): bool
    {
        $long = ip2long($ip);

        if ($long === false) {
            return true;
        }

        foreach (['127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8'] as $cidr) {
            [$subnet, $bits] = explode('/', $cidr);
            $mask = -1 << (32 - (int) $bits);

            if (($long & $mask) === (ip2long($subnet) & $mask)) {
                return true;
            }
        }

        return false;
    }
}
