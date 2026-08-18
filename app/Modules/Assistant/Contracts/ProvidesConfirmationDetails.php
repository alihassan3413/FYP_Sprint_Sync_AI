<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Contracts;

use App\Modules\Assistant\Support\ToolContext;

interface ProvidesConfirmationDetails
{
    /**
     * @param  array<string, mixed>  $args
     * @return array<string, string>
     */
    public function confirmationDetails(array $args, ToolContext $context): array;
}
