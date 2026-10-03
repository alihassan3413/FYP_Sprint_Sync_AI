<?php

declare(strict_types=1);

namespace App\Modules\Workspace\Data;

/**
 * Money is deliberately kept out of WorkspacePermission: every workspace admin
 * implicitly holds every WorkspacePermission, and seeing what clients pay or
 * what people earn must never come along for free with "can manage projects".
 *
 * Each permission is independent so a bookkeeper can record payments without
 * approving invoices, and anyone can be kept away from salaries.
 *
 * v1: only the workspace owner has finance access (see Workspace::allowsFinance).
 * The values are already storable on custom roles so delegating them later is a
 * policy change, not a schema change. Before enabling delegation, reset any
 * billing.* grants created under the old role editor, where those toggles
 * described the SprintSync subscription rather than client invoicing.
 */
enum FinancePermission: string
{
    case View = 'billing.view';
    case Manage = 'billing.manage';
    case Approve = 'billing.approve';
    case Payments = 'billing.payments';
    case Compensation = 'people.compensation';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::View => 'View invoices and clients',
            self::Manage => 'Manage clients and recurring invoices',
            self::Approve => 'Approve and send invoices',
            self::Payments => 'Record payments',
            self::Compensation => 'See pay and compensation',
        };
    }

    /**
     * @param  array<string, mixed>|null  $permissions
     * @return array<string, bool>
     */
    public static function normalise(?array $permissions): array
    {
        $normalised = [];

        foreach (self::cases() as $permission) {
            $normalised[$permission->value] = ($permissions[$permission->value] ?? false) === true;
        }

        return $normalised;
    }
}
