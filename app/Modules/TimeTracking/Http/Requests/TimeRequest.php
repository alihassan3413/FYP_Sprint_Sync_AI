<?php

declare(strict_types=1);

namespace App\Modules\TimeTracking\Http\Requests;

use App\Modules\People\Models\Person;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\TimeTracking\Support\HoursInput;
use App\Modules\TimeTracking\Support\TimeAccess;
use App\Modules\Workspace\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared parts of the time requests: the workspace from the route, the
 * client-generated request key (ULID/UUID, so retries are recognised), and
 * project/task/person looked up by public id inside the workspace.
 */
abstract class TimeRequest extends FormRequest
{
    /** @var list<string> */
    protected const KEY_RULES = ['required', 'string', 'min:20', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'];

    public function workspace(): Workspace
    {
        return $this->route('workspace');
    }

    public function key(string $field = 'idempotency_key'): string
    {
        return (string) $this->validated($field);
    }

    public function project(): ?Project
    {
        return app(TimeAccess::class)->project($this->user(), $this->workspace(), $this->string('project')->toString() ?: null);
    }

    public function task(): ?Task
    {
        return app(TimeAccess::class)->task($this->project(), $this->string('task')->toString() ?: null);
    }

    public function targetPerson(): ?Person
    {
        return app(TimeAccess::class)->person($this->workspace(), $this->string('person')->toString() ?: null);
    }

    public function businessDate(string $field): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $this->validated($field), $this->workspace()->timezone)->startOfDay();
    }

    /**
     * Validation for project (+ optional task) references.
     *
     * @return array<string, mixed>
     */
    protected function projectRules(): array
    {
        return [
            'project' => ['required', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if ($this->project() === null) {
                    $fail('Choose a project you can record time on.');
                }
            }],
            'task' => ['nullable', 'string', function (string $attribute, mixed $value, \Closure $fail) {
                if (filled($value) && $this->task() === null) {
                    $fail('That task is not part of the chosen project.');
                }
            }],
        ];
    }

    /**
     * @return \Closure(string, mixed, \Closure): void
     */
    protected function hoursRule(bool $allowNegative = false): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($allowNegative) {
            if (HoursInput::toSeconds(is_scalar($value) ? (string) $value : null, $allowNegative) === null) {
                $fail('Enter hours like 2.5 or 45.25 (up to two decimals).');
            }
        };
    }
}
