<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Pages\Queues;
use App\Jobs\Deploy\ReportPreviewToGitHub;
use App\Models\PlatformAdminEvent;
use App\Services\Admin\SystemHealth;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class AdminOperationsTest extends TestCase
{
    use AdminHelpers;
    use RefreshDatabase;

    public function test_health_shows_checks_queues_and_the_scheduler_heartbeat(): void
    {
        $admin = $this->admin();
        DB::table('jobs')->insert(['queue' => 'checks', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->subMinutes(20)->getTimestamp(), 'created_at' => now()->subMinutes(20)->getTimestamp()]);

        $page = $this->as($admin)->get('/admin/health')->assertOk();
        $this->assertStringContainsString('no-store', (string) $page->headers->get('Cache-Control'));
        $page->assertSee('Database connection')->assertSee('Current')->assertSee('No heartbeat yet')->assertSee('Backed up');

        Artisan::call('platform:heartbeat');
        $this->assertIsInt(Cache::get(SystemHealth::HEARTBEAT_KEY));
        $report = $this->as($admin)->get('/admin/health/report.json')->assertOk();
        $this->assertSame('degraded', $report->json('status'));
        $this->assertTrue($report->collect('checks')->firstWhere('name', 'Scheduler')['passed']);
        $this->assertSame(['queue' => 'checks', 'pending' => 1, 'reserved' => 0, 'oldest_minutes' => 20, 'healthy' => false], $report->collect('queues')->firstWhere('queue', 'checks'));
        $this->assertStringContainsString('attachment; filename="platform-health-', (string) $report->headers->get('Content-Disposition'));
    }

    public function test_failed_jobs_can_be_retried_or_deleted_and_the_trail_records_it(): void
    {
        $admin = $this->admin();
        [$first, $second] = [$this->failedJob('Ping monitor'), $this->failedJob('Send alert')];

        $this->as($admin)->get('/admin/queues')->assertOk()->assertSee('Ping monitor')->assertSee('RuntimeException: Connection refused')->assertDontSee('/var/www');
        Livewire::test(Queues::class)->assertCanSeeTableRecords([$first, $second])->callAction(TestAction::make('retry')->table($first))->assertNotified();
        $this->assertSame(1, DB::table('failed_jobs')->count());
        $this->assertSame(1, DB::table('jobs')->where('queue', 'checks')->count());
        Livewire::test(Queues::class)->callAction(TestAction::make('forget')->table($second));
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->failedJob('Another');
        Livewire::test(Queues::class)->callAction('forgetAll');
        $this->assertSame(0, DB::table('failed_jobs')->count());

        $this->assertSame(['jobs.retried', 'jobs.forgotten', 'jobs.forgotten'], PlatformAdminEvent::query()->orderBy('id')->pluck('action')->all());
        $this->as($admin)->get('/admin')->assertOk()->assertSee("Retried failed job {$first}.");
    }

    /**
     * Record a failed job on the checks queue and return its UUID.
     *
     * @param  string  $name
     * @return string
     */
    private function failedJob(string $name): string
    {
        $uuid = (string) Str::uuid();
        DB::table('failed_jobs')->insert([
            'uuid' => $uuid, 'connection' => 'database', 'queue' => 'checks', 'failed_at' => now(),
            'payload' => json_encode(['uuid' => $uuid, 'displayName' => $name, 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 1, 'data' => ['commandName' => ReportPreviewToGitHub::class, 'command' => serialize(new ReportPreviewToGitHub(1))]], JSON_THROW_ON_ERROR),
            'exception' => "RuntimeException: Connection refused\n#0 /var/www/app/Jobs/Ping.php(12)",
        ]);

        return $uuid;
    }
}
