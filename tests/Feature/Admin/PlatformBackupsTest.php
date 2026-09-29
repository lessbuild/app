<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\PlatformBackup;
use App\Models\User;
use App\Notifications\PlatformBackupFailed;
use App\Services\Admin\PlatformBackups;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PDO;
use RuntimeException;
use Tests\TestCase;

final class PlatformBackupsTest extends TestCase
{
    // Snapshots can't run inside the transaction RefreshDatabase wraps each test in.
    use DatabaseMigrations;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::getDriverName() !== 'sqlite') {
            // PostgreSQL backups shell out to pg_dump, which must match the server's version; CI's doesn't.
            $this->markTestSkipped('These tests snapshot the SQLite database; the SQLite run covers them.');
        }
        $this->directory = sys_get_temp_dir().'/platform-backups-'.uniqid();
        config(['platform.backups.path' => $this->directory, 'platform.backups.keep_local' => 2, 'platform.backups.s3.endpoint' => null]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);
        parent::tearDown();
    }

    public function test_a_backup_is_a_compressed_consistent_copy_with_a_checksum(): void
    {
        $this->assertSame(0, \Illuminate\Support\Facades\Artisan::call('platform:backup'));

        $backup = PlatformBackup::query()->sole();
        $path = "{$this->directory}/{$backup->file}";
        $this->assertTrue($backup->succeeded());
        $this->assertStringStartsWith('SQLite format 3', (string) gzdecode((string) file_get_contents($path)));
        $this->assertSame(hash_file('sha256', $path), $backup->sha256);
        $this->assertSame([true, false], [$backup->isLocal(), $backup->isOffsite()]);
        $this->assertSame('0700', substr(sprintf('%o', fileperms($this->directory)), -4));
    }

    public function test_backups_are_copied_off_site_and_old_copies_pruned(): void
    {
        config(['platform.backups.s3' => ['endpoint' => 'https://s3.example.net', 'region' => 'auto', 'bucket' => 'ops', 'key' => 'AKIA', 'secret' => 'shh', 'prefix' => 'bp'], 'platform.backups.keep_remote_days' => 30]);
        Http::fake(['https://s3.example.net/*' => Http::response('', 200)]);
        $backups = app(PlatformBackups::class);

        $old = $backups->create();
        $old->forceFill(['created_at' => now()->subDays(40)])->save();
        $backups->create();
        $latest = $backups->create();

        $this->assertTrue($latest->isOffsite());
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT' && $request->url() === "https://s3.example.net/ops/bp/{$latest->file}" && str_starts_with($request->header('Authorization')[0], 'AWS4-HMAC-SHA256 Credential=AKIA/'));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE' && str_ends_with($request->url(), $old->file));
        $this->assertSame([false, false], [$old->refresh()->isLocal(), $old->isOffsite()]);
        $this->assertFileDoesNotExist("{$this->directory}/{$old->file}");
        $this->assertTrue($latest->refresh()->isLocal());
    }

    public function test_a_failed_upload_keeps_the_local_copy_and_tells_the_admins(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['is_platform_admin' => true]);
        config(['platform.backups.s3' => ['endpoint' => 'https://s3.example.net', 'region' => 'auto', 'bucket' => 'ops', 'key' => 'AKIA', 'secret' => 'shh', 'prefix' => 'bp']]);
        Http::fake(['https://s3.example.net/*' => Http::response('<Error><Code>AccessDenied</Code></Error>', 403)]);

        $backup = app(PlatformBackups::class)->create();

        $this->assertSame([true, true, false], [$backup->succeeded(), $backup->isLocal(), $backup->isOffsite()]);
        $this->assertSame('The upload request failed (HTTP 403, AccessDenied).', $backup->error);
        Notification::assertSentTo($admin, PlatformBackupFailed::class);
    }

    public function test_a_sqlite_backup_restores_after_its_checks_and_keeps_the_old_database(): void
    {
        // A small file database stands in for production; the backup record stays in the test database.
        $database = "{$this->directory}/live.sqlite";
        File::ensureDirectoryExists($this->directory);
        $seed = fn (string $path, string $value) => (new PDO("sqlite:{$path}"))->exec("create table notes (body text); insert into notes values ('{$value}')");
        $seed($database, 'current');
        $snapshot = "{$this->directory}/snapshot.sqlite";
        $seed($snapshot, 'from the backup');
        file_put_contents("{$this->directory}/platform-restore.sqlite.gz", (string) gzencode((string) file_get_contents($snapshot)));
        $backup = new PlatformBackup;
        $backup->forceFill(['file' => 'platform-restore.sqlite.gz', 'driver' => 'sqlite', 'status' => 'succeeded', 'sha256' => hash_file('sha256', "{$this->directory}/platform-restore.sqlite.gz"), 'size' => 1])->save();
        $tampered = $backup->replicate()->forceFill(['file' => 'platform-other.sqlite.gz', 'sha256' => str_repeat('0', 64)]);
        $tampered->save();
        copy("{$this->directory}/platform-restore.sqlite.gz", "{$this->directory}/platform-other.sqlite.gz");

        config(['database.connections.live' => ['driver' => 'sqlite', 'database' => $database, 'prefix' => '', 'foreign_key_constraints' => false], 'database.default' => 'live']);
        try {
            try {
                app(PlatformBackups::class)->restore($tampered);
                $this->fail('A backup that fails its checksum must not be restored.');
            } catch (RuntimeException $exception) {
                $this->assertSame('The backup file doesn’t match its checksum.', $exception->getMessage());
            }
            $before = app(PlatformBackups::class)->restore($backup);
            $this->assertSame('from the backup', DB::connection('live')->table('notes')->value('body'));
            $rows = (new PDO("sqlite:{$before}"))->query('select body from notes');
            $this->assertSame('current', $rows === false ? null : $rows->fetchColumn());
        } finally {
            DB::purge('live');
            config(['database.default' => 'sqlite']);
        }
    }

    public function test_admins_see_backups_and_can_back_up_now(): void
    {
        $admin = User::factory()->create();
        Account::factory()->withMember($admin)->create();
        $admin->forceFill(['is_platform_admin' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
        $as = fn () => $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);

        $as()->get('/admin/backups')->assertOk()->assertSee('No backup yet.')->assertSee('PLATFORM_BACKUP_S3_ENDPOINT');
        $as()->get('/admin/health')->assertOk()->assertSee('Database backups')->assertSee('No backup yet');
        $as()->post('/admin/backups')->assertRedirect('/admin/backups')->assertSessionHas('status', 'Backed up on this server.');
        $as()->get('/admin/backups')->assertSee(PlatformBackup::query()->sole()->file);
        $as()->get('/admin/health')->assertSee('kept on this server only');
        $this->actingAs(User::factory()->create())->post('/admin/backups')->assertNotFound();
    }
}
