<?php

namespace Tests\Feature;

use App\Jobs\Web\DeleteWebsiteFromCaddyJob;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Models\Website;
use App\Scripts\Database\CreateMysqlDatabase;
use App\Scripts\Server\UpdateEnviromentScript;
use App\Scripts\Web\AddWebsiteToCaddyScript;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class WebsiteProvisioningSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_website_input_is_normalized_and_unsafe_hosts_are_rejected(): void
    {
        Queue::fake();
        [$user, $server] = $this->infrastructure();

        $this->actingAs($user)->post(route('websites.store'), [
            ...$this->payload($server),
            'url' => 'https://Portal.Example.com/',
        ])->assertRedirect();

        $this->assertSame('Portal.Example.com', Website::query()->sole()->url);

        foreach ([
            'example.com/path',
            'example.com?debug=true',
            'example.com { respond "owned" }',
            "example.com\nmalicious.test",
        ] as $host) {
            $this->actingAs($user)->post(route('websites.store'), [
                ...$this->payload($server),
                'url' => $host,
            ])->assertSessionHasErrors(['url']);
        }

        $this->assertDatabaseCount('websites', 1);
    }

    public function test_deployment_slugs_are_safe_unique_and_stable(): void
    {
        [$user, $server] = $this->infrastructure();

        $first = $this->website($user, $server, "Customer Portal'; touch /tmp/pwn");
        $second = $this->website($user, $server, "Customer Portal'; touch /tmp/pwn");

        $this->assertSame('customer-portal-touch-tmppwn', $first->deployment_slug);
        $this->assertSame('customer-portal-touch-tmppwn-2', $second->deployment_slug);

        $first->update(['name' => 'Renamed Portal']);
        $this->assertSame('customer-portal-touch-tmppwn', $first->fresh()->deployment_slug);
    }

    public function test_generated_website_scripts_do_not_evaluate_raw_user_content(): void
    {
        [$user, $server] = $this->infrastructure();
        $environment = "APP_ENV=production\nDANGEROUS='$(touch /tmp/pwned)'";
        $website = $this->website($user, $server, "Portal'; touch /tmp/name-pwned", $environment);

        $caddyScript = (new AddWebsiteToCaddyScript)->script(1, $website);
        $databaseScript = (new CreateMysqlDatabase)->script(2, $website);
        $environmentScript = (new UpdateEnviromentScript)->script(3, $website);

        $this->assertStringNotContainsString('touch /tmp/name-pwned', $caddyScript.$databaseScript.$environmentScript);
        $this->assertStringNotContainsString('touch /tmp/pwned', $environmentScript);
        $this->assertStringContainsString(base64_encode($environment), $environmentScript);
        $this->assertMatchesRegularExpression("/printf '%s' '[A-Za-z0-9+\\/=]+' \\| base64 --decode/", $caddyScript);
        preg_match("/printf '%s' '([^']+)' \\| base64 --decode/", $caddyScript, $matches);
        $this->assertStringContainsString(
            '/var/www/portal-touch-tmpname-pwned',
            base64_decode($matches[1], true),
        );
        $this->assertStringContainsString("--password='mysql-root-secret'", $databaseScript);
        $this->assertStringNotContainsString("--password='database-secret'", $databaseScript);
        $this->assertStringContainsString('`portal_touch_tmpname_pwned`', $databaseScript);

        foreach ([$caddyScript, $databaseScript, $environmentScript] as $script) {
            $this->assertStringContainsString('curl --fail --silent --show-error --retry 2', $script);
            $this->assertStringNotContainsString('--insecure', $script);
            $syntaxCheck = new Process(['bash', '-n']);
            $syntaxCheck->setInput($script);
            $syntaxCheck->run();
            $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
        }
    }

    public function test_mysql_root_password_is_encrypted_at_rest(): void
    {
        [, $server] = $this->infrastructure();

        $this->assertSame('mysql-root-secret', $server->mysql_root_password);
        $this->assertNotSame(
            $server->mysql_root_password,
            Server::query()->toBase()->find($server->id)->mysql_root_password,
        );
        $this->assertArrayNotHasKey('mysql_root_password', $server->toArray());
    }

    public function test_deleted_website_is_soft_deleted_and_queues_cleanup_by_identifier(): void
    {
        Queue::fake();
        [$user, $server] = $this->infrastructure();
        $website = $this->website($user, $server, 'Customer Portal');

        $this->actingAs($user)->delete(route('websites.destroy', $website))
            ->assertRedirect(route('websites.index'));

        $this->assertSoftDeleted($website);
        $this->assertArrayNotHasKey('provisioning_token', $website->toArray());
        Queue::assertPushed(DeleteWebsiteFromCaddyJob::class, function (DeleteWebsiteFromCaddyJob $job) use ($website): bool {
            return $job->websiteId === $website->id;
        });
    }

    private function infrastructure(): array
    {
        $user = User::factory()->create();
        $server = $user->servers()->create([
            'name' => 'Production',
            'type' => ServerTypeEnum::app,
            'provisioning_status' => Server::STATUS_ACTIVE,
            'mysql_root_password' => 'mysql-root-secret',
        ]);

        return [$user, $server];
    }

    private function website(
        User $user,
        Server $server,
        string $name,
        string $environment = 'APP_ENV=production',
    ): Website {
        return $user->websites()->create([
            ...$this->payload($server),
            'name' => $name,
            'environment' => $environment,
            'database_password' => 'database-secret',
        ]);
    }

    private function payload(Server $server): array
    {
        return [
            'server_id' => $server->id,
            'name' => 'Customer Portal',
            'url' => 'portal.example.com',
            'description' => 'Customer portal',
            'environment' => 'APP_ENV=production',
        ];
    }
}
