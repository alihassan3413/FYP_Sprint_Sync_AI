<?php

declare(strict_types=1);

namespace App\Modules\Meetings\Data;

use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

#[TypeScript]
final class StoreMeetingData extends Data
{
    public const MAX_PARTICIPANTS = 50;

    public function __construct(
        #[Rule(['required', 'string', 'min:2', 'max:150'])]
        public string $title,

        #[Rule(['nullable', 'string', 'max:5000'])]
        public ?string $description,

        #[Rule(['required', 'date'])]
        public string $scheduled_at,

        #[Rule(['required', 'integer', 'min:1', 'max:1440'])]
        public int $duration_minutes,

        #[Rule(['nullable', 'url', 'max:2048'])]
        public ?string $meeting_link,

        /** @var array<int, int> */
        public array $participant_user_ids = [],

        /** @var array<int, string> */
        public array $participant_emails = [],
    ) {}
}
