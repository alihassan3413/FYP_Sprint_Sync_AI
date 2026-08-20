<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Contracts;

use App\Modules\Assistant\Support\ToolContext;

/**
 * A tool that sometimes has a question before it can be confirmed.
 *
 * Confirmation is decided before the tool runs, so a tool that discovers a
 * missing detail during execution would have already put a confirmation card
 * in front of the user — who approves it, and is then told the action failed.
 * That reads as the app cancelling their request.
 *
 * Implementing this lets the tool say "not yet, I need to ask something first".
 * The call is then run without a card, because a tool with an outstanding
 * question only ever returns the question: it never writes anything.
 */
interface DefersConfirmation
{
    /**
     * @param  array<string, mixed>  $args
     */
    public function needsMoreInformation(array $args, ToolContext $context): bool;
}
