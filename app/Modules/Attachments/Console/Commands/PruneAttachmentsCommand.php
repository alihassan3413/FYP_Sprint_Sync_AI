<?php

declare(strict_types=1);

namespace App\Modules\Attachments\Console\Commands;

use App\Modules\Attachments\Models\Attachment;
use Illuminate\Console\Command;

final class PruneAttachmentsCommand extends Command
{
    protected $signature = 'attachments:prune';

    protected $description = 'Delete uploads that were never attached to anything.';

    public function handle(): int
    {
        $cutoff = now()->subHours((int) config('attachments.prune_unclaimed_after_hours'));

        $stale = Attachment::query()
            ->whereNull('attachable_type')
            ->where('created_at', '<', $cutoff)
            ->get();

        foreach ($stale as $attachment) {
            $attachment->delete();
        }

        $this->info("Pruned {$stale->count()} unclaimed attachment(s).");

        return self::SUCCESS;
    }
}
