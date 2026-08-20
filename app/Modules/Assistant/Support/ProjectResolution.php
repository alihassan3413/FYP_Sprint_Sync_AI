<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Support;

use App\Modules\Projects\Models\Project;

/**
 * The outcome of working out which project the user meant. Either we have one,
 * or we have a tool payload that tells the assistant exactly what to ask.
 */
final readonly class ProjectResolution
{
    /**
     * @param  array<int, array{id: int, name: string|null, match_confidence?: int}>  $candidates
     */
    private function __construct(
        public ?Project $project,
        public string $status,
        public array $candidates = [],
        public ?string $message = null,
    ) {}

    public static function resolved(Project $project): self
    {
        return new self($project, 'resolved');
    }

    /**
     * @param  array<int, array{id: int, name: string|null, match_confidence?: int}>  $candidates
     */
    public static function ambiguous(array $candidates, string $message): self
    {
        return new self(null, 'ambiguous', $candidates, $message);
    }

    /**
     * @param  array<int, array{id: int, name: string|null}>  $candidates
     */
    public static function notFound(array $candidates, string $message): self
    {
        return new self(null, 'not_found', $candidates, $message);
    }

    public static function noProjects(): self
    {
        return new self(
            null,
            'no_projects',
            [],
            'You are not on any project in this workspace yet, so there is nowhere to put this. '
                .'An admin can create a project or add you to one.',
        );
    }

    public function isResolved(): bool
    {
        return $this->project !== null;
    }

    /**
     * The tool result to return when the project could not be pinned down. The
     * error code tells the assistant whether to ask the user or give up.
     *
     * @return array<string, mixed>
     */
    public function toolPayload(): array
    {
        return [
            'success' => false,
            'error_code' => match ($this->status) {
                'ambiguous' => 'project_ambiguous',
                'no_projects' => 'no_projects',
                default => 'project_not_found',
            },
            'error' => $this->message,
            'projects' => $this->candidates,
            /* A question for the user, not something that went wrong. */
            'awaiting_input' => $this->status === 'ambiguous',
            'next_step' => $this->status === 'ambiguous'
                ? 'Ask the user which of these projects they mean, then call this tool again with that project_id.'
                : 'Show the user the projects listed here and ask which one they meant.',
        ];
    }
}
