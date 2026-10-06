<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

/**
 * The outcome of InvoiceAutomationSafety: safe to send automatically, or held
 * for the owner with every reason found (in the order they were checked).
 */
final readonly class SafetyResult
{
    /**
     * @param  list<array{code: string, message: string}>  $reasons
     */
    public function __construct(public array $reasons = []) {}

    public function isSafe(): bool
    {
        return $this->reasons === [];
    }

    public function has(AutomationReason $reason): bool
    {
        return in_array($reason->value, array_column($this->reasons, 'code'), true);
    }
}
