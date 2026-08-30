<?php

namespace Tests\Feature;

use App\Actions\Server\PrepareServerProvisioningAction;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Scripts\Database\InstallMysqlScript;
use App\Scripts\Server\ConfigureServerScript;
use App\Scripts\Web\InstallCaddyScript;
use App\Services\ServerProvisioningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class ProvisioningCredentialTest extends TestCase
{
    use RefreshDatabase;

    public function test_prepared_credentials_make_script_rendering_deterministic_and_side_effect_free(): void
    {
        $server = $this->server(ServerTypeEnum::app);
        $prepare = new PrepareServerProvisioningAction(new ServerProvisioningPlan);
        $prepare->handle($server);
        $rootPassword = $server->provisioningRootPassword();
        $mysqlPassword = $server->mysql_root_password;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $firstRootScript = (new ConfigureServerScript)->script(3, $server);
        $firstMysqlScript = (new InstallMysqlScript)->script(8, $server);
        $secondRootScript = (new ConfigureServerScript)->script(3, $server);
        $secondMysqlScript = (new InstallMysqlScript)->script(8, $server);

        $this->assertSame($firstRootScript, $secondRootScript);
        $this->assertSame($firstMysqlScript, $secondMysqlScript);
        $this->assertSame($rootPassword, $server->provisioningRootPassword());
        $this->assertSame($mysqlPassword, $server->fresh()->mysql_root_password);
        $this->assertFalse(collect(DB::getQueryLog())->contains(
            fn (array $query): bool => str_starts_with(strtolower($query['query']), 'update '),
        ));
    }

    public function test_credentials_are_flashed_once_and_mysql_is_only_created_when_required(): void
    {
        $webServer = $this->server(ServerTypeEnum::web);
        $prepare = new PrepareServerProvisioningAction(new ServerProvisioningPlan);
        $prepare->handle($webServer);

        $this->assertNotNull($webServer->provisioningRootPassword());
        $this->assertNull($webServer->fresh()->mysql_root_password);
        $this->assertContains('root_password', session('_flash.new'));
        $this->assertNotContains('mysql_password', session('_flash.new'));

        $databaseServer = $this->server(ServerTypeEnum::database);
        $prepare->handle($databaseServer);

        $this->assertNotNull($databaseServer->mysql_root_password);
        $this->assertContains('mysql_password', session('_flash.new'));
    }

    public function test_scripts_fail_closed_when_credentials_were_not_prepared(): void
    {
        $server = $this->server(ServerTypeEnum::app);

        try {
            (new ConfigureServerScript)->script(3, $server);
            $this->fail('Root credential rendering should fail before preparation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Server provisioning credentials have not been prepared.', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('MySQL provisioning credentials have not been prepared.');
        (new InstallMysqlScript)->script(8, $server);
    }

    public function test_remote_steps_are_safe_to_resume_after_partial_execution(): void
    {
        $server = $this->server(ServerTypeEnum::app);
        $server->update(['public_ip' => '192.0.2.10']);
        (new PrepareServerProvisioningAction(new ServerProvisioningPlan))->handle($server);

        $configure = (new ConfigureServerScript)->script(3, $server);
        $mysql = (new InstallMysqlScript)->script(8, $server);
        $caddy = (new InstallCaddyScript)->script(7, $server);

        $this->assertStringContainsString('if [ ! -f "/home/$SERVER_NAME/.ssh/id_rsa" ]', $configure);
        $this->assertStringContainsString('CREATE USER IF NOT EXISTS', $mysql);
        $this->assertStringContainsString('cat > /etc/mysql/mysql.conf.d/99-lessbuild.cnf', $mysql);
        $this->assertStringNotContainsString('>> /etc/mysql/my.cnf', $mysql);
        $this->assertStringContainsString('gpg --batch --yes --dearmor', $caddy);
        $this->assertStringContainsString('mkdir -p /etc/caddy/websites', $caddy);
    }

    public function test_legacy_server_passwords_are_encrypted_at_rest(): void
    {
        $server = $this->server(ServerTypeEnum::app);
        $server->update(['password' => 'legacy-root-secret']);
        $migration = require database_path('migrations/2026_08_30_120000_encrypt_legacy_server_passwords.php');

        $migration->down();
        $this->assertSame(
            'legacy-root-secret',
            Server::query()->toBase()->find($server->id)->password,
        );

        $migration->up();

        $this->assertSame('legacy-root-secret', $server->fresh()->password);
        $this->assertNotSame(
            'legacy-root-secret',
            Server::query()->toBase()->find($server->id)->password,
        );
        $this->assertArrayNotHasKey('password', $server->fresh()->toArray());
    }

    private function server(ServerTypeEnum $type): Server
    {
        $user = User::factory()->create(['name' => 'Provisioning User']);

        return $user->servers()->create([
            'name' => 'provisioning-server',
            'type' => $type,
            'ssh_public_key' => 'ssh-ed25519 provisioning-key',
            'provisioning_status' => Server::STATUS_QUEUED,
        ]);
    }
}
