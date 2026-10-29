<?php

declare(strict_types=1);

namespace Tests\Feature\People;

use App\Mail\MemberInvitationMail;
use App\Models\User;
use App\Modules\Audit\Actions\RecordAuditLogAction;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\People\Actions\ProcessTeamImportRowAction;
use App\Modules\People\Data\TeamImportOutcome;
use App\Modules\People\Data\TeamImportStatus;
use App\Modules\People\Jobs\ProcessTeamImportJob;
use App\Modules\People\Models\Person;
use App\Modules\People\Models\TeamImport;
use App\Modules\People\Support\TeamSpreadsheet;
use App\Modules\TimeTracking\Actions\SaveWorkScheduleAction;
use App\Modules\TimeTracking\Models\WorkSchedule;
use App\Modules\Workspace\Models\Workspace;
use App\Modules\Workspace\Models\WorkspaceInvitation;
use App\Modules\Workspace\Models\WorkspaceRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

final class TeamImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private const HEADER = 'first_name,last_name,email,role,department,timezone,schedule';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->owner = User::factory()->create(['name' => 'Ali Hassan']);
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
    }

    private function member(UserRole $role = UserRole::MEMBER, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    private function csv(string ...$lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('teammates.csv', implode("\n", [self::HEADER, ...$lines]));
    }

    private function upload(UploadedFile $file, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.people.imports.create', $this->workspace))
            ->post(route('workspace.people.imports.store', $this->workspace), ['file' => $file]);
    }

    private function assertFileError(TestResponse $response, string $message): void
    {
        $response->assertSessionHasErrors('file');
        $this->assertStringContainsString($message, session('errors')->first('file'));
    }

    private function staged(string ...$lines): TeamImport
    {
        $this->upload($this->csv(...$lines))->assertSessionHasNoErrors();

        return TeamImport::query()->latest('id')->firstOrFail();
    }

    private function confirm(TeamImport $import, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)->post(route('workspace.people.imports.confirm', [$this->workspace, $import]));
    }

    private function rowStatuses(TeamImport $import): array
    {
        return $import->rows()->orderBy('row_number')->pluck('status', 'row_number')->map(fn ($status) => $status->value)->all();
    }

    public function test_the_templates_download_and_read_back(): void
    {
        $xlsx = $this->actingAs($this->owner)->get(route('workspace.people.imports.template', $this->workspace));
        $xlsx->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($path, $xlsx->getContent());
        $this->assertCount(3, TeamSpreadsheet::read($path, 'xlsx', 500));
        @unlink($path);

        $csv = $this->actingAs($this->owner)->get(route('workspace.people.imports.template', [$this->workspace, 'format' => 'csv']));
        $csv->assertOk();
        $this->assertStringContainsString(self::HEADER, $csv->getContent());
    }

    public function test_owners_and_admins_import_members_and_guests_cannot(): void
    {
        $admin = $this->member(UserRole::ADMIN);

        $this->actingAs($this->owner)->get(route('workspace.people.imports.create', $this->workspace))->assertOk()->assertInertia(fn (Assert $page) => $page->component('people/import'));
        $this->actingAs($admin)->get(route('workspace.people.imports.create', $this->workspace))->assertOk();

        foreach ([UserRole::MEMBER, UserRole::CLIENT] as $role) {
            $user = $this->member($role);
            $this->actingAs($user)->get(route('workspace.people.imports.create', $this->workspace))->assertForbidden();
            $this->upload($this->csv('A,B,a@example.com,member,,,'), $user)->assertForbidden();
            $this->actingAs($user)->get(route('workspace.people.imports.template', $this->workspace))->assertForbidden();
        }

        $outsider = User::factory()->create();
        $this->actingAs($outsider)->get(route('workspace.people.imports.create', $this->workspace))->assertNotFound();
        $this->assertSame(0, TeamImport::query()->count());
    }

    public function test_an_admin_can_run_a_whole_import(): void
    {
        $admin = $this->member(UserRole::ADMIN, ['name' => 'Sara Admin']);
        $this->upload($this->csv('Ayesha,Khan,ayesha@example.com,member,Quote Operations,,'), $admin)->assertSessionHasNoErrors();
        $import = TeamImport::query()->sole();

        $this->confirm($import, $admin)->assertSessionHasNoErrors();

        $this->assertSame(TeamImportStatus::Completed, $import->fresh()->status);
        $this->assertSame($admin->id, $import->fresh()->confirmed_by);
        $this->assertTrue(WorkspaceInvitation::query()->where('email', 'ayesha@example.com')->exists());
    }

    public function test_the_preview_classifies_every_row_and_invalid_rows_do_not_block_valid_ones(): void
    {
        $this->member(UserRole::MEMBER, ['email' => 'already@example.com']);
        WorkspaceInvitation::query()->create(['workspace_id' => $this->workspace->id, 'email' => 'invited@example.com', 'role' => 'member', 'token' => str_repeat('t', 64), 'invited_by' => $this->owner->id, 'expires_at' => now()->addDays(3)]);
        WorkspaceRole::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => 'Quote Lead', 'slug' => 'quote-lead', 'permissions' => []]);

        $import = $this->staged(
            'Ayesha,Khan,Ayesha@Example.com,member,,,',
            'Bilal,Ahmed,bilal@example.com,member: Quote Lead,Brand New Dept,Mars/Base,',
            'Already,Here,already@example.com,member,,,',
            'Waiting,Invite,invited@example.com,admin,,,',
            'Bad,Email,not-an-email,member,,,',
            'No,Role,norole@example.com,superuser,,,',
            'Ghost,Role,ghost@example.com,member: Nope,,,',
            'The,Owner,owner2@example.com,owner,,,',
            'Dupe,Person,ayesha@example.com,member,,,',
            ',Missing,missing@example.com,member,,,',
        );

        $this->assertSame([
            2 => 'ready', 3 => 'warning', 4 => 'skipped', 5 => 'skipped',
            6 => 'error', 7 => 'error', 8 => 'error', 9 => 'error', 10 => 'error', 11 => 'error',
        ], $this->rowStatuses($import));

        $bilal = $import->rows()->where('row_number', 3)->sole();
        $this->assertSame(UserRole::MEMBER, $bilal->role);
        $this->assertNotNull($bilal->workspace_role_id);
        $this->assertNull($bilal->timezone);
        $this->assertContains('New department "Brand New Dept" will be created.', $bilal->messages);
        $this->assertSame('ayesha@example.com', $import->rows()->where('row_number', 2)->value('email'));
        $this->assertStringContainsString('Same email as row 2', implode(' ', $import->rows()->where('row_number', 10)->sole()->messages));

        $this->actingAs($this->owner)->get(route('workspace.people.imports.show', [$this->workspace, $import]))
            ->assertInertia(fn (Assert $page) => $page
                ->component('people/import-show')
                ->where('teamImport.status', 'validated')
                ->where('teamImport.importing_rows', 2)
                ->where('counts.ready', 1)
                ->where('counts.warning', 1)
                ->where('counts.skipped', 2)
                ->where('counts.error', 6)
                ->has('rows', 10));

        $this->assertSame(0, WorkspaceInvitation::query()->where('email', 'ayesha@example.com')->count(), 'nothing happens before confirming');
        $this->assertSame(0, Person::query()->where('email', 'ayesha@example.com')->count());
    }

    public function test_excel_files_with_shared_strings_are_read(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'xl');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('xl/workbook.xml', '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="People" sheetId="1" r:id="rId7"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId7" Type="worksheet" Target="worksheets/sheet3.xml"/></Relationships>');
        $strings = ['First Name', 'Last Name', 'Email', 'Role', 'Department', 'Usman', 'Ghani', 'usman@example.com', 'member', 'CSR'];
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.implode('', array_map(fn ($s) => "<si><t>{$s}</t></si>", $strings)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet3.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
            .'<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c><c r="E1" t="s"><v>4</v></c></row>'
            .'<row r="2"><c r="A2" t="s"><v>5</v></c><c r="B2" t="s"><v>6</v></c><c r="C2" t="s"><v>7</v></c><c r="D2" t="s"><v>8</v></c><c r="E2" t="s"><v>9</v></c></row>'
            .'<row r="3"><c r="A3" t="inlineStr"><is><t>Faheem</t></is></c><c r="B3" t="inlineStr"><is><t>Ali</t></is></c><c r="C3" t="inlineStr"><is><t>faheem@example.com</t></is></c><c r="D3" t="inlineStr"><is><t>admin</t></is></c></row>'
            .'</sheetData></worksheet>');
        $zip->close();

        $this->upload(new UploadedFile($path, 'people.xlsx', null, null, true))->assertSessionHasNoErrors();

        $import = TeamImport::query()->sole();
        $this->assertSame(['usman@example.com', 'faheem@example.com'], $import->rows()->orderBy('row_number')->pluck('email')->all());
        $this->assertSame('CSR', $import->rows()->where('row_number', 2)->value('department'));
        @unlink($path);
    }

    public static function badFiles(): array
    {
        return [
            'pdf' => [fn () => UploadedFile::fake()->create('team.pdf', 10, 'application/pdf'), 'xlsx or .csv'],
            'too large' => [fn () => UploadedFile::fake()->createWithContent('team.csv', self::HEADER."\n".str_repeat('x', 2100 * 1024)), '2 MB'],
            'missing columns' => [fn () => UploadedFile::fake()->createWithContent('team.csv', "name,email\nA,a@example.com"), 'Missing required columns: first_name, last_name, role'],
            'empty' => [fn () => UploadedFile::fake()->createWithContent('team.csv', self::HEADER), 'no teammate rows'],
            'too many rows' => [fn () => UploadedFile::fake()->createWithContent('team.csv', self::HEADER."\n".implode("\n", array_map(fn ($i) => "A,B,p{$i}@example.com,member,,,", range(1, 501)))), 'at most 500'],
            'corrupt xlsx' => [fn () => UploadedFile::fake()->createWithContent('team.xlsx', 'not a zip'), 'could not be read'],
        ];
    }

    #[DataProvider('badFiles')]
    public function test_bad_files_are_rejected_with_a_clear_message(\Closure $file, string $message): void
    {
        $this->assertFileError($this->upload($file()), $message);
        $this->assertSame(0, TeamImport::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_same_file_cannot_be_staged_twice_while_active(): void
    {
        $this->staged('A,B,a@example.com,member,,,');

        $this->assertFileError($this->upload($this->csv('A,B,a@example.com,member,,,')), 'already being imported');
        $this->assertSame(1, TeamImport::query()->count());
    }

    public function test_confirming_imports_valid_rows_through_the_normal_flows(): void
    {
        $existingAccount = User::factory()->create(['email' => 'has-account@example.com']);
        $existingProfile = Person::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => 'Old Profile', 'email' => 'profile@example.com']);
        $mentor = Person::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => 'Night Mentor', 'email' => 'mentor@example.com']);
        app(SaveWorkScheduleAction::class)->handle($this->workspace, $mentor, [1, 2, 3, 4, 5], '18:00', '03:00', 'Asia/Karachi', $this->owner);
        $agencyRole = WorkspaceRole::query()->forceCreate(['workspace_id' => $this->workspace->id, 'name' => 'Agency Partner', 'slug' => 'agency-partner', 'permissions' => ['client.board.view' => true]]);
        $usersBefore = User::query()->count();

        $import = $this->staged(
            'Ayesha,Khan,ayesha@example.com,member,Quote Operations,Asia/Karachi,mentor@example.com',
            'Has,Account,has-account@example.com,admin,,,',
            'New,Name,profile@example.com,member,,,',
            'Partner,Agency,ops@agency.example,client: Agency Partner,Ignored Dept,,',
            'Bad,Row,nope,member,,,',
        );
        $this->assertNotNull($import->path);
        Storage::disk('local')->assertExists($import->path);

        $this->confirm($import)->assertSessionHasNoErrors();
        $import->refresh();

        $this->assertSame(TeamImportStatus::Completed, $import->status);
        $this->assertSame(4, $import->processed_rows);
        $this->assertNull($import->path);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'the uploaded file is deleted');
        $this->assertSame($usersBefore, User::query()->count(), 'never creates accounts or passwords');

        $ayesha = WorkspaceInvitation::query()->where('email', 'ayesha@example.com')->sole();
        $this->assertSame(UserRole::MEMBER, $ayesha->role);
        $this->assertSame('Asia/Karachi', $ayesha->timezone);
        $ayeshaProfile = Person::query()->where('email', 'ayesha@example.com')->sole();
        $this->assertSame('Ayesha Khan', $ayeshaProfile->name);
        $this->assertSame('Quote Operations', $ayeshaProfile->department?->name);
        $copied = WorkSchedule::query()->where('person_id', $ayeshaProfile->id)->sole();
        $this->assertSame(['18:00', '03:00', 'Asia/Karachi'], [$copied->start_time, $copied->end_time, $copied->timezone]);

        $this->assertSame(UserRole::ADMIN, WorkspaceInvitation::query()->where('email', 'has-account@example.com')->sole()->role);
        $this->assertFalse($this->workspace->users()->whereKey($existingAccount->id)->exists(), 'existing accounts are invited, not force-added');

        $this->assertSame(1, Person::query()->where('email', 'profile@example.com')->count(), 'the existing profile is reused');
        $this->assertSame('Old Profile', $existingProfile->fresh()->name);
        $this->assertSame($existingProfile->id, $import->rows()->where('email', 'profile@example.com')->value('person_id'));

        $guest = WorkspaceInvitation::query()->where('email', 'ops@agency.example')->sole();
        $this->assertSame([UserRole::CLIENT, $agencyRole->id], [$guest->role, $guest->workspace_role_id]);
        $this->assertSame(0, Person::query()->where('email', 'ops@agency.example')->count(), 'guests get no team profile');

        $this->assertSame([TeamImportOutcome::Invited, TeamImportOutcome::Invited, TeamImportOutcome::Invited, TeamImportOutcome::Invited], $import->rows()->whereNotNull('outcome')->orderBy('row_number')->pluck('outcome')->all());
        $this->assertNull($import->rows()->where('row_number', 6)->value('outcome'), 'error rows are never processed');
        Mail::assertQueued(MemberInvitationMail::class, 4);

        $confirmed = AuditLog::query()->where('action', AuditAction::PERSON_IMPORT_CONFIRMED->value)->sole();
        $this->assertSame($this->owner->id, $confirmed->user_id);
        $this->assertStringContainsString('4 teammates from teammates.csv', $confirmed->description);
        $this->assertStringContainsString('4 invited, 0 linked, 1 skipped, 0 failed', AuditLog::query()->where('action', AuditAction::PERSON_IMPORT_COMPLETED->value)->sole()->description);
    }

    public function test_confirming_twice_imports_once(): void
    {
        $import = $this->staged('A,B,a@example.com,member,,,');

        $this->confirm($import)->assertSessionHasNoErrors();
        $this->confirm($import)->assertSessionHas('error');

        $this->assertSame(1, WorkspaceInvitation::query()->count());
        Mail::assertQueued(MemberInvitationMail::class, 1);
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PERSON_IMPORT_CONFIRMED->value)->count());
    }

    public function test_processing_is_retry_safe_and_chunked(): void
    {
        $import = $this->staged(...array_map(fn ($i) => "Person,{$i},p{$i}@example.com,member,,,", range(1, 60)));

        $import->forceFill(['status' => TeamImportStatus::Queued, 'confirmed_by' => $this->owner->id])->save();
        $import->rows()->where('row_number', '<=', 11)->update(['processed_at' => now(), 'outcome' => TeamImportOutcome::Invited]);

        (new ProcessTeamImportJob($import->id))->handle(app(ProcessTeamImportRowAction::class), app(RecordAuditLogAction::class));

        $import->refresh();
        $this->assertSame(TeamImportStatus::Completed, $import->status);
        $this->assertSame(60, $import->processed_rows);
        $this->assertSame(50, WorkspaceInvitation::query()->count(), 'rows already processed are not sent again');

        (new ProcessTeamImportJob($import->id))->handle(app(ProcessTeamImportRowAction::class), app(RecordAuditLogAction::class));
        $this->assertSame(50, WorkspaceInvitation::query()->count());
        $this->assertSame(1, AuditLog::query()->where('action', AuditAction::PERSON_IMPORT_COMPLETED->value)->count());
    }

    public function test_rows_are_rechecked_when_processed(): void
    {
        $import = $this->staged('Joined,Meanwhile,later@example.com,member,,,', 'Still,New,new@example.com,member,,,');
        $this->member(UserRole::MEMBER, ['email' => 'later@example.com']);

        $this->confirm($import);

        $later = $import->rows()->where('email', 'later@example.com')->sole();
        $this->assertSame(TeamImportOutcome::Skipped, $later->outcome);
        $this->assertContains('Skipped: already a member of this workspace.', $later->messages);
        $this->assertSame(TeamImportOutcome::Invited, $import->rows()->where('email', 'new@example.com')->value('outcome'));
    }

    public function test_cancelling_deletes_the_file_and_blocks_confirming(): void
    {
        $import = $this->staged('A,B,a@example.com,member,,,');

        $this->actingAs($this->owner)->post(route('workspace.people.imports.cancel', [$this->workspace, $import]))->assertRedirect(route('workspace.people.imports.create', $this->workspace));

        $this->assertSame(TeamImportStatus::Cancelled, $import->fresh()->status);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->confirm($import)->assertSessionHas('error');
        $this->assertSame(0, WorkspaceInvitation::query()->count());
    }

    public function test_unconfirmed_imports_expire_and_orphan_files_are_cleaned(): void
    {
        $old = $this->staged('A,B,a@example.com,member,,,');
        $old->forceFill(['created_at' => now()->subHours(25)])->save();
        $fresh = $this->staged('C,D,c@example.com,member,,,');
        Storage::disk('local')->put('team-imports/orphan/source.csv', 'x');

        $this->artisan('people:prune-team-imports')->assertSuccessful();

        $this->assertSame(TeamImportStatus::Expired, $old->fresh()->status);
        $this->assertSame(TeamImportStatus::Validated, $fresh->fresh()->status);
        Storage::disk('local')->assertMissing('team-imports/orphan/source.csv');
        Storage::disk('local')->assertExists($fresh->path);
    }

    public function test_the_issue_report_lists_rows_needing_attention_safely(): void
    {
        $this->member(UserRole::MEMBER, ['email' => 'already@example.com']);
        $import = $this->staged('Fine,Row,fine@example.com,member,,,', '=HYPERLINK("x"),Evil,bad,member,,,', 'Already,Here,already@example.com,member,,,');

        $response = $this->actingAs($this->owner)->get(route('workspace.people.imports.errors', [$this->workspace, $import]));
        $response->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('row,first_name,last_name,email,role,department,timezone,schedule,status,result,messages', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('already@example.com', $csv);
        $this->assertStringNotContainsString('fine@example.com', $csv);
    }

    public function test_other_workspaces_cannot_see_an_import(): void
    {
        $import = $this->staged('A,B,a@example.com,member,,,');
        $outsider = User::factory()->create();
        $theirs = Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->get(route('workspace.people.imports.show', [$theirs, $import]))->assertNotFound();
        $this->actingAs($outsider)->post(route('workspace.people.imports.confirm', [$theirs, $import]))->assertNotFound();
        $this->assertSame(TeamImportStatus::Validated, $import->fresh()->status);
    }

    public function test_the_invitation_page_suggests_the_imported_timezone(): void
    {
        $import = $this->staged('Ayesha,Khan,ayesha@example.com,member,,Asia/Karachi,');
        $this->confirm($import);
        $token = WorkspaceInvitation::query()->sole()->token;
        auth()->logout();

        $this->get(route('workspace.invitations.accept', ['token' => $token]))
            ->assertInertia(fn (Assert $page) => $page->where('invitation.timezone', 'Asia/Karachi'));
    }

    public function test_a_row_without_a_confirming_user_fails_safely(): void
    {
        $import = $this->staged('A,B,a@example.com,member,,,');
        $import->forceFill(['status' => TeamImportStatus::Queued, 'created_by' => null, 'confirmed_by' => null])->save();

        ProcessTeamImportJob::dispatchSync($import->id);

        $this->assertSame(TeamImportOutcome::Failed, $import->rows()->sole()->outcome);
        $this->assertSame(TeamImportStatus::Completed, $import->fresh()->status);
        $this->assertSame(0, WorkspaceInvitation::query()->count());
    }
}
