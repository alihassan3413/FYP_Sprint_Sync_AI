<?php

declare(strict_types=1);

namespace App\Modules\People\Support;

use App\Models\User;
use App\Modules\People\Data\TeamImportRowStatus;
use App\Modules\People\Models\Person;
use App\Modules\TimeTracking\Models\WorkSchedule;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\UserRole;
use DateTimeZone;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class TeamImportValidator
{
    private array $memberEmails;

    private array $pendingEmails;

    private array $accountEmails;

    private Collection $people;

    private Collection $customRoles;

    private array $departments;

    private array $scheduledPeople;

    private array $seen = [];

    public function __construct(private readonly Workspace $workspace, array $emails)
    {
        $emails = array_values(array_unique(array_filter(array_map(fn ($email) => Str::lower(trim((string) $email)), $emails))));

        $this->memberEmails = array_flip($workspace->users()->pluck('users.email')->map(fn ($email) => Str::lower($email))->all());
        $this->pendingEmails = array_flip(WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->pending()->pluck('email')->map(fn ($email) => Str::lower($email))->all());
        $this->accountEmails = array_flip(User::query()->whereIn('email', $emails)->pluck('email')->map(fn ($email) => Str::lower($email))->all());
        $this->people = $workspace->people()->whereNotNull('email')->get(['id', 'name', 'email', 'user_id'])->keyBy(fn (Person $person) => Str::lower((string) $person->email));
        $this->customRoles = $workspace->roles()->get(['id', 'name'])->keyBy(fn ($role) => Str::lower($role->name));
        $this->departments = array_flip($workspace->departments()->pluck('name')->map(fn ($name) => Str::lower($name))->all());
        $this->scheduledPeople = WorkSchedule::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('person_id')
            ->where('effective_from', '<=', now())
            ->orderBy('effective_from')
            ->orderBy('id')
            ->get()
            ->keyBy('person_id')
            ->reject(fn (WorkSchedule $schedule) => $schedule->isEmpty())
            ->keys()
            ->flip()
            ->all();
    }

    public function validate(int $rowNumber, array $record): array
    {
        $errors = [];
        $warnings = [];
        $notes = [];
        $skip = null;

        $firstName = Str::squish($record['first_name'] ?? '');
        $lastName = Str::squish($record['last_name'] ?? '');
        $email = Str::lower(trim($record['email'] ?? ''));
        $fullName = trim($firstName.' '.$lastName);

        if ($firstName === '') {
            $errors[] = 'First name is required.';
        } elseif (mb_strlen($firstName) > 60) {
            $errors[] = 'First name must be 60 characters or fewer.';
        }

        if ($lastName === '') {
            $errors[] = 'Last name is required.';
        } elseif (mb_strlen($lastName) > 60) {
            $errors[] = 'Last name must be 60 characters or fewer.';
        }

        if ($email === '') {
            $errors[] = 'Email is required.';
        } elseif (mb_strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = "\"{$email}\" is not a valid email address.";
        } elseif (isset($this->seen[$email])) {
            $errors[] = "Same email as row {$this->seen[$email]}.";
        } else {
            $this->seen[$email] = $rowNumber;
        }

        [$role, $customRole, $roleError] = $this->role($record['role'] ?? '');
        if ($roleError !== null) {
            $errors[] = $roleError;
        }

        $department = Str::squish($record['department'] ?? '');
        if (mb_strlen($department) > 80) {
            $errors[] = 'Department must be 80 characters or fewer.';
        }

        $timezone = trim($record['timezone'] ?? '');
        if ($timezone !== '' && ! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $warnings[] = "Unknown timezone \"{$timezone}\" was ignored.";
            $timezone = '';
        }

        $scheduleValue = Str::lower(trim($record['schedule'] ?? ''));
        $schedulePerson = null;

        if ($role === UserRole::CLIENT) {
            if ($department !== '') {
                $warnings[] = 'Guests have no team profile, so the department was ignored.';
                $department = '';
            }
            if ($scheduleValue !== '' && $scheduleValue !== 'default') {
                $warnings[] = 'Guests have no work schedule, so the schedule was ignored.';
            }
            $scheduleValue = '';
        }

        if ($department !== '' && ! isset($this->departments[Str::lower($department)])) {
            $warnings[] = "New department \"{$department}\" will be created.";
        }

        if ($scheduleValue !== '' && $scheduleValue !== 'default') {
            $source = $this->people->get($scheduleValue);
            if ($source === null) {
                $warnings[] = "No teammate with email {$scheduleValue}; the workspace schedule applies.";
            } elseif (! isset($this->scheduledPeople[$source->id])) {
                $warnings[] = "{$source->name} has no schedule of their own; the workspace schedule applies.";
            } else {
                $schedulePerson = $source->id;
                $notes[] = "Uses {$source->name}'s schedule.";
            }
        }

        if ($errors === [] && $email !== '') {
            if (isset($this->memberEmails[$email])) {
                $skip = 'Already a member of this workspace.';
            } elseif (isset($this->pendingEmails[$email])) {
                $skip = 'Already invited and waiting to accept.';
            } elseif (isset($this->accountEmails[$email])) {
                $notes[] = 'Has a SprintSync account; they join when they accept the invitation.';
            }

            $existing = $this->people->get($email);
            if ($skip === null && $existing !== null && $role !== UserRole::CLIENT) {
                $notes[] = "Matches the existing team profile \"{$existing->name}\".";
            }
        }

        $status = match (true) {
            $errors !== [] => TeamImportRowStatus::Error,
            $skip !== null => TeamImportRowStatus::Skipped,
            $warnings !== [] => TeamImportRowStatus::Warning,
            default => TeamImportRowStatus::Ready,
        };

        return [
            'row_number' => $rowNumber,
            'first_name' => $firstName === '' ? null : mb_substr($firstName, 0, 120),
            'last_name' => $lastName === '' ? null : mb_substr($lastName, 0, 120),
            'email' => $email === '' ? null : mb_substr($email, 0, 254),
            'role' => $role?->value,
            'workspace_role_id' => $customRole,
            'department' => $department === '' ? null : mb_substr($department, 0, 80),
            'timezone' => $timezone === '' ? null : $timezone,
            'schedule_person_id' => $schedulePerson,
            'raw' => $record,
            'status' => $status,
            'messages' => [...$errors, ...($skip === null ? [] : [$skip]), ...$warnings, ...$notes],
        ];
    }

    private function role(string $value): array
    {
        $value = Str::squish($value);
        if ($value === '') {
            return [null, null, 'Role is required: admin, member or client.'];
        }

        [$base, $custom] = array_pad(array_map('trim', explode(':', $value, 2)), 2, '');
        $role = UserRole::tryFrom(Str::lower($base));

        if ($role === UserRole::OWNER) {
            return [null, null, 'The workspace owner cannot be imported.'];
        }

        if ($role === null || ! in_array($role->value, UserRole::invitationRoles(), true)) {
            return [null, null, "Unknown role \"{$base}\". Use admin, member or client."];
        }

        if ($custom === '') {
            return [$role, null, null];
        }

        $customRole = $this->customRoles->get(Str::lower($custom));

        if ($customRole === null) {
            return [$role, null, "No custom role named \"{$custom}\". Create it in Access & permissions first."];
        }

        return [$role, $customRole->id, null];
    }
}
