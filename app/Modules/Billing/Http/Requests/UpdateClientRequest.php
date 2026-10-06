<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Requests;

use App\Modules\Billing\Models\Client;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

final class UpdateClientRequest extends ClientDetailsRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->client()) ?? false;
    }

    public function client(): Client
    {
        return $this->route('client');
    }

    protected function uniqueName(): Unique
    {
        return Rule::unique('clients', 'name')
            ->where('workspace_id', $this->workspace()->id)
            ->ignore($this->client()->id);
    }
}
