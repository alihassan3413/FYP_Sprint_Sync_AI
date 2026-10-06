<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\Client;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ClientLogoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Workspace $workspace;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(Client::LOGO_DISK);

        $this->owner = User::factory()->create();
        $this->workspace = Workspace::factory()->ownedBy($this->owner)->create();
        $this->client = Client::factory()->rocketFlood()->for($this->workspace)->create();
    }

    private function upload(UploadedFile $file, ?User $as = null, ?Client $client = null)
    {
        return $this->actingAs($as ?? $this->owner)->post(
            route('workspace.clients.logo.store', [$this->workspace, $client ?? $this->client]),
            ['logo' => $file],
        );
    }

    public function test_the_owner_can_upload_a_logo_stored_privately_under_a_generated_name(): void
    {
        $this->upload(UploadedFile::fake()->image('../../evil name.png', 200, 80))
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Logo added.');

        $path = $this->client->fresh()->logo_path;

        $this->assertNotNull($path);
        $this->assertStringStartsWith("workspaces/{$this->workspace->id}/clients/{$this->client->public_id}/", $path);
        $this->assertMatchesRegularExpression('/\/[0-9a-z]{26}\.png$/', $path);
        $this->assertStringNotContainsString('evil', $path);
        Storage::disk(Client::LOGO_DISK)->assertExists($path);
    }

    public function test_jpeg_and_webp_are_accepted(): void
    {
        foreach (['logo.jpg', 'logo.webp'] as $name) {
            $this->upload(UploadedFile::fake()->image($name, 120, 120))->assertSessionHasNoErrors();
        }

        $this->assertStringEndsWith('.webp', $this->client->fresh()->logo_path);
    }

    public function test_props_expose_an_authorized_url_and_never_the_path(): void
    {
        $this->upload(UploadedFile::fake()->image('logo.png', 120, 120));

        $client = $this->client->fresh();
        $fileName = basename((string) $client->logo_path);

        $this->actingAs($this->owner)
            ->get(route('workspace.clients.show', [$this->workspace, $client]))
            ->assertInertia(fn ($page) => $page->where('client.logo_url', fn (string $url) => str_contains($url, "/clients/{$client->public_id}/logo?v=")))
            ->assertDontSee($fileName);
    }

    /**
     * @return array<string, array{0: UploadedFile}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'svg' => [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'text renamed to png' => [UploadedFile::fake()->createWithContent('logo.png', '<?php echo "hi"; ?>')],
            'gif' => [UploadedFile::fake()->image('logo.gif', 50, 50)],
            'pdf' => [UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')],
            'too large' => [UploadedFile::fake()->image('logo.png', 100, 100)->size(3000)],
            'too many pixels' => [UploadedFile::fake()->image('logo.png', 4001, 10)],
        ];
    }

    #[DataProvider('rejectedFiles')]
    public function test_unsafe_or_unsupported_files_are_rejected(UploadedFile $file): void
    {
        $this->upload($file)->assertSessionHasErrors('logo');

        $this->assertNull($this->client->fresh()->logo_path);
        $this->assertSame([], Storage::disk(Client::LOGO_DISK)->allFiles());
    }

    public function test_a_missing_file_is_rejected(): void
    {
        $this->actingAs($this->owner)
            ->post(route('workspace.clients.logo.store', [$this->workspace, $this->client]), [])
            ->assertSessionHasErrors('logo');
    }

    public function test_replacing_a_logo_deletes_the_old_file(): void
    {
        $this->upload(UploadedFile::fake()->image('first.png', 100, 100));
        $first = $this->client->fresh()->logo_path;

        $this->upload(UploadedFile::fake()->image('second.png', 100, 100))->assertSessionHas('success', 'Logo changed.');
        $second = $this->client->fresh()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk(Client::LOGO_DISK)->assertMissing($first);
        Storage::disk(Client::LOGO_DISK)->assertExists($second);
        $this->assertCount(1, Storage::disk(Client::LOGO_DISK)->allFiles());
    }

    public function test_removing_a_logo_deletes_the_file_and_is_harmless_to_repeat(): void
    {
        $this->upload(UploadedFile::fake()->image('logo.png', 100, 100));
        $path = $this->client->fresh()->logo_path;

        foreach ([1, 2] as $attempt) {
            $this->actingAs($this->owner)
                ->delete(route('workspace.clients.logo.destroy', [$this->workspace, $this->client]))
                ->assertRedirect();
        }

        $this->assertNull($this->client->fresh()->logo_path);
        Storage::disk(Client::LOGO_DISK)->assertMissing($path);
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::CLIENT_UPDATED->value)->count());
    }

    public function test_archiving_and_restoring_keep_the_logo(): void
    {
        $this->upload(UploadedFile::fake()->image('logo.png', 100, 100));
        $path = $this->client->fresh()->logo_path;

        $this->actingAs($this->owner)->post(route('workspace.clients.archive', [$this->workspace, $this->client]));
        $this->actingAs($this->owner)->post(route('workspace.clients.restore', [$this->workspace, $this->client]));

        $this->assertSame($path, $this->client->fresh()->logo_path);
        Storage::disk(Client::LOGO_DISK)->assertExists($path);
    }

    public function test_the_owner_can_view_the_logo_with_safe_headers(): void
    {
        $this->upload(UploadedFile::fake()->image('logo.png', 100, 100));

        $response = $this->actingAs($this->owner)
            ->get(route('workspace.clients.logo.show', [$this->workspace, $this->client]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Type', 'image/png');

        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringStartsWith('inline', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_a_client_without_a_logo_has_no_logo_to_view(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.clients.logo.show', [$this->workspace, $this->client]))
            ->assertNotFound();
    }

    public function test_non_owners_cannot_view_upload_or_remove_logos(): void
    {
        $this->upload(UploadedFile::fake()->image('logo.png', 100, 100));
        $path = $this->client->fresh()->logo_path;

        foreach ([UserRole::ADMIN, UserRole::MEMBER, UserRole::CLIENT] as $role) {
            $user = User::factory()->create();
            $this->workspace->users()->attach($user->id, ['role' => $role->value]);

            $this->actingAs($user)->get(route('workspace.clients.logo.show', [$this->workspace, $this->client]))->assertForbidden();
            $this->upload(UploadedFile::fake()->image('other.png', 100, 100), $user)->assertForbidden();
            $this->actingAs($user)->delete(route('workspace.clients.logo.destroy', [$this->workspace, $this->client]))->assertForbidden();
        }

        $this->assertSame($path, $this->client->fresh()->logo_path);
        $this->assertCount(1, Storage::disk(Client::LOGO_DISK)->allFiles());
    }

    public function test_logos_are_isolated_between_workspaces(): void
    {
        $this->upload(UploadedFile::fake()->image('logo.png', 100, 100));

        $outsider = User::factory()->create();
        $theirs = Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)
            ->get(route('workspace.clients.logo.show', ['workspace' => $theirs->slug, 'client' => $this->client->public_id]))
            ->assertNotFound();

        $this->actingAs($outsider)
            ->get(route('workspace.clients.logo.show', [$this->workspace, $this->client]))
            ->assertNotFound();

        $this->actingAs($this->owner)
            ->get('/'.$this->workspace->slug.'/clients/'.Str::ulid().'/logo')
            ->assertNotFound();
    }
}
