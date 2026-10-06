<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Models\Department;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * Turns a typed department name into a department, creating it if the
 * workspace does not have one yet. Case and extra spaces do not create
 * duplicates, and two requests racing to create the same name end up with one
 * row: the unique index rejects the second insert and it reuses the first.
 */
final class ResolveDepartmentAction
{
    public function __construct(private readonly RecordAuditLogAction $auditLogger) {}

    public function handle(Workspace $workspace, ?string $name, User $actor): ?Department
    {
        $name = Str::squish((string) $name);

        if ($name === '') {
            return null;
        }

        $existing = $this->find($workspace, $name);

        if ($existing !== null) {
            return $existing;
        }

        try {
            /** @var Department $department */
            $department = $workspace->departments()->create(['name' => $name]);
        } catch (UniqueConstraintViolationException) {
            return $this->find($workspace, $name) ?? throw new \LogicException('Department vanished after a unique conflict.');
        }

        $this->auditLogger->handle(
            $workspace,
            null,
            $actor,
            AuditAction::DEPARTMENT_CREATED,
            "{$actor->name} created the department \"{$department->name}\".",
            $department,
        );

        return $department;
    }

    private function find(Workspace $workspace, string $name): ?Department
    {
        return $workspace->departments()->where('name_key', Department::keyFor($name))->first();
    }
}
