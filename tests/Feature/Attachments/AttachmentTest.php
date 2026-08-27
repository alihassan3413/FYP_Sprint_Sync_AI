<?php

declare(strict_types=1);

namespace Tests\Feature\Attachments;

use App\Models\User;
use App\Modules\Attachments\Actions\ClaimAttachmentsAction;
use App\Modules\Attachments\Actions\StoreAttachmentAction;
use App\Modules\Attachments\Models\Attachment;
use App\Modules\Projects\Models\Project;
use App\Modules\Tasks\Models\Task;
use App\Modules\Tasks\Models\TaskComment;
use App\Modules\Workspace\Models\Workspace;
use App\ProjectRole;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AttachmentTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->owner->forceFill(['current_workspace_id' => $this->workspace->id])->save();
    }

    private function store(UploadedFile $file, ?User $user = null, ?Workspace $workspace = null): Attachment
    {
        return app(StoreAttachmentAction::class)->handle(
            $workspace ?? $this->workspace,
            $user ?? $this->owner,
            $file,
            'local',
        );
    }

    private function comment(?Task $task = null, ?User $author = null): TaskComment
    {
        $task ??= $this->task();

        return $task->comments()->create([
            'body' => 'Looks broken',
            'user_id' => ($author ?? $this->owner)->id,
        ]);
    }

    private function task(?Workspace $workspace = null): Task
    {
        $workspace ??= $this->workspace;
        $project = Project::factory()->forWorkspace($workspace)->create();

        return Task::factory()->create([
            'project_id' => $project->id,
            'workspace_id' => $workspace->id,
        ]);
    }

    private function claim(TaskComment $comment, array $ids, ?User $user = null): array
    {
        return app(ClaimAttachmentsAction::class)->handle(
            $comment,
            $user ?? $this->owner,
            $ids,
            6,
            $comment->task->workspace_id,
        );
    }

    /**
     * @param  array<int, int>  $ids
     */
    private function createTaskWithAttachments(array $ids, ?User $actor = null): TestResponse
    {
        $actor ??= $this->owner;
        $project = Project::factory()->forWorkspace($this->workspace)->create();

        return $this->actingAs($actor)->post(
            route('workspace.projects.tasks.store', [
                'workspace' => $this->workspace->slug,
                'project' => $project->id,
            ]),
            ['title' => 'Task with files', 'attachment_ids' => $ids],
        );
    }

    public function test_a_pdf_a_spreadsheet_and_a_csv_are_all_accepted(): void
    {
        foreach ([['report.pdf', 'application/pdf'], ['budget.xlsx', 'application/vnd.ms-excel'], ['rows.csv', 'text/csv']] as [$name, $mime]) {
            $this->actingAs($this->owner)
                ->post('/attachments', ['file' => UploadedFile::fake()->create($name, 40, $mime)])
                ->assertCreated();
        }

        $this->assertSame(3, Attachment::query()->count());
    }

    public function test_a_file_over_the_size_limit_is_rejected(): void
    {
        config()->set('attachments.max_kilobytes', 100);

        $this->actingAs($this->owner)
            ->postJson('/attachments', ['file' => UploadedFile::fake()->create('huge.pdf', 250, 'application/pdf')])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_a_file_at_the_size_limit_is_accepted(): void
    {
        config()->set('attachments.max_kilobytes', 100);

        $this->actingAs($this->owner)
            ->post('/attachments', ['file' => UploadedFile::fake()->create('fits.pdf', 100, 'application/pdf')])
            ->assertCreated();
    }

    public function test_an_executable_is_still_rejected_after_widening_the_allowlist(): void
    {
        foreach (['payload.exe', 'script.sh', 'shell.php', 'page.html'] as $name) {
            $this->actingAs($this->owner)
                ->postJson('/attachments', ['file' => UploadedFile::fake()->create($name, 10)])
                ->assertStatus(422);
        }

        $this->assertSame(0, Attachment::query()->count());
    }

    public function test_an_image_is_served_inline_but_a_document_downloads(): void
    {
        $image = $this->store(UploadedFile::fake()->image('shot.png', 40, 40));
        $document = $this->store(UploadedFile::fake()->create('notes.csv', 4, 'text/csv'));

        $this->claim($this->comment(), [$image->id, $document->id]);

        $imageResponse = $this->actingAs($this->owner)->get(route('attachments.show', $image));
        $imageResponse->assertOk();
        $this->assertStringContainsString('inline', (string) $imageResponse->headers->get('content-disposition'));
        $this->assertSame('nosniff', $imageResponse->headers->get('x-content-type-options'));

        $documentResponse = $this->actingAs($this->owner)->get(route('attachments.show', $document));
        $documentResponse->assertOk();
        $this->assertStringContainsString('attachment', (string) $documentResponse->headers->get('content-disposition'));
    }

    public function test_a_task_can_be_created_with_attachments(): void
    {
        $first = $this->store(UploadedFile::fake()->create('spec.pdf', 20, 'application/pdf'));
        $second = $this->store(UploadedFile::fake()->image('mock.png', 60, 60));

        $this->createTaskWithAttachments([$first->id, $second->id])->assertRedirect();

        $task = Task::query()->where('title', 'Task with files')->firstOrFail();

        $this->assertSame(2, $task->attachments()->count());
        $this->assertTrue($first->fresh()->isClaimed());
        $this->assertTrue($second->fresh()->isClaimed());
    }

    public function test_more_attachments_than_a_task_allows_are_rejected(): void
    {
        config()->set('attachments.max_per_task', 2);

        $ids = collect(range(1, 3))
            ->map(fn (int $i) => $this->store(UploadedFile::fake()->create("file-{$i}.pdf", 5, 'application/pdf'))->id)
            ->all();

        $this->createTaskWithAttachments($ids)->assertSessionHasErrors('attachment_ids');

        $this->assertSame(0, Task::query()->where('title', 'Task with files')->count());
    }

    public function test_a_task_cannot_claim_someone_elses_upload(): void
    {
        $stranger = User::factory()->create();
        $theirs = $this->store(UploadedFile::fake()->create('theirs.pdf', 5, 'application/pdf'), $stranger);

        $this->createTaskWithAttachments([$theirs->id])->assertRedirect();

        $task = Task::query()->where('title', 'Task with files')->firstOrFail();

        $this->assertSame(0, $task->attachments()->count());
        $this->assertFalse($theirs->fresh()->isClaimed());
    }

    public function test_task_attachments_reach_the_project_page(): void
    {
        $attachment = $this->store(UploadedFile::fake()->create('brief.pdf', 12, 'application/pdf'));

        $this->createTaskWithAttachments([$attachment->id])->assertRedirect();

        $task = Task::query()->where('title', 'Task with files')->firstOrFail();

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', ['workspace' => $this->workspace->slug, 'project' => $task->project_id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('tasks.0.attachments.0.name', 'brief.pdf'));
    }

    public function test_upload_limits_are_shared_with_the_browser(): void
    {
        $this->actingAs($this->owner)
            ->get(route('dashboard', ['workspace' => $this->workspace->slug]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('attachments.max_kilobytes', (int) config('attachments.max_kilobytes'))
                ->where('attachments.max_per_task', (int) config('attachments.max_per_task')));
    }

    public function test_a_large_image_gets_a_downscaled_preview(): void
    {
        $attachment = $this->store(UploadedFile::fake()->image('screenshot.png', 3000, 2000));

        $this->assertSame(3000, $attachment->width);
        $this->assertNotNull($attachment->preview_path);

        [$previewWidth] = getimagesizefromstring((string) $attachment->previewContents());

        $this->assertLessThanOrEqual(1568, $previewWidth);
    }

    public function test_a_small_image_is_not_copied_twice(): void
    {
        $attachment = $this->store(UploadedFile::fake()->image('icon.png', 400, 300));

        $this->assertNull($attachment->preview_path);
        $this->assertNotNull($attachment->previewContents());
    }

    public function test_a_non_image_file_is_accepted_without_a_preview(): void
    {
        $attachment = $this->store(UploadedFile::fake()->create('spec.pdf', 40, 'application/pdf'));

        $this->assertFalse($attachment->isImage());
        $this->assertNull($attachment->preview_path);
    }

    public function test_an_unsupported_file_type_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->postJson(route('attachments.store'), ['file' => UploadedFile::fake()->create('payload.exe', 12)])
            ->assertJsonValidationErrors('file');
    }

    public function test_uploading_requires_signing_in(): void
    {
        $this->postJson(route('attachments.store'), ['file' => UploadedFile::fake()->image('a.png')])
            ->assertUnauthorized();
    }

    public function test_a_comment_can_be_posted_with_an_attachment(): void
    {
        $task = $this->task();
        $attachment = $this->store(UploadedFile::fake()->image('bug.png', 800, 600));

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.tasks.comments.store', [
                'workspace' => $this->workspace->slug,
                'project' => $task->project_id,
                'task' => $task->id,
            ]), [
                'body' => 'Here is the bug',
                'attachment_ids' => [$attachment->id],
            ])
            ->assertRedirect();

        $comment = TaskComment::query()->where('task_id', $task->id)->firstOrFail();

        $this->assertCount(1, $comment->attachments);
        $this->assertSame('bug.png', $comment->attachments->first()->name);
    }

    public function test_a_comment_can_carry_several_attachments(): void
    {
        $task = $this->task();

        $ids = collect(['one.png', 'two.png', 'three.png'])
            ->map(fn (string $name) => $this->store(UploadedFile::fake()->image($name, 500, 400))->id)
            ->all();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.tasks.comments.store', [
                'workspace' => $this->workspace->slug,
                'project' => $task->project_id,
                'task' => $task->id,
            ]), ['body' => 'Three shots', 'attachment_ids' => $ids])
            ->assertRedirect();

        $this->assertCount(3, TaskComment::query()->where('task_id', $task->id)->firstOrFail()->attachments);
    }

    public function test_more_attachments_than_allowed_are_rejected(): void
    {
        $task = $this->task();

        $ids = collect(range(1, 8))
            ->map(fn (int $n) => $this->store(UploadedFile::fake()->image("shot{$n}.png", 300, 200))->id)
            ->all();

        $this->actingAs($this->owner)
            ->post(route('workspace.projects.tasks.comments.store', [
                'workspace' => $this->workspace->slug,
                'project' => $task->project_id,
                'task' => $task->id,
            ]), ['body' => 'Too many', 'attachment_ids' => $ids])
            ->assertSessionHasErrors('attachment_ids');
    }

    public function test_an_attachment_is_readable_by_anyone_who_can_see_the_task(): void
    {
        $task = $this->task();
        $attachment = $this->store(UploadedFile::fake()->image('shared.png', 600, 400));
        $this->claim($this->comment($task), [$attachment->id]);

        $teammate = User::factory()->create();
        $this->workspace->users()->attach($teammate->id, ['role' => UserRole::MEMBER->value]);
        $task->project->members()->attach($teammate->id, ['role' => ProjectRole::MEMBER->value]);

        $this->actingAs($teammate->refresh())
            ->get(route('attachments.show', $attachment))
            ->assertOk();
    }

    public function test_an_attachment_is_denied_to_someone_who_cannot_see_the_task(): void
    {
        $task = $this->task();
        $attachment = $this->store(UploadedFile::fake()->image('private.png', 600, 400));
        $this->claim($this->comment($task), [$attachment->id]);

        $outsider = User::factory()->create();
        $this->workspace->users()->attach($outsider->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($outsider->refresh())
            ->get(route('attachments.show', $attachment))
            ->assertForbidden();
    }

    public function test_a_guest_cannot_read_an_attachment(): void
    {
        $attachment = $this->store(UploadedFile::fake()->image('shot.png', 300, 200));
        $this->claim($this->comment(), [$attachment->id]);

        $this->get(route('attachments.show', $attachment))->assertRedirect(route('login'));
    }

    public function test_an_unclaimed_upload_stays_private_to_its_uploader(): void
    {
        $attachment = $this->store(UploadedFile::fake()->image('draft.png', 300, 200));

        $this->actingAs($this->owner)->get(route('attachments.show', $attachment))->assertOk();

        $someoneElse = User::factory()->create();
        $this->workspace->users()->attach($someoneElse->id, ['role' => UserRole::MEMBER->value]);

        $this->actingAs($someoneElse->refresh())
            ->get(route('attachments.show', $attachment))
            ->assertForbidden();
    }

    public function test_an_upload_cannot_be_claimed_by_another_user(): void
    {
        $mine = $this->store(UploadedFile::fake()->image('mine.png', 300, 200));

        $thief = User::factory()->create();
        $this->workspace->users()->attach($thief->id, ['role' => UserRole::MEMBER->value]);

        $claimed = $this->claim($this->comment(), [$mine->id], $thief->refresh());

        $this->assertSame([], $claimed);
        $this->assertNull($mine->refresh()->attachable_type);
    }

    public function test_an_upload_from_another_workspace_cannot_be_claimed(): void
    {
        $otherOwner = User::factory()->create();
        $otherWorkspace = Workspace::factory()->ownedBy($otherOwner)->create();

        $foreign = $this->store(UploadedFile::fake()->image('foreign.png', 300, 200), $this->owner, $otherWorkspace);

        $claimed = $this->claim($this->comment(), [$foreign->id]);

        $this->assertSame([], $claimed);
        $this->assertNull($foreign->refresh()->attachable_type);
    }

    public function test_an_attachment_cannot_be_claimed_twice(): void
    {
        $task = $this->task();
        $attachment = $this->store(UploadedFile::fake()->image('once.png', 300, 200));

        $this->claim($this->comment($task), [$attachment->id]);
        $stolen = $this->claim($this->comment($task), [$attachment->id]);

        $this->assertSame([], $stolen);
    }

    public function test_attachments_reach_the_project_page_after_posting(): void
    {
        $task = $this->task();
        $attachment = $this->store(UploadedFile::fake()->image('render.png', 900, 600));
        $this->claim($this->comment($task), [$attachment->id]);

        $this->actingAs($this->owner)
            ->get(route('workspace.projects.show', [
                'workspace' => $this->workspace->slug,
                'project' => $task->project_id,
            ]))
            ->assertOk()
            ->assertSee('render.png');
    }

    public function test_deleting_removes_both_copies_from_disk(): void
    {
        $attachment = $this->store(UploadedFile::fake()->image('gone.png', 2400, 1600));

        $original = $attachment->path;
        $preview = $attachment->preview_path;

        $attachment->delete();

        Storage::disk('local')->assertMissing($original);
        Storage::disk('local')->assertMissing($preview);
    }

    public function test_unclaimed_uploads_are_pruned_but_claimed_ones_survive(): void
    {
        $abandoned = $this->store(UploadedFile::fake()->image('abandoned.png', 300, 200));
        $abandoned->forceFill(['created_at' => now()->subDays(3)])->save();

        $kept = $this->store(UploadedFile::fake()->image('kept.png', 300, 200));
        $kept->forceFill(['created_at' => now()->subDays(3)])->save();
        $this->claim($this->comment(), [$kept->id]);

        $this->artisan('attachments:prune')->assertSuccessful();

        $this->assertNull(Attachment::query()->find($abandoned->id));
        $this->assertNotNull(Attachment::query()->find($kept->id));
    }
}
