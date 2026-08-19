<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Models\User;

/**
 * The outcome of working out which person the user meant for an assignment.
 */
final readonly class AssigneeResolution
{
    /**
     * @param  array<int, array{id: int, name: string|null, email: string|null, match_confidence?: int}>  $candidates
     */
    private function __construct(
        public ?User $user,
        public string $status,
        public array $candidates = [],
        public ?string $message = null,
    ) {}

    public static function resolved(User $user): self
    {
        return new self($user, 'resolved');
    }

    /**
     * @param  array<int, array{id: int, name: string|null, email: string|null, match_confidence?: int}>  $candidates
     */
    public static function ambiguous(array $candidates, string $message): self
    {
        return new self(null, 'ambiguous', $candidates, $message);
    }

    /**
     * @param  array<int, array{id: int, name: string|null, email: string|null}>  $candidates
     */
    public static function notFound(array $candidates, string $message): self
    {
        return new self(null, 'not_found', $candidates, $message);
    }

    /**
     * They exist in the workspace but are not on this project yet.
     */
    public static function notOnProject(User $user, string $message): self
    {
        return new self(null, 'not_on_project', [[
            'id' => $user->id,
            'name' => UntrustedText::inline($user->name),
            'email' => UntrustedText::inline($user->email),
        ]], $message);
    }

    public function isResolved(): bool
    {
        return $this->user !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toolPayload(): array
    {
        return [
            'success' => false,
            'error_code' => match ($this->status) {
                'ambiguous' => 'assignee_ambiguous',
                'not_on_project' => 'assignee_not_on_project',
                default => 'assignee_not_found',
            },
            'error' => $this->message,
            'people' => $this->candidates,
            'next_step' => match ($this->status) {
                'ambiguous' => 'Ask the user which of these people they mean, then call this tool again with their email address.',
                'not_on_project' => 'Offer to add them to the project with add_project_member, and only do it once the user agrees.',
                default => 'Show the user who is on this project and ask which of them they meant.',
            },
        ];
    }
}
