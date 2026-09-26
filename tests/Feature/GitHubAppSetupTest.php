<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Services\GitHubApp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

class GitHubAppSetupTest extends TestCase
{
    use RefreshDatabase;

    private string $keyPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->keyPath = sys_get_temp_dir().'/buildpusher-github-app-'.Str::uuid().'.pem';
        config([
            'github-app.id' => '12345',
            'github-app.slug' => 'buildpusher-test',
            'github-app.private_key' => null,
            'github-app.private_key_path' => $this->keyPath,
            'github-app.webhook_secret' => 'github-app-secret',
            'github-app.setup_enabled' => true,
            'lessbuild.platform_admin_emails' => ['admin@example.com'],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->keyPath)) {
            unlink($this->keyPath);
        }

        parent::tearDown();
    }

    public function test_platform_administrator_can_open_the_phone_friendly_setup_page(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->actingAs($admin)
            ->get(route('admin.github-app.setup'))
            ->assertOk()
            ->assertSee('GitHub App setup')
            ->assertSee('multipart/form-data');
    }

    public function test_non_platform_administrator_cannot_open_or_submit_setup(): void
    {
        $user = User::factory()->create(['email' => 'member@example.com']);

        $this->actingAs($user)->get(route('admin.github-app.setup'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.github-app.setup.store'), [
            'private_key' => UploadedFile::fake()->createWithContent('github-app.pem', $this->privateKey()),
        ])->assertForbidden();

        $this->assertFileDoesNotExist($this->keyPath);
    }

    public function test_disabled_setup_is_not_available(): void
    {
        config(['github-app.setup_enabled' => false]);
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->actingAs($admin)->get(route('admin.github-app.setup'))->assertNotFound();
    }

    public function test_a_key_fingerprint_is_not_treated_as_a_configured_private_key(): void
    {
        config(['github-app.private_key' => 'SHA256:configured-key-fingerprint']);
        $admin = User::factory()->create(['email' => 'admin@example.com']);

        $this->assertFalse(app(GitHubApp::class)->configured());
        $this->actingAs($admin)->get(route('github-app.connect'))->assertStatus(503);
    }

    public function test_invalid_upload_is_rejected_without_flashing_the_private_key(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $invalid = 'not-a-private-key';

        $response = $this->actingAs($admin)->post(route('admin.github-app.setup.store'), [
            'private_key' => UploadedFile::fake()->createWithContent('github-app.pem', $invalid),
        ]);

        $response->assertSessionHasErrors('private_key');
        $this->assertFileDoesNotExist($this->keyPath);
        $this->assertNull(session('_old_input.private_key'));
        $this->assertFalse(app(GitHubApp::class)->hasPrivateKey());
    }

    public function test_valid_upload_is_stored_privately_and_can_sign_app_requests(): void
    {
        $admin = User::factory()->create(['email' => 'admin@example.com']);
        $privateKey = $this->privateKey();

        $this->actingAs($admin)->post(route('admin.github-app.setup.store'), [
            'private_key' => UploadedFile::fake()->createWithContent('github-app.pem', $privateKey),
        ])->assertRedirect(route('providers.index'))->assertSessionHas('success');

        $this->assertFileExists($this->keyPath);
        $this->assertSame(trim($privateKey), trim((string) file_get_contents($this->keyPath)));
        $this->assertSame(0600, fileperms($this->keyPath) & 0777);
        $this->assertTrue(app(GitHubApp::class)->hasPrivateKey());
        $this->assertTrue(app(GitHubApp::class)->configured());
        $this->assertNull(session('_old_input.private_key'));
        $this->actingAs($admin)->get(route('admin.github-app.setup'))->assertNotFound();
    }

    private function privateKey(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $privateKey);

        return $privateKey;
    }
}
