<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\People\Data\StorePersonData;
use App\Modules\People\Models\Person;
use Illuminate\Support\Facades\DB;

final class UpdatePersonAction
{
    public function __construct(
        private readonly RecordAuditLogAction $auditLogger,
        private readonly ResolveDepartmentAction $departments,
        private readonly SetPersonUserAction $linker,
    ) {}

    public function handle(Person $person, StorePersonData $data, User $actor): Person
    {
        $department = $this->departments->handle($person->workspace, $data->department, $actor);

        return DB::transaction(function () use ($person, $data, $actor, $department) {
            $person->fill($data->details());
            $person->department()->associate($department);

            $changed = array_keys($person->getDirty());
            $person->save();

            if ($changed !== []) {
                $this->auditLogger->handle(
                    $person->workspace,
                    null,
                    $actor,
                    AuditAction::PERSON_UPDATED,
                    "{$actor->name} updated {$person->name}.",
                    $person,
                    ['changed' => $changed],
                );
            }

            $user = $data->user_id === null ? null : User::query()->findOrFail($data->user_id);

            return $this->linker->handle($person, $user, $actor);
        });
    }
}
