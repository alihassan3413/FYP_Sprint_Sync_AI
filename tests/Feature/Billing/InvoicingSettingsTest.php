<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Modules\Audit\Data\AuditAction;
use App\Modules\Audit\Models\AuditLog;
use App\Modules\Billing\Models\InvoicingProfile;
use App\Modules\Workspace\Models\Workspace;
use App\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Settings → Invoicing: the workspace's own details on its invoices.
 */
final class InvoicingSettingsTest extends TestCase
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
    }

    /**
     * @return array<string, string>
     */
    private function details(array $overrides = []): array
    {
        return [
            'business_name' => 'SprintSync QA',
            'billing_email' => 'billing@example.com',
            'address_line1' => '123 Test Street',
            'city' => 'Lahore',
            'country' => 'Pakistan',
            'tax_id' => 'TEST-123',
            ...$overrides,
        ];
    }

    private function save(array $details, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.invoicing.edit', $this->workspace))
            ->put(route('workspace.invoicing.update', $this->workspace), $details);
    }

    private function uploadLogo(UploadedFile $file, ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->owner)
            ->from(route('workspace.invoicing.edit', $this->workspace))
            ->post(route('workspace.invoicing.logo.store', $this->workspace), ['logo' => $file]);
    }

    private function member(UserRole $role): User
    {
        $user = User::factory()->create();
        $this->workspace->users()->attach($user->id, ['role' => $role->value]);

        return $user;
    }

    public function test_the_owner_saves_invoicing_details(): void
    {
        $this->save($this->details(['legal_name' => '  ', 'postal_code' => '54000']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Invoicing details saved. New invoices will use them.');

        $profile = InvoicingProfile::query()->sole();
        $this->assertSame($this->workspace->id, $profile->workspace_id);
        $this->assertSame('SprintSync QA', $profile->business_name);
        $this->assertNull($profile->legal_name, 'blank optional fields are stored as null');
        $this->assertSame('54000', $profile->postal_code);

        $this->save($this->details(['city' => 'Karachi']))->assertSessionHasNoErrors();
        $this->assertSame(1, InvoicingProfile::query()->count());
        $this->assertSame('Karachi', $profile->fresh()->city);
        $this->assertSame(2, AuditLog::query()->where('action', AuditAction::INVOICING_UPDATED->value)->count());

        $this->actingAs($this->owner)
            ->get(route('workspace.invoicing.edit', $this->workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspace/settings/Invoicing')
                ->where('details.business_name', 'SprintSync QA')
                ->where('logoUrl', null)
                ->missing('details.workspace_id'));
    }

    public function test_required_details_are_validated(): void
    {
        $this->save(['business_name' => '', 'billing_email' => 'nope', 'address_line1' => '', 'city' => '', 'country' => ''])
            ->assertSessionHasErrors(['business_name', 'billing_email', 'address_line1', 'city', 'country']);

        $this->assertSame(0, InvoicingProfile::query()->count());
    }

    public function test_the_logo_is_re_encoded_and_stored_by_its_content_hash(): void
    {
        $this->save($this->details());

        $this->uploadLogo(UploadedFile::fake()->image('logo.jpg', 300, 120))->assertSessionHasNoErrors();

        $path = InvoicingProfile::query()->sole()->logo_path;
        $this->assertMatchesRegularExpression("#^workspaces/{$this->workspace->id}/invoicing/logos/[0-9a-f]{64}\\.png$#", $path);
        $bytes = Storage::disk('local')->get($path);
        $this->assertStringStartsWith("\x89PNG", $bytes, 're-encoded to PNG');
        $this->assertSame(hash('sha256', $bytes).'.png', basename($path));

        $this->actingAs($this->owner)
            ->get(route('workspace.invoicing.edit', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('logoUrl', fn ($url) => str_contains($url, '/settings/invoicing/logo') && ! str_contains($url, 'workspaces/')));

        $this->actingAs($this->owner)
            ->get(route('workspace.invoicing.logo.show', $this->workspace))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * @return array<string, array{0: UploadedFile}>
     */
    public static function badLogos(): array
    {
        return [
            'svg' => [UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>')],
            'gif' => [UploadedFile::fake()->image('logo.gif')],
            'pdf' => [UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf')],
            'script renamed to png' => [UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;')],
            'too wide' => [UploadedFile::fake()->image('logo.png', 4100, 10)],
            'too heavy' => [UploadedFile::fake()->image('logo.png')->size(3000)],
        ];
    }

    #[DataProvider('badLogos')]
    public function test_unsafe_or_oversized_logos_are_rejected(UploadedFile $file): void
    {
        $this->save($this->details());

        $this->uploadLogo($file)->assertSessionHasErrors('logo');

        $this->assertNull(InvoicingProfile::query()->sole()->logo_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_logo_needs_business_details_first(): void
    {
        $this->uploadLogo(UploadedFile::fake()->image('logo.png'))->assertSessionHasErrors('logo');

        $this->assertSame(0, InvoicingProfile::query()->count());
    }

    public function test_replacing_and_removing_the_logo_cleans_up_unused_files(): void
    {
        $this->save($this->details());
        $this->uploadLogo(UploadedFile::fake()->image('first.png', 100, 40));
        $first = InvoicingProfile::query()->sole()->logo_path;

        $this->uploadLogo(UploadedFile::fake()->image('second.png', 200, 80));
        $second = InvoicingProfile::query()->sole()->logo_path;

        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->actingAs($this->owner)
            ->delete(route('workspace.invoicing.logo.destroy', $this->workspace))
            ->assertSessionHas('success', 'Logo removed.');

        $this->assertNull(InvoicingProfile::query()->sole()->logo_path);
        Storage::disk('local')->assertMissing($second);
    }

    /**
     * @return array<string, array{0: UserRole}>
     */
    public static function nonOwners(): array
    {
        return ['admin' => [UserRole::ADMIN], 'member' => [UserRole::MEMBER], 'guest client' => [UserRole::CLIENT]];
    }

    #[DataProvider('nonOwners')]
    public function test_only_the_owner_can_see_or_change_invoicing_details(UserRole $role): void
    {
        $this->save($this->details());
        $this->uploadLogo(UploadedFile::fake()->image('logo.png'));
        $user = $this->member($role);

        $this->actingAs($user)->get(route('workspace.invoicing.edit', $this->workspace))->assertForbidden();
        $this->actingAs($user)->get(route('workspace.invoicing.logo.show', $this->workspace))->assertForbidden();
        $this->save($this->details(['business_name' => 'Hijacked']), $user)->assertForbidden();
        $this->uploadLogo(UploadedFile::fake()->image('evil.png'), $user)->assertForbidden();
        $this->actingAs($user)->delete(route('workspace.invoicing.logo.destroy', $this->workspace))->assertForbidden();

        $this->assertSame('SprintSync QA', InvoicingProfile::query()->sole()->business_name);
        $this->assertNotNull(InvoicingProfile::query()->sole()->logo_path);

        $this->actingAs($user)
            ->get(route('workspace.settings', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('canManageInvoicing', false));
    }

    public function test_other_workspaces_get_not_found(): void
    {
        $this->save($this->details());
        $outsider = User::factory()->create();
        Workspace::factory()->ownedBy($outsider)->create();

        $this->actingAs($outsider)->get(route('workspace.invoicing.edit', $this->workspace))->assertNotFound();
        $this->actingAs($outsider)->get(route('workspace.invoicing.logo.show', $this->workspace))->assertNotFound();
        $this->save($this->details(['business_name' => 'Hijacked']), $outsider)->assertNotFound();

        $this->assertSame('SprintSync QA', InvoicingProfile::query()->sole()->business_name);
    }

    public function test_the_settings_card_is_shown_to_the_owner(): void
    {
        $this->actingAs($this->owner)
            ->get(route('workspace.settings', $this->workspace))
            ->assertInertia(fn (Assert $page) => $page->where('canManageInvoicing', true));
    }
}
