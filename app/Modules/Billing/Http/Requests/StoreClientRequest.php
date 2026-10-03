<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\Client;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class StoreClientRequest extends ClientDetailsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [Client::class, $this->workspace()]) ?? false;
    }

    protected function uniqueName(): Unique
    {
        return Rule::unique('clients', 'name')->where('workspace_id', $this->workspace()->id);
    }
}
