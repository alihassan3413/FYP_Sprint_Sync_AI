<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class MyWorkRequest extends FormRequest
{
    public const STATUSES = ['open', 'done', 'all'];

    public const DUE = ['any', 'overdue', 'today', 'week', 'none'];

    public function rules(): array
    {
        return [
            'scope' => ['nullable', Rule::in(['mine', 'all'])],
            'project' => ['nullable', 'integer'],
            'assignee' => ['nullable', 'string', 'regex:/^(\d+|unassigned)$/'],
            'status' => ['nullable', Rule::in(self::STATUSES)],
            'due' => ['nullable', Rule::in(self::DUE)],
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function filters(bool $canViewAll): array
    {
        $scope = $canViewAll && $this->validated('scope') === 'all' ? 'all' : 'mine';

        return [
            'scope' => $scope,
            'project' => $this->validated('project') === null ? null : (int) $this->validated('project'),
            'assignee' => $scope === 'all' ? $this->validated('assignee') : null,
            'status' => $this->validated('status') ?? 'open',
            'due' => $this->validated('due') ?? 'any',
            'search' => trim((string) $this->validated('search')) ?: null,
        ];
    }
}
