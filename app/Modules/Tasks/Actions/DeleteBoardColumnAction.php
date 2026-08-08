<?php

declare(strict_types=1);

namespace App\Modules\Tasks\Actions;

use App\Modules\Tasks\Models\BoardColumn;

final class DeleteBoardColumnAction
{
    public function handle(BoardColumn $column): void
    {
        $column->delete();
    }
}
