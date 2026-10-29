<?php

declare(strict_types=1);

namespace App\Modules\People\Http\Controllers;

use App\Modules\People\Actions\CancelTeamImportAction;
use App\Modules\People\Actions\ConfirmTeamImportAction;
use App\Modules\People\Actions\StageTeamImportAction;
use App\Modules\People\Data\TeamImportOutcome;
use App\Modules\People\Data\TeamImportRowStatus;
use App\Modules\People\Exceptions\TeamImportException;
use App\Modules\People\Http\Requests\StageTeamImportRequest;
use App\Modules\People\Models\TeamImport;
use App\Modules\People\Models\TeamImportRow;
use App\Modules\People\Support\TeamSpreadsheet;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class TeamImportController
{
    public function create(Request $request, Workspace $workspace): Response
    {
        abort_unless($request->user()->can('create', [TeamImport::class, $workspace]), 403);

        return Inertia::render('people/import', [
            'limits' => ['rows' => TeamImport::MAX_ROWS, 'megabytes' => TeamImport::MAX_KILOBYTES / 1024],
            'columns' => TeamSpreadsheet::COLUMNS,
            'recent' => $workspace->teamImports()
                ->with('creator:id,name')
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (TeamImport $import) => [
                    'public_id' => $import->public_id,
                    'original_name' => $import->original_name,
                    'status' => $import->status->value,
                    'total_rows' => $import->total_rows,
                    'created_at' => $import->created_at->toIso8601String(),
                    'created_by' => $import->creator?->name,
                ])
                ->values(),
        ]);
    }

    public function template(Request $request, Workspace $workspace): HttpResponse
    {
        abort_unless($request->user()->can('create', [TeamImport::class, $workspace]), 403);

        if ($request->query('format') === 'csv') {
            return response("\xEF\xBB\xBF".TeamSpreadsheet::templateCsv(), 200, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => 'attachment; filename="sprintsync-teammates-template.csv"',
            ]);
        }

        return response(TeamSpreadsheet::templateXlsx(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="sprintsync-teammates-template.xlsx"',
        ]);
    }

    public function store(StageTeamImportRequest $request, Workspace $workspace, StageTeamImportAction $stage): RedirectResponse
    {
        try {
            $import = $stage->handle($workspace, $request->file('file'), $request->user());
        } catch (TeamImportException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return redirect()->route('workspace.people.imports.show', [$workspace, $import]);
    }

    public function show(Request $request, Workspace $workspace, TeamImport $teamImport): Response
    {
        abort_unless($request->user()->can('view', $teamImport), 403);

        $rows = $teamImport->rows()->with('customRole:id,name')->orderBy('row_number')->get();

        return Inertia::render('people/import-show', [
            'teamImport' => [
                'public_id' => $teamImport->public_id,
                'original_name' => $teamImport->original_name,
                'status' => $teamImport->status->value,
                'total_rows' => $teamImport->total_rows,
                'processed_rows' => $teamImport->processed_rows,
                'importing_rows' => $rows->filter(fn (TeamImportRow $row) => $row->status->willImport())->count(),
                'created_by' => $teamImport->creator?->name,
                'confirmed_by' => $teamImport->confirmer?->name,
                'created_at' => $teamImport->created_at->toIso8601String(),
                'completed_at' => $teamImport->completed_at?->toIso8601String(),
            ],
            'counts' => [
                ...collect(TeamImportRowStatus::cases())->mapWithKeys(fn (TeamImportRowStatus $status) => [$status->value => $rows->where('status', $status)->count()])->all(),
                ...collect(TeamImportOutcome::cases())->mapWithKeys(fn (TeamImportOutcome $outcome) => ['outcome_'.$outcome->value => $rows->where('outcome', $outcome)->count()])->all(),
            ],
            'rows' => $rows->map(fn (TeamImportRow $row) => [
                'row_number' => $row->row_number,
                'name' => $row->fullName() !== '' ? $row->fullName() : null,
                'email' => $row->email ?? ($row->raw['email'] ?? null),
                'role' => $row->role === null ? ($row->raw['role'] ?? null) : $row->role->label().($row->customRole ? " · {$row->customRole->name}" : ''),
                'department' => $row->department,
                'status' => $row->status->value,
                'outcome' => $row->outcome?->value,
                'outcome_label' => $row->outcome?->label(),
                'messages' => $row->messages,
            ])->values(),
        ]);
    }

    public function confirm(Request $request, Workspace $workspace, TeamImport $teamImport, ConfirmTeamImportAction $confirm): RedirectResponse
    {
        abort_unless($request->user()->can('update', $teamImport), 403);

        try {
            $confirm->handle($teamImport, $request->user());
        } catch (TeamImportException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Import started. Invitations are being sent.');
    }

    public function cancel(Request $request, Workspace $workspace, TeamImport $teamImport, CancelTeamImportAction $cancel): RedirectResponse
    {
        abort_unless($request->user()->can('update', $teamImport), 403);

        try {
            $cancel->handle($teamImport);
        } catch (TeamImportException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return redirect()->route('workspace.people.imports.create', $workspace)->with('success', 'Import cancelled. Nothing was imported and the file was deleted.');
    }

    public function errors(Request $request, Workspace $workspace, TeamImport $teamImport): StreamedResponse
    {
        abort_unless($request->user()->can('view', $teamImport), 403);

        $rows = $teamImport->rows()
            ->where(fn ($query) => $query
                ->whereIn('status', [TeamImportRowStatus::Error, TeamImportRowStatus::Skipped])
                ->orWhereIn('outcome', [TeamImportOutcome::Failed, TeamImportOutcome::Skipped])
                ->orWhere('status', TeamImportRowStatus::Warning))
            ->orderBy('row_number')
            ->get();

        $name = pathinfo($teamImport->original_name, PATHINFO_FILENAME);

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['row', ...TeamSpreadsheet::COLUMNS, 'status', 'result', 'messages'], escape: '');
            foreach ($rows as $row) {
                fputcsv($out, [
                    $row->row_number,
                    ...array_map(fn (string $column) => self::safeCell((string) ($row->raw[$column] ?? '')), TeamSpreadsheet::COLUMNS),
                    $row->status->value,
                    $row->outcome?->value ?? '',
                    self::safeCell(implode(' ', $row->messages)),
                ], escape: '');
            }
            fclose($out);
        }, "{$name}-issues.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function safeCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
