<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Requests;

use App\Modules\People\Models\TeamImport;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class StageTeamImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', [TeamImport::class, $this->route('workspace')]) ?? false;
    }

    public function rules(): array
    {
        return [
            'file' => ['required', File::types(['xlsx', 'csv', 'txt'])->max(TeamImport::MAX_KILOBYTES), function (string $attribute, mixed $value, Closure $fail) {
                if (! in_array(strtolower((string) $value?->getClientOriginalExtension()), ['xlsx', 'csv'], true)) {
                    $fail('Upload an .xlsx or .csv file.');
                }
            }],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required' => 'Choose an .xlsx or .csv file.',
            'file.max' => 'The file must be 2 MB or smaller.',
            'file.mimes' => 'Upload an .xlsx or .csv file.',
        ];
    }
}
