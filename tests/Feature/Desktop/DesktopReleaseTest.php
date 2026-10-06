<?php

declare(strict_types=1);

namespace Tests\Feature\Desktop;

use App\Models\User;
use App\Modules\Desktop\Models\DesktopRelease;
use App\Modules\Desktop\Support\DesktopTokens;
use App\Modules\Workspace\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Publishing and downloading desktop builds: files only ever come from the
 * private disk by channel, platform and architecture.
 */
final class DesktopReleaseTest extends TestCase
{
    use RefreshDatabase;

    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->folder = sys_get_temp_dir().'/sprintsync-release-test-'.uniqid();
        mkdir($this->folder);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->folder.'/*') ?: []);
        rmdir($this->folder);

        parent::tearDown();
    }

    private function build(string $name, string $contents = 'installer-bytes'): string
    {
        file_put_contents($this->folder.'/'.$name, $contents);

        return $this->folder.'/'.$name;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function publish(string $file, array $options = []): int
    {
        return $this->artisan('desktop:publish-release', [
            'file' => $file,
            '--platform' => 'macos',
            '--arch' => 'arm64',
            '--release' => '0.1.0',
            '--channel' => 'uat',
            ...$options,
        ])->run();
    }

    public function test_publishing_copies_the_build_with_its_checksum(): void
    {
        $this->assertSame(0, $this->publish($this->build('SprintSync.dmg'), ['--min-os' => 'macOS 12']));

        $release = DesktopRelease::query()->sole();
        $this->assertSame(hash('sha256', 'installer-bytes'), $release->sha256);
        $this->assertSame(15, $release->size_bytes);
        $this->assertFalse($release->signed);
        $this->assertSame('macOS 12', $release->min_os);
        $this->assertSame("desktop-releases/{$release->public_id}/SprintSync-0.1.0-macos-arm64.dmg", $release->path);
        Storage::disk('local')->assertExists($release->path);
    }

    public function test_publishing_rejects_bad_input(): void
    {
        $dmg = $this->build('SprintSync.dmg');

        $this->assertSame(1, $this->publish($this->build('evil.sh')));
        $this->assertSame(1, $this->publish($this->build('setup.exe'), ['--platform' => 'macos']));
        $this->assertSame(1, $this->publish($dmg, ['--arch' => 'sparc']));
        $this->assertSame(1, $this->publish($dmg, ['--release' => '../../1']));
        $this->assertSame(1, $this->publish($dmg, ['--channel' => 'beta']));
        $this->assertSame(1, $this->publish($dmg, ['--platform' => 'ios']));
        $this->assertSame(1, $this->publish($this->folder.'/missing.dmg'));
        $this->assertSame(0, DesktopRelease::query()->count());

        $this->assertSame(0, $this->publish($dmg));
        $this->assertSame(1, $this->publish($dmg), 'the same version twice');
        $this->assertSame(1, DesktopRelease::query()->count());
    }

    public function test_the_version_defaults_to_the_desktop_package_and_must_match_the_file(): void
    {
        $packageVersion = json_decode((string) file_get_contents(base_path('desktop/package.json')), true)['version'];

        $this->assertSame(1, $this->publish($this->build('SprintSync-9.9.9-macos-arm64.dmg'), ['--release' => null]));
        $this->assertSame(0, $this->publish($this->build("SprintSync-{$packageVersion}-macos-arm64.dmg"), ['--release' => null]));
        $this->assertSame($packageVersion, DesktopRelease::query()->sole()->version);
        $this->assertSame(0, $this->publish($this->build('SprintSync-0.2.0-uat.1-macos-arm64.dmg'), ['--release' => '0.2.0-uat.1']));
    }

    public function test_a_stable_build_downloads_publicly_with_its_checksum(): void
    {
        $this->publish($this->build('SprintSync.exe', 'windows-build'), ['--platform' => 'windows', '--arch' => 'x64', '--channel' => 'stable', '--signed' => true]);

        $response = $this->get('/download/desktop/windows/x64');

        $response->assertOk()
            ->assertHeader('X-Checksum-SHA256', hash('sha256', 'windows-build'))
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertDownload('SprintSync-0.1.0-windows-x64.exe');
        $this->assertSame('windows-build', $response->streamedContent());
    }

    public function test_the_newest_active_build_is_served(): void
    {
        $this->publish($this->build('a.dmg', 'old'), ['--channel' => 'stable']);
        $this->travel(1)->minutes();
        $this->publish($this->build('b.dmg', 'new'), ['--channel' => 'stable', '--release' => '0.2.0']);

        $this->assertSame('new', $this->get('/download/desktop/macos/arm64')->streamedContent());

        DesktopRelease::query()->where('version', '0.2.0')->update(['active' => false]);
        $this->assertSame('old', $this->get('/download/desktop/macos/arm64')->streamedContent());
    }

    public function test_uat_builds_need_a_signed_in_person(): void
    {
        $this->publish($this->build('SprintSync.dmg', 'uat-build'));

        $this->get('/download/desktop/uat/macos/arm64')->assertRedirect(route('login'));
        $this->get('/download/desktop/macos/arm64')->assertNotFound();

        $this->actingAs(User::factory()->create())->get('/download/desktop/uat/macos/arm64')->assertOk()->assertDownload('SprintSync-0.1.0-macos-arm64.dmg');
    }

    public function test_only_known_platforms_and_architectures_route(): void
    {
        $this->publish($this->build('SprintSync.dmg'), ['--channel' => 'stable']);

        foreach (['/download/desktop/macos/..%2F..%2F.env', '/download/desktop/../../.env', '/download/desktop/ios/arm64', '/download/desktop/macos/arm64.dmg', '/download/desktop/stable/macos/arm64'] as $path) {
            $this->get($path)->assertNotFound();
        }

        $this->get('/download/desktop/windows/x64')->assertNotFound();
    }

    public function test_a_missing_file_is_not_found_not_an_error(): void
    {
        $this->publish($this->build('SprintSync.dmg'), ['--channel' => 'stable']);
        Storage::disk('local')->delete(DesktopRelease::query()->sole()->path);

        $this->get('/download/desktop/macos/arm64')->assertNotFound();
    }

    public function test_the_landing_page_lists_stable_builds_only_without_paths(): void
    {
        $this->publish($this->build('SprintSync.dmg'));
        $this->publish($this->build('SprintSync.exe'), ['--platform' => 'windows', '--arch' => 'x64', '--channel' => 'stable']);

        $this->get('/')->assertInertia(fn (Assert $page) => $page
            ->component('Welcome')
            ->has('desktopDownloads', 1)
            ->where('desktopDownloads.0.platform', 'windows')
            ->where('desktopDownloads.0.url', route('desktop.download', ['platform' => 'windows', 'arch' => 'x64']))
            ->missing('desktopDownloads.0.path'));
    }

    public function test_signed_in_testers_also_see_uat_builds_on_the_landing_page(): void
    {
        $this->publish($this->build('SprintSync.dmg'));
        $this->publish($this->build('SprintSync.exe'), ['--platform' => 'windows', '--arch' => 'x64', '--channel' => 'stable']);

        $this->actingAs(User::factory()->create())->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('desktopDownloads', 2)
            ->where('desktopDownloads.0.channel', 'stable')
            ->where('desktopDownloads.1.channel', 'uat')
            ->where('desktopDownloads.1.url', route('desktop.download.uat', ['platform' => 'macos', 'arch' => 'arm64'])));
    }

    public function test_downloads_live_on_the_landing_page_not_the_time_page(): void
    {
        $owner = User::factory()->create();
        $workspace = Workspace::factory()->ownedBy($owner)->create();
        $this->publish($this->build('uat.dmg'));
        $this->publish($this->build('stable.dmg'), ['--channel' => 'stable', '--release' => '0.0.9']);
        $this->publish($this->build('SprintSync.exe'), ['--platform' => 'windows', '--arch' => 'x64']);

        $this->actingAs($owner)->get(route('workspace.time.index', $workspace))
            ->assertInertia(fn (Assert $page) => $page->component('time/index')->missing('desktopDownloads'));

        $this->actingAs($owner)->get('/')->assertInertia(fn (Assert $page) => $page
            ->has('desktopDownloads', 2)
            ->where('desktopDownloads.0.channel', 'stable')
            ->where('desktopDownloads.0.version', '0.0.9')
            ->where('desktopDownloads.1.channel', 'uat')
            ->where('desktopDownloads.1.url', route('desktop.download.uat', ['platform' => 'windows', 'arch' => 'x64'])));
    }

    public function test_download_links_carry_no_paths_or_tokens(): void
    {
        $this->publish($this->build('SprintSync.dmg'), ['--channel' => 'stable']);

        $download = DesktopRelease::query()->sole()->toDownload();

        $this->assertSame(route('desktop.download', ['platform' => 'macos', 'arch' => 'arm64']), $download['url']);
        $this->assertStringNotContainsString('desktop-releases', $download['url']);
        $this->assertStringNotContainsString('?', $download['url']);
        $this->assertArrayNotHasKey('path', $download);
    }

    public function test_the_desktop_app_asks_for_the_latest_build_with_its_token(): void
    {
        $this->publish($this->build('SprintSync.dmg'));
        $query = '?channel=uat&platform=macos&arch=arm64';

        $this->getJson('/api/desktop/releases/latest'.$query)->assertUnauthorized();

        [, $token] = app(DesktopTokens::class)->issue(User::factory()->create(), 'Mac', 'macos');
        $this->withToken($token)->getJson('/api/desktop/releases/latest'.$query)
            ->assertOk()
            ->assertJsonPath('release.version', '0.1.0')
            ->assertJsonPath('release.signed', false)
            ->assertJsonMissingPath('release.path');
        $this->withToken($token)->getJson('/api/desktop/releases/latest?channel=uat&platform=macos&arch=x64')->assertOk()->assertJsonPath('release', null);
        $this->withToken($token)->getJson('/api/desktop/releases/latest?channel=nightly&platform=macos&arch=arm64')->assertUnprocessable();
    }
}
