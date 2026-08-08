<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AvatarTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_image_can_be_uploaded(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/profile/avatar', [
                'avatar' => UploadedFile::fake()->image('avatar.jpg'),
            ])
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertNotNull($user->avatar_path);
        Storage::disk('public')->assertExists($user->avatar_path);
    }

    public function test_the_stored_path_is_persisted_on_the_user(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]);

        $user->refresh();

        $this->assertStringStartsWith('avatars/', $user->avatar_path);
    }

    public function test_the_avatar_url_is_exposed_through_the_shared_user_prop(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ]);

        $user->refresh();

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertInertia(fn ($page) => $page->where('auth.user.avatar_url', Storage::disk('public')->url($user->avatar_path)));
    }

    public function test_replacing_an_avatar_deletes_the_old_file_and_stores_the_new_one(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ]);

        $originalPath = $user->refresh()->avatar_path;
        Storage::disk('public')->assertExists($originalPath);

        $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ]);

        $newPath = $user->refresh()->avatar_path;

        $this->assertNotSame($originalPath, $newPath);
        Storage::disk('public')->assertMissing($originalPath);
        Storage::disk('public')->assertExists($newPath);
    }

    public function test_removing_an_avatar_deletes_the_file_and_clears_the_reference(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)->post('/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ]);

        $path = $user->refresh()->avatar_path;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user)
            ->delete('/settings/profile/avatar')
            ->assertRedirect('/settings/profile');

        $user->refresh();

        $this->assertNull($user->avatar_path);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_removing_when_no_avatar_exists_is_a_safe_no_op(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->delete('/settings/profile/avatar')
            ->assertRedirect('/settings/profile');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_a_non_image_file_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/profile/avatar', [
                'avatar' => UploadedFile::fake()->create('document.pdf', 100, 'application/pdf'),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('public')->assertDirectoryEmpty('avatars');
    }

    public function test_a_file_with_an_image_extension_but_non_image_content_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $fakeImage = UploadedFile::fake()->create('avatar.jpg', 50, 'application/pdf');

        $this->actingAs($user)
            ->post('/settings/profile/avatar', ['avatar' => $fakeImage])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post('/settings/profile/avatar', [
                'avatar' => UploadedFile::fake()->image('big.jpg')->size(3000),
            ])
            ->assertSessionHasErrors('avatar');

        $this->assertNull($user->refresh()->avatar_path);
    }

    public function test_a_user_cannot_modify_another_users_avatar(): void
    {
        Storage::fake('public');

        $owner = User::factory()->withAvatar('avatars/owner.jpg')->create();
        Storage::disk('public')->put('avatars/owner.jpg', 'fake-contents');

        $attacker = User::factory()->create();

        $this->actingAs($attacker)->post('/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('attack.jpg'),
        ]);

        $this->assertSame('avatars/owner.jpg', $owner->fresh()->avatar_path);
        Storage::disk('public')->assertExists('avatars/owner.jpg');

        $this->actingAs($attacker)->delete('/settings/profile/avatar');

        $this->assertSame('avatars/owner.jpg', $owner->fresh()->avatar_path);
        Storage::disk('public')->assertExists('avatars/owner.jpg');
    }

    public function test_initials_fallback_still_works_when_no_avatar_exists(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->actingAs($user)
            ->get('/settings/profile')
            ->assertInertia(fn ($page) => $page->where('auth.user.avatar_url', null));
    }

    public function test_guests_cannot_upload_or_remove_an_avatar(): void
    {
        Storage::fake('public');

        $this->post('/settings/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.jpg'),
        ])->assertRedirect('/login');

        $this->delete('/settings/profile/avatar')->assertRedirect('/login');
    }
}
