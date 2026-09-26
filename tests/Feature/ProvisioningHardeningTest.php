<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\Enums\Server\ServerTypeEnum;
use App\Modules\Deployer\Models\Server;
use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\Scripts\Cache\InstallMemcachedScript;
use App\Modules\Deployer\Scripts\Cache\InstallRedisScript;
use App\Modules\Deployer\Scripts\Database\CreateMysqlDatabase;
use App\Modules\Deployer\Scripts\Database\InstallMysqlScript;
use App\Modules\Deployer\Scripts\Languages\InstallPHPScript;
use App\Modules\Deployer\Scripts\Server\ConfigureServerScript;
use App\Modules\Deployer\Scripts\Server\EndScript;
use App\Modules\Deployer\Scripts\Server\UpdateDependenciesScript;
use App\Modules\Deployer\Scripts\Web\AddWebsiteToCaddyScript;
use App\Modules\Deployer\Scripts\Web\InstallCaddyScript;
use App\Modules\Deployer\Services\ProvisioningScriptRenderer;
use App\Modules\Deployer\Services\ServerProvisioningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProvisioningHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_managed_services_do_not_bind_datastores_to_public_interfaces_or_weaken_kernel_protections(): void
    {
        $server = $this->server();
        $mysql = (new InstallMysqlScript)->script(1, $server);
        $redis = (new InstallRedisScript)->script(1, $server);
        $memcached = (new InstallMemcachedScript)->script(1, $server);

        $this->assertStringContainsString('bind-address = 127.0.0.1', $mysql);
        $this->assertStringContainsString('mysql --user=root -e "SELECT 1"', $mysql);
        $this->assertStringNotContainsString('yes | sudo apt install mysql-server', $mysql);
        $this->assertStringNotContainsString("root'@'%'", $mysql);
        $this->assertStringContainsString('bind 127.0.0.1 ::1', $redis);
        $this->assertStringContainsString('protected-mode yes', $redis);
        $this->assertStringNotContainsString('pecl install', $redis);
        $this->assertStringContainsString('-l 127.0.0.1', $memcached);
        $this->assertStringNotContainsString('0.0.0.0', $memcached);
        $this->assertStringNotContainsString('protected_regular = 0', $memcached);
    }

    public function test_ssh_hardening_is_validated_and_preserves_existing_root_credentials_and_keys(): void
    {
        $script = (new ConfigureServerScript)->script(1, $this->server());
        $this->assertStringContainsString('/etc/ssh/sshd_config.d/99-buildpusher.conf', $script);
        $this->assertStringContainsString('sshd -t', $script);
        $this->assertStringContainsString('systemctl reload ssh', $script);
        $this->assertStringNotContainsString('service ssh restart', $script);
        $this->assertStringNotContainsString('echo "root:', $script);
        $this->assertStringNotContainsString('cp /root/.ssh/authorized_keys', $script);
        $this->assertStringContainsString('visudo -cf', $script);
        $this->assertStringContainsString('ufw default deny incoming', $script);
        $this->assertStringContainsString('SSH_PORT="${SSH_CONNECTION:-}"', $script);
        $this->assertStringContainsString('ufw allow OpenSSH', $script);
        $this->assertStringContainsString('ufw allow 80/tcp', $script);
        $this->assertStringContainsString('ufw allow 443/tcp', $script);
        $this->assertStringContainsString('ufw --force enable', $script);
        $this->assertStringContainsString('backupManagedFile /etc/ufw/user.rules', $script);
        $this->assertStringNotContainsString('ssh-keyscan -H', $script);
        $this->assertStringContainsString('github.com ssh-ed25519', $script);
        $this->assertStringContainsString('gitlab.com ssh-ed25519', $script);
        $this->assertStringContainsString('bitbucket.org ssh-ed25519', $script);
    }

    public function test_caddy_install_preserves_operator_configuration_and_validates_before_reload(): void
    {
        $script = (new InstallCaddyScript)->script(1, $this->server());
        $this->assertStringContainsString('Caddyfile.pre-buildpusher', $script);
        $this->assertStringNotContainsString('echo "import /etc/caddy/websites/*" > /etc/caddy/Caddyfile', $script);
        $this->assertStringContainsString("grep -qxF 'import /etc/caddy/websites/*'", $script);
        $this->assertStringContainsString('caddy validate --config /etc/caddy/Caddyfile', $script);
    }

    public function test_website_caddy_script_owns_access_log_before_reload(): void
    {
        $server = $this->server();
        $website = $server->websites()->create([
            'user_id' => $server->user_id,
            'organization_id' => $server->organization_id,
            'name' => 'site',
            'description' => 'Test site',
            'environment' => 'APP_ENV=test',
            'url' => 'site.example.test',
            'deployment_slug' => 'site-test',
            'database_password' => 'database-secret',
        ]);
        $script = (new AddWebsiteToCaddyScript)->script(1, $website);

        $this->assertStringContainsString('chown caddy:caddy', $script);
        $this->assertStringContainsString('chmod 640', $script);
    }

    public function test_website_caddy_script_keeps_literal_ip_hosts_on_http(): void
    {
        $server = $this->server();
        $website = $server->websites()->create([
            'user_id' => $server->user_id,
            'organization_id' => $server->organization_id,
            'name' => 'site',
            'description' => 'Test site',
            'environment' => 'APP_ENV=test',
            'url' => '192.0.2.10',
            'deployment_slug' => 'site-test',
            'database_password' => 'database-secret',
        ]);
        $script = (new AddWebsiteToCaddyScript)->script(1, $website);

        $this->assertSame(1, preg_match("/printf '%s' '([^']+)' \\| base64 --decode/", $script, $matches));
        $configuration = base64_decode($matches[1], true);

        $this->assertIsString($configuration);
        $this->assertStringStartsWith('http://192.0.2.10 {', ltrim($configuration));
    }

    public function test_provisioning_uses_supported_php_and_writes_a_management_marker(): void
    {
        config(['lessbuild.default_php_version' => '8.4']);
        $server = $this->server();
        $this->assertStringContainsString('php8.4-fpm', (new InstallPHPScript)->script(1, $server));
        $this->assertStringNotContainsString('--force-yes', (new UpdateDependenciesScript)->script(1, $server));
        $this->assertStringContainsString('/etc/buildpusher/managed', (new EndScript)->script(1, $server));
        $this->assertStringContainsString('/etc/buildpusher/provisioning-manifest', (new EndScript)->script(1, $server));
    }

    public function test_website_database_user_is_local_only(): void
    {
        $server = $this->server();
        $website = $server->websites()->create(['user_id' => $server->user_id, 'organization_id' => $server->organization_id,
            'name' => 'site', 'description' => 'Test site', 'environment' => 'APP_ENV=test', 'url' => 'site.example.test',
            'deployment_slug' => 'site-test', 'database_password' => 'database-secret']);
        $script = (new CreateMysqlDatabase)->script(1, $website);
        // The isolated callback URL may also contain localhost; count SQL host literals only.
        $this->assertSame(3, substr_count($script, "'localhost'"));
        $this->assertStringNotContainsString("@'%'", $script);
    }

    public function test_every_server_role_renders_as_valid_strict_bash(): void
    {
        foreach (ServerTypeEnum::cases() as $type) {
            $server = $this->server();
            $server->type = $type;
            $script = app(ProvisioningScriptRenderer::class)->server(
                $server,
                app(ServerProvisioningPlan::class)->scripts($server),
            );
            $syntax = new Process(['bash', '-n']);
            $syntax->setInput($script);
            $syntax->run();

            $this->assertTrue($syntax->isSuccessful(), "{$type->value}: ".$syntax->getErrorOutput());
            $this->assertStringContainsString('set -Eeuo pipefail', $script);
            $this->assertStringContainsString('backupManagedFile()', $script);
            $this->assertStringNotContainsString('yes | sudo apt', $script);
            $this->assertStringNotContainsString('sudo apt install', $script);
            $this->assertStringContainsString('DEBIAN_FRONTEND=noninteractive', $script);
        }
    }

    private function server(): Server
    {
        $user = User::factory()->create();
        $server = $user->servers()->create(['name' => 'managed', 'type' => ServerTypeEnum::app, 'public_ip' => '1.1.1.1',
            'ssh_public_key' => 'ssh-ed25519 AAAATEST', 'mysql_root_password' => 'mysql-secret']);
        $server->setProvisioningRootPassword('temporary-secret');

        return $server;
    }
}
