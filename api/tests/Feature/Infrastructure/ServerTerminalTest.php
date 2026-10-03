<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure;

use App\Enums\AccountRole;
use App\Jobs\Infrastructure\RunServerTerminal;
use App\Models\AuditEntry;
use App\Models\Project;
use App\Models\Provider;
use App\Models\Server;
use App\Models\ServerTerminalFrame;
use App\Models\ServerTerminalSession;
use App\Models\User;
use App\Services\Infrastructure\ServerTerminal;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Monitoring\MonitoringHelpers;
use Tests\TestCase;

final class ServerTerminalTest extends TestCase
{
    use InfrastructureHelpers;
    use MonitoringHelpers;
    use RefreshDatabase;

    private Project $project;

    private User $owner;

    private Server $server;

    private FakeServerTerminal $terminals;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakeInfrastructure();
        $this->terminals = new FakeServerTerminal;
        $this->app->instance(ServerTerminal::class, $this->terminals);
        config(['infrastructure.terminal.poll_milliseconds' => 0]);
        Queue::fake();
        $this->project = Project::factory()->withServices(['infrastructure'])->create();
        $this->owner = $this->ownerOf($this->project);
        $this->server = Server::factory()->create(['provider_id' => Provider::factory()->create(['account_id' => $this->project->account_id])->id]);
        $this->base = "/projects/{$this->project->id}/infrastructure/servers/{$this->server->id}";
        $this->withoutMiddleware(RequirePassword::class);
    }

    public function test_a_terminal_relays_keystrokes_and_output_until_the_shell_exits(): void
    {
        $this->actingAs($this->owner)->get($this->base)->assertSee('Open terminal');
        $response = $this->actingAs($this->owner)->post("{$this->base}/terminal", ['columns' => 100, 'rows' => 30]);
        $terminal = ServerTerminalSession::query()->sole();
        $response->assertRedirect("{$this->base}/terminal/{$terminal->id}")->assertSessionHas("terminals.{$terminal->id}");
        Queue::assertPushedOn('terminals', RunServerTerminal::class);
        $this->assertSame(['connecting', 100, 30], [$terminal->status, $terminal->columns, $terminal->rows]);
        $this->assertSame(\App\Enums\AuditAction::ServerTerminalOpened, AuditEntry::query()->latest('id')->firstOrFail()->action);
        $this->get("{$this->base}/terminal/{$terminal->id}")->assertOk()->assertSee('data-terminal', false);

        // Keystrokes typed while it connects wait for the shell.
        $this->postJson("{$this->base}/terminal/{$terminal->id}/input", ['input' => "uptime\r"])->assertStatus(202);
        $this->postJson("{$this->base}/terminal/{$terminal->id}/input", ['input' => "\r"])->assertStatus(202);
        $this->postJson("{$this->base}/terminal/{$terminal->id}/input", ['input' => "exit\r"])->assertStatus(202);
        $this->assertSame("uptime\r", ServerTerminalFrame::query()->where('direction', 'in')->orderBy('sequence')->firstOrFail()->payload);
        $this->assertStringNotContainsString('uptime', (string) \Illuminate\Support\Facades\DB::table('server_terminal_frames')->value('payload'));

        app()->call([new RunServerTerminal($terminal->id), 'handle']);
        $this->assertSame([100, 30], [$this->terminals->size?->columns, $this->terminals->size?->rows]);
        $this->assertSame("uptime\r\rexit\r", implode('', $this->terminals->written));
        $this->assertTrue($this->terminals->closed);
        $this->assertSame(['closed', 'shell exited'], [$this->reload($terminal)->status, $this->reload($terminal)->close_reason]);

        $output = $this->getJson("{$this->base}/terminal/{$terminal->id}/output?after=0")->assertOk()->json('data');
        $this->assertSame('closed', $output['status']);
        $this->assertStringContainsString("ran: uptime\r\n", implode('', array_column($output['frames'], 'data')));
        $last = end($output['frames'])['sequence'];
        $this->getJson("{$this->base}/terminal/{$terminal->id}/output?after={$last}")->assertJsonPath('data.frames', []);
        $this->assertSame(0, ServerTerminalFrame::query()->count());
        $this->postJson("{$this->base}/terminal/{$terminal->id}/input", ['input' => 'ls'])->assertStatus(409);
    }

    public function test_only_the_opener_in_the_same_browser_can_use_it_and_closing_stops_the_broker(): void
    {
        $this->actingAs($this->owner)->post("{$this->base}/terminal");
        $terminal = ServerTerminalSession::query()->sole();

        $admin = User::factory()->create();
        $this->addMember($this->project, $admin, AccountRole::Admin);
        $this->actingAs($admin)->getJson("{$this->base}/terminal/{$terminal->id}/output")->assertForbidden();
        $this->actingAs($admin)->postJson("{$this->base}/terminal/{$terminal->id}/input", ['input' => 'id'])->assertForbidden();

        // The same person without the token (another browser) can't type into it.
        $this->flushSession();
        $this->actingAs($this->owner)->postJson("{$this->base}/terminal/{$terminal->id}/input", ['input' => 'id'])->assertStatus(409);
        $this->actingAs($this->owner)->get("{$this->base}/terminal/{$terminal->id}")->assertSee('opened in another browser');

        $this->actingAs($this->owner)->delete("{$this->base}/terminal/{$terminal->id}")->assertRedirect($this->base);
        $this->assertSame('closed', $this->reload($terminal)->status);
        app()->call([new RunServerTerminal($terminal->id), 'handle']);
        $this->assertNull($this->terminals->size);

        $foreign = Server::factory()->create();
        $this->actingAs($this->owner)->get("/projects/{$this->project->id}/infrastructure/servers/{$foreign->id}/terminal/{$terminal->id}")->assertNotFound();
    }

    public function test_terminals_need_admin_rights_a_pinned_host_key_and_a_worker(): void
    {
        $member = User::factory()->create();
        $this->addMember($this->project, $member, AccountRole::Member);
        $this->actingAs($member)->get($this->base)->assertDontSee('Open terminal');
        $this->actingAs($member)->post("{$this->base}/terminal")->assertForbidden();

        $this->server->forceFill(['ssh_host_key' => null])->save();
        $this->actingAs($this->owner)->post("{$this->base}/terminal")->assertSessionHasErrors('terminal');
        $this->server->forceFill(['ssh_host_key' => 'ssh-ed25519 AAAA'])->save();

        $this->terminals->refuse = true;
        $this->actingAs($this->owner)->post("{$this->base}/terminal");
        $refused = ServerTerminalSession::query()->sole();
        app()->call([new RunServerTerminal($refused->id), 'handle']);
        $this->assertSame(['failed', 'The SSH connection couldn’t be opened.'], [$this->reload($refused)->status, $this->reload($refused)->close_reason]);

        // Nothing picks up a terminal when no worker runs on the terminals queue; idle ones expire.
        $this->actingAs($this->owner)->post("{$this->base}/terminal");
        $waiting = ServerTerminalSession::query()->where('status', 'connecting')->sole();
        $this->travel(3)->minutes();
        $this->command('terminals:expire')->assertSuccessful();
        $this->assertSame(['failed', 'no terminal worker is running'], [$this->reload($waiting)->status, $this->reload($waiting)->close_reason]);

        $this->actingAs($this->owner)->post("{$this->base}/terminal");
        $idle = ServerTerminalSession::query()->where('status', 'connecting')->sole();
        $idle->forceFill(['status' => 'connected', 'broker_seen_at' => now()->addMinutes(11)])->save();
        $this->travel(11)->minutes();
        $this->command('terminals:expire')->assertSuccessful();
        $this->assertSame('expired', $this->reload($idle)->status);
    }
}
