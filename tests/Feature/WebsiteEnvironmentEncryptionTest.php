<?php

namespace Tests\Feature;

use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Server\UpdateEnviromentScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebsiteEnvironmentEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_environment_configuration_is_encrypted_and_hidden_at_rest(): void
    {
        [$user, $website] = $this->website();
        $environment = "APP_ENV=production\nAPP_KEY=base64:top-secret-value";

        $website->update(['environment' => $environment]);
        $stored = Website::query()->toBase()->find($website->id)->environment;

        $this->assertSame($environment, $website->fresh()->environment);
        $this->assertNotSame($environment, $stored);
        $this->assertStringNotContainsString('top-secret-value', $stored);
        $this->assertArrayNotHasKey('environment', $website->fresh()->toArray());

        $this->actingAs($user)
            ->get(route('websites.edit', $website))
            ->assertSuccessful()
            ->assertSee($environment);
    }

    public function test_legacy_plaintext_environment_values_are_migrated_reversibly(): void
    {
        [, $website] = $this->website();
        $environment = "APP_ENV=production\nDATABASE_PASSWORD=legacy-secret";
        $website->update(['environment' => $environment]);
        $migration = require database_path('migrations/2026_08_30_200000_encrypt_website_environments.php');

        $migration->down();
        $this->assertSame($environment, Website::query()->toBase()->find($website->id)->environment);

        $migration->up();
        $this->assertSame($environment, $website->fresh()->environment);
        $this->assertNotSame($environment, Website::query()->toBase()->find($website->id)->environment);
    }

    public function test_provisioning_receives_decrypted_environment_without_exposing_plaintext_to_shell(): void
    {
        [, $website] = $this->website();
        $environment = "APP_ENV=production\nDANGEROUS='$(touch /tmp/environment-pwned)'";
        $website->update(['environment' => $environment]);

        $script = (new UpdateEnviromentScript)->script(3, $website->fresh());

        $this->assertStringContainsString(base64_encode($environment), $script);
        $this->assertStringNotContainsString('touch /tmp/environment-pwned', $script);
        $this->assertStringNotContainsString($environment, $script);
    }

    /**
     * @return array{User, Website}
     */
    private function website(): array
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Production',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);
        $website = $user->websites()->create([
            'server_id' => $server->id,
            'name' => 'Application',
            'description' => 'Production application',
            'environment' => 'APP_ENV=production',
            'url' => 'app.example.com',
            'provisioning_status' => Website::STATUS_ACTIVE,
        ]);

        return [$user, $website];
    }
}
