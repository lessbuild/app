<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Data\Security\Finding;
use App\Enums\SelectionKind;
use App\Jobs\Security\PatchServer;
use App\Models\BillingSelection;
use App\Models\Project;
use App\Models\SecurityFinding;
use App\Models\Server;
use App\Models\ServerFirewallRule;
use App\Models\Website;
use App\Services\Security\HardeningScripts;
use App\Services\Security\PatchSchedule;
use App\Services\Security\ServerAudit;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Infrastructure\InfrastructureHelpers;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServerHardeningTest extends TestCase
{
    use InfrastructureHelpers, MonitoringHelpers {
        MonitoringHelpers::ownerOf insteadof InfrastructureHelpers;
    }
    use RefreshDatabase;

    /**
     * What a neglected server reports.
     *
     * @var string
     */
    private const NEGLECTED = "PASSWORD_AUTH=yes\nROOT_LOGIN=yes\nUFW=inactive\nFAIL2BAN=inactive\nUNATTENDED=off\nUPDATES=31\nSECURITY_UPDATES=12\nREBOOT=yes\nUBUNTU=20.04\nPHP=8.5.10\nPHP=8.5.10\nPHP=7.4.33\nPHP_PPA=yes\nPHP_AUTO_UPDATES=off\nLISTEN=0.0.0.0:22\nLISTEN=0.0.0.0:3306\nLISTEN=127.0.0.1:6379\nLISTEN=[::]:8080\n";

    /**
     * What a hardened server reports.
     *
     * @var string
     */
    private const HARDENED = "PASSWORD_AUTH=no\nROOT_LOGIN=without-password\nUFW=active\nUFW_ALLOW=22/tcp\nUFW_ALLOW=80/tcp\nUFW_ALLOW=443/tcp\nFAIL2BAN=active\nUNATTENDED=on\nUPDATES=0\nSECURITY_UPDATES=0\nREBOOT=no\nUBUNTU=24.04\nPHP=8.5.12\nPHP=8.3.36\nPHP_PPA=yes\nPHP_AUTO_UPDATES=on\nLISTEN=0.0.0.0:22\nLISTEN=0.0.0.0:3306\n";

    /**
     * Check a server's audit, a one-click fix, the rescan clearing what was fixed, and update windows.
     *
     * @return void
     */
    public function test_servers_are_audited_fixed_and_patched(): void
    {
        $this->fakeInfrastructure();
        $project = Project::factory()->withServices(['security', 'infrastructure'])->create();
        (new BillingSelection)->forceFill(['account_id' => $project->account_id, 'service' => 'security', 'kind' => SelectionKind::Tier, 'item_key' => 'pro'])->save();
        $owner = $this->ownerOf($project);
        $server = Server::factory()->create(['account_id' => $project->account_id, 'name' => 'web-1']);
        Website::factory()->create(['account_id' => $project->account_id, 'environment_id' => $project->environments()->firstOrFail()->id, 'server_id' => $server->id]);
        (new ServerFirewallRule)->forceFill(['server_id' => $server->id, 'name' => 'Office', 'port' => '5432', 'protocol' => 'tcp', 'source' => '198.51.100.0/24', 'status' => 'active'])->save();
        $page = "/api/app/projects/{$project->id}/security";

        $this->shell->reply(self::NEGLECTED);
        $this->actingAs($owner)->postJson("{$page}/scans", ['kind' => 'servers'])->assertSuccessful();
        $this->assertStringContainsString('sshd -T', $this->shell->ran[0]['command']);
        $titles = SecurityFinding::query()->where('source', 'servers')->pluck('severity', 'title')->all();
        $this->assertSame('high', $titles['web-1 accepts SSH passwords']);
        $this->assertSame('high', $titles['web-1 lets root sign in with a password']);
        $this->assertSame('high', $titles['web-1’s firewall is off']);
        $this->assertSame('critical', $titles['MySQL on web-1 can be reached from the internet']);
        $this->assertSame('low', $titles['Port 8080 on web-1 is open to the internet']);
        $this->assertSame('high', $titles['web-1 has 12 security updates waiting']);
        $this->assertSame('high', $titles['web-1 runs Ubuntu 20.04, which is at or near the end of its support']);
        $this->assertSame('critical', $titles['web-1 runs PHP 8.5.10, which has known security holes']);
        $this->assertSame('critical', $titles['web-1 runs PHP 7.4.33, which no longer gets security fixes']);
        $this->assertSame(1, SecurityFinding::query()->where('title', 'web-1 runs PHP 8.5.10, which has known security holes')->count(), 'One finding per PHP version.');
        $this->assertArrayNotHasKey('web-1’s automatic updates skip PHP', $titles, 'Automatic updates are off altogether, which is its own finding.');
        $this->assertArrayNotHasKey('Redis on web-1 can be reached from the internet', $titles, 'Listening on 127.0.0.1 is fine.');

        $firewall = SecurityFinding::query()->where('title', 'web-1’s firewall is off')->sole();
        $this->actingAs($owner)->getJson("{$page}/findings?source=servers")->assertOk()->assertJsonFragment(['id' => $firewall->id, 'fixAction' => 'enable-firewall', 'fixLabel' => __('Turn the firewall on')]);
        $this->shell->reply('ok')->reply(self::HARDENED);
        $this->actingAs($owner)->postJson("{$page}/findings/{$firewall->id}/fix")->assertSuccessful();
        $fix = $this->shell->ran[1]['command'];
        $this->assertStringContainsString('ufw allow 22/tcp', $fix);
        $this->assertLessThan(strpos($fix, 'ufw --force enable'), strpos($fix, 'ufw allow 22/tcp'), 'SSH is allowed before the firewall turns on.');
        $this->assertStringContainsString('198.51.100.0/24', $fix, 'The server’s own rules are kept.');
        $this->assertSame('resolved', $firewall->refresh()->status, 'The server was audited again and the finding cleared.');
        $this->assertSame(0, SecurityFinding::query()->where('source', 'servers')->where('status', 'open')->count(), 'Behind the firewall, MySQL on 0.0.0.0 is no longer exposed.');

        $php = SecurityFinding::query()->where('title', 'web-1 runs PHP 8.5.10, which has known security holes')->sole();
        $this->assertSame('resolved', $php->status, 'PHP 8.5.12 and 8.3.36 are safe.');

        $script = app(HardeningScripts::class)->script('ssh-keys-only', $server);
        $this->assertStringContainsString('if sshd -t', $script, 'Bad SSH settings are undone, never reloaded.');
        $this->assertStringContainsString('prohibit-password', app(HardeningScripts::class)->script('root-keys-only', $server), 'Root keeps key access, which BuildPusher uses.');

        $updates = app(HardeningScripts::class)->script('apply-updates', $server);
        $this->assertStringContainsString('origin=LP-PPA-ondrej-php', $updates, 'Automatic updates include PHP from then on.');
        $this->assertStringContainsString('--only-upgrade install $php_packages', $updates, 'PHP is updated even where unattended-upgrades skips it.');
        $this->assertStringContainsString("systemctl try-restart 'php*-fpm.service'", $updates, 'PHP-FPM restarts on the new version.');
        $this->assertStringContainsString('supervisorctl restart all', $updates, 'Queue workers restart on the new version.');
        $this->assertStringContainsString('rm -f -- "$dir/20-redis.ini"', $updates, 'The duplicate redis module from older setups is removed.');
        $this->assertStringContainsString('origin=LP-PPA-ondrej-php', app(HardeningScripts::class)->script('enable-auto-updates', $server));

        Queue::fake();
        $this->actingAs($owner)->getJson("{$page}/servers")->assertOk()->assertJsonPath('servers.0.name', 'web-1')->assertJsonPath('servers.0.patchDay', null);
        $this->actingAs($owner)->putJson("{$page}/servers/{$server->id}/patch", ['patch_day' => 2, 'patch_hour' => 4, 'patch_reboot' => '1'])->assertJsonRedirect("{$page}/servers");
        $this->assertSame([2, 4, true], [$server->refresh()->patch_day, $server->patch_hour, $server->patch_reboot]);
        $this->actingAs($owner)->putJson("{$page}/servers/{$server->id}/patch", ['now' => '1'])->assertSuccessful();
        Queue::assertPushed(PatchServer::class, fn (PatchServer $job): bool => $job->serverId === $server->id && ! $job->reboot);

        $this->travelTo(CarbonImmutable::parse('next tuesday 04:10', 'UTC'));
        $this->assertSame(1, app(PatchSchedule::class)->runDue());
        Queue::assertPushed(PatchServer::class, fn (PatchServer $job): bool => $job->reboot);
        $this->travelTo(CarbonImmutable::parse('next tuesday 05:10', 'UTC'));
        $this->assertSame(0, app(PatchSchedule::class)->runDue(), 'Outside the window.');

        $other = Server::factory()->create(['account_id' => $project->account_id]);
        $this->actingAs($owner)->putJson("{$page}/servers/{$other->id}/patch", ['patch_day' => 1])->assertJsonValidationErrors('patch_day');
    }

    /**
     * A server whose automatic updates are on but leave out PHP's source is told so, with the fix that includes it.
     *
     * @return void
     */
    public function test_automatic_updates_that_skip_php_are_a_finding(): void
    {
        $server = Server::factory()->make(['name' => 'web-2']);
        $base = "UNATTENDED=on\nPHP=8.5.12\n";

        $php = array_values(array_filter((new ServerAudit)->findings($server, $base."PHP_PPA=yes\nPHP_AUTO_UPDATES=off\n"), fn (Finding $finding): bool => str_starts_with($finding->key, 'php')));
        $this->assertCount(1, $php);
        $this->assertSame('php-auto-updates', $php[0]->key);
        $this->assertSame('enable-auto-updates', $php[0]->data['fix_action']);

        $keys = fn (string $output): array => array_map(fn (Finding $finding): string => $finding->key, (new ServerAudit)->findings($server, $output));
        $this->assertNotContains('php-auto-updates', $keys($base."PHP_PPA=yes\nPHP_AUTO_UPDATES=on\n"));
        $this->assertNotContains('php-auto-updates', $keys($base."PHP_PPA=no\nPHP_AUTO_UPDATES=off\n"), 'PHP from elsewhere is updated by Ubuntu’s own updates.');
    }
}
