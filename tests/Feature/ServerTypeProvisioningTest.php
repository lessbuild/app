<?php

namespace Tests\Feature;

use App\Actions\Server\CreateCloudServerAction;
use App\Actions\Server\PrepareServerProvisioningAction;
use App\Contracts\ServerProvider;
use App\Data\CloudServerData;
use App\Models\Enums\Server\ServerTypeEnum;
use App\Models\Server;
use App\Models\User;
use App\Scripts\Cache\InstallMemcachedScript;
use App\Scripts\Cache\InstallRedisScript;
use App\Scripts\Database\InstallMysqlScript;
use App\Scripts\Languages\InstallNodeScript;
use App\Scripts\Languages\InstallPHPScript;
use App\Scripts\Server\BaseScript;
use App\Scripts\Server\ConfigureServerScript;
use App\Scripts\Server\EndScript;
use App\Scripts\Server\InstallComposerScript;
use App\Scripts\Server\RecipesScript;
use App\Scripts\Web\InstallCaddyScript;
use App\Services\ServerProvisioningPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Mockery;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ServerTypeProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_server_type_has_the_expected_specialized_steps(): void
    {
        $plan = new ServerProvisioningPlan;

        $this->assertSpecializedSteps($plan, ServerTypeEnum::app, [
            InstallComposerScript::class,
            InstallPHPScript::class,
            InstallNodeScript::class,
            InstallCaddyScript::class,
            InstallMysqlScript::class,
            InstallRedisScript::class,
            InstallMemcachedScript::class,
        ]);
        $this->assertSpecializedSteps($plan, ServerTypeEnum::web, [
            InstallComposerScript::class,
            InstallPHPScript::class,
            InstallCaddyScript::class,
        ]);
        $this->assertSpecializedSteps($plan, ServerTypeEnum::worker, [
            InstallComposerScript::class,
            InstallPHPScript::class,
            InstallNodeScript::class,
        ]);
        $this->assertSpecializedSteps($plan, ServerTypeEnum::database, [InstallMysqlScript::class]);
        $this->assertSpecializedSteps($plan, ServerTypeEnum::cache, [
            InstallRedisScript::class,
            InstallMemcachedScript::class,
        ]);
        $this->assertSpecializedSteps($plan, ServerTypeEnum::loadbalancer, [InstallCaddyScript::class]);
    }

    public function test_cloud_init_only_contains_scripts_for_the_selected_type(): void
    {
        $server = User::factory()->create()->servers()->create([
            'name' => 'cache-server',
            'type' => ServerTypeEnum::cache,
            'ssh_public_key' => 'ssh-rsa test-key',
        ]);
        $provider = Mockery::mock(ServerProvider::class);
        $payload = null;
        $provider->shouldReceive('createServer')->once()->andReturnUsing(
            function (array $request) use (&$payload): CloudServerData {
                $payload = $request;

                return new CloudServerData(1, 'cache-server', 'nyc1', 'small', 'ubuntu');
            },
        );

        $plan = new ServerProvisioningPlan;
        (new CreateCloudServerAction($plan, new PrepareServerProvisioningAction($plan)))
            ->handle($server, $provider, ['name' => 'cache-server']);

        $script = $payload['user_data'];
        $this->assertStringContainsString('yes | sudo apt install redis-server', $script);
        $this->assertStringContainsString('yes | sudo apt install memcached', $script);
        $this->assertStringNotContainsString('apt install mysql-server', $script);
        $this->assertStringNotContainsString('apt install php8.1 php8.1-fpm', $script);
    }

    public function test_progress_ui_and_completion_callback_use_the_selected_type_plan(): void
    {
        $user = User::factory()->create();
        $provider = $user->providers()->create([
            'name' => 'DigitalOcean',
            'provider' => 'digitalocean',
            'token' => 'provider-secret',
            'description' => 'Cloud provider',
        ]);
        $server = $user->servers()->create([
            'name' => 'Cache Server',
            'type' => ServerTypeEnum::cache,
            'provider_id' => $provider->id,
            'provisioning_status' => Server::STATUS_PROVISIONING,
        ]);
        $finalStage = (new ServerProvisioningPlan)->finalStage($server);

        $this->assertSame(7, $finalStage);
        $this->actingAs($user)->get(route('servers.show', $server))
            ->assertSuccessful()
            ->assertSee('Install Redis')
            ->assertSee('Install Memcached')
            ->assertDontSee('Install PHP')
            ->assertDontSee('Install Mysql');

        $this->postJson(URL::signedRoute('callbacks.server', $server), ['status' => 12])
            ->assertUnprocessable();

        $this->post(URL::signedRoute('callbacks.server', $server), ['status' => $finalStage])
            ->assertSuccessful();
        $this->assertSame(Server::STATUS_ACTIVE, $server->fresh()->provisioning_status);

        $this->postJson(URL::signedRoute('callbacks.server', $server), ['status' => 1])
            ->assertNoContent();
    }

    public function test_server_configuration_uses_the_generated_key_and_model_owner(): void
    {
        $user = User::factory()->create([
            'name' => "Grace O'Connor",
            'email' => 'grace@example.com',
        ]);
        $server = $user->servers()->create([
            'name' => 'cache-server',
            'type' => ServerTypeEnum::cache,
            'ssh_public_key' => 'ssh-rsa generated-server-key',
        ]);
        $server->setProvisioningRootPassword('generated-root-password');

        $script = (new ConfigureServerScript)->script(3, $server);
        $syntaxCheck = new Process(['bash', '-n']);
        $syntaxCheck->setInput($script);
        $syntaxCheck->run();

        $this->assertTrue($syntaxCheck->isSuccessful(), $syntaxCheck->getErrorOutput());
        $this->assertStringContainsString("PUBLIC_KEY='ssh-rsa generated-server-key'", $script);
        $this->assertStringContainsString("git config --global user.name 'Grace O'\\''Connor'", $script);
        $this->assertStringNotContainsString('SSH_PUBLIC_KEY', $script);

        $baseScript = (new BaseScript)->script(0, $server);
        $this->assertStringContainsString('curl --fail --silent --show-error --retry 2', $baseScript);
        $this->assertStringNotContainsString('--insecure', $baseScript);
        $this->assertMatchesRegularExpression("#'http[^']+expires=[^']+&signature=[^']+'#", $baseScript);
    }

    private function assertSpecializedSteps(
        ServerProvisioningPlan $plan,
        ServerTypeEnum $type,
        array $expected,
    ): void {
        $scripts = $plan->scripts($type);
        $steps = $plan->steps($type);

        $this->assertSame(BaseScript::class, $scripts[0]);
        $this->assertSame(RecipesScript::class, $steps[array_key_last($steps) - 1]);
        $this->assertSame(EndScript::class, $steps[array_key_last($steps)]);

        foreach ([
            InstallComposerScript::class,
            InstallPHPScript::class,
            InstallNodeScript::class,
            InstallCaddyScript::class,
            InstallMysqlScript::class,
            InstallRedisScript::class,
            InstallMemcachedScript::class,
        ] as $specializedStep) {
            $this->assertSame(
                in_array($specializedStep, $expected, true),
                in_array($specializedStep, $steps, true),
                "Unexpected provisioning plan for {$type->value}: {$specializedStep}",
            );
        }
    }
}
