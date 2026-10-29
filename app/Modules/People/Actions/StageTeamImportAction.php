<?php

declare(strict_types=1);

namespace App\Modules\People\Actions;

use App\Models\User;
use App\Modules\People\Data\TeamImportStatus;
use App\Modules\People\Exceptions\TeamImportException;
use App\Modules\People\Models\TeamImport;
use App\Modules\People\Support\TeamImportValidator;
use App\Modules\People\Support\TeamSpreadsheet;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class StageTeamImportAction
{
    public function handle(Workspace $workspace, UploadedFile $file, User $actor): TeamImport
    {
        $hash = (string) hash_file('sha256', $file->getRealPath());

        $active = TeamImport::query()
            ->where('workspace_id', $workspace->id)
            ->where('file_sha256', $hash)
            ->whereIn('status', [TeamImportStatus::Validated, TeamImportStatus::Queued, TeamImportStatus::Processing])
            ->exists();

        if ($active) {
            throw TeamImportException::duplicateFile();
        }

        $extension = Str::lower($file->getClientOriginalExtension());
        $records = TeamSpreadsheet::read($file->getRealPath(), $extension, TeamImport::MAX_ROWS);

        if ($records === []) {
            throw TeamImportException::empty();
        }

        $validator = new TeamImportValidator($workspace, array_column($records, 'email'));
        $rows = [];
        foreach ($records as $rowNumber => $record) {
            $rows[] = $validator->validate($rowNumber, $record);
        }

        $import = new TeamImport;
        $import->public_id = $import->newUniqueId();
        $directory = "team-imports/{$import->public_id}";
        $path = Storage::disk('local')->putFileAs($directory, $file, 'source.'.$extension);

        try {
            return DB::transaction(function () use ($import, $workspace, $actor, $file, $path, $hash, $rows) {
                $import->forceFill([
                    'workspace_id' => $workspace->id,
                    'created_by' => $actor->id,
                    'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
                    'path' => $path,
                    'file_sha256' => $hash,
                    'status' => TeamImportStatus::Validated,
                    'total_rows' => count($rows),
                ])->save();

                foreach ($rows as $row) {
                    $import->rows()->create($row);
                }

                return $import;
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->deleteDirectory($directory);

            throw $exception;
        }
    }
}
