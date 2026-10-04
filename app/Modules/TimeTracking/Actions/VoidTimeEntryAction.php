<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\TimeTracking\Data\TimeEntrySource;
use App\Modules\TimeTracking\Exceptions\TimeTrackingException;
use App\Modules\TimeTracking\Models\TimeEntry;

/**
 * Voids a manual entry or an adjustment added by mistake. It stays on record
 * (with who, when and why) and stops counting. Tracked time is never voided:
 * it is corrected with an adjustment, so the original duration stays visible.
 * A conditional update makes a second void a no-op.
 */
final class VoidTimeEntryAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(TimeEntry $entry, string $reason, User $actor): TimeEntry
    {
        if ($entry->source === TimeEntrySource::Tracked) {
            throw TimeTrackingException::invalid('Tracked time is corrected with an adjustment, not voided.');
        }

        if (TimeEntry::query()->active()->where('adjusts_entry_id', $entry->id)->exists()) {
            throw TimeTrackingException::invalid('Void the corrections to this time first.');
        }

        $voided = TimeEntry::query()->whereKey($entry->getKey())->whereNull('voided_at')->update([
            'voided_at' => now(),
            'voided_by' => $actor->id,
            'void_reason' => $reason,
            'updated_at' => now(),
        ]);

        $entry->refresh();

        if ($voided === 1) {
            $this->auditLogger->handle(
                $entry->workspace,
                null,
                $actor,
                AuditAction::TIME_ENTRY_VOIDED,
                "{$actor->name} voided {$entry->person->name}'s time on {$entry->project_name}: {$reason}",
                $entry,
                ['person' => $entry->person->name, 'duration_seconds' => $entry->duration_seconds, 'reason' => $reason],
            );
        }

        return $entry;
    }
}
