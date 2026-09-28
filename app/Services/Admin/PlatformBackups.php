<?php

declare(strict_types=1);

namespace App\Services\Admin;

use App\Models\PlatformBackup;
use App\Models\User;
use App\Notifications\PlatformBackupFailed;
use App\Services\Storage\S3Client;
use App\Services\Storage\S3Location;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PDO;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Backs up the platform's own database: a consistent snapshot (SQLite's VACUUM INTO, or pg_dump), compressed and
 * checksummed, kept locally for a few copies and uploaded to off-site S3-compatible storage when it's configured.
 * Failures are recorded and emailed to platform admins. Restoring checks the checksum and the database's integrity,
 * and keeps the current database beside the restored one.
 */
final class PlatformBackups
{
    /**
     * Create a new PlatformBackups instance.
     *
     * @param  S3Client  $s3  Uploads, downloads and deletes off-site copies.
     * @param  DatabaseManager  $db  The platform's (single) database connection.
     */
    public function __construct(private readonly S3Client $s3, private readonly DatabaseManager $db) {}

    /**
     * Take a backup now, copy it off-site when that's set up, and prune old copies. A failure is recorded (and admins
     * told) rather than thrown.
     *
     * @param  string  $trigger  schedule or manual
     * @return PlatformBackup
     */
    public function create(string $trigger = 'schedule'): PlatformBackup
    {
        $connection = $this->db->connection();
        $driver = $connection->getDriverName();
        $backup = new PlatformBackup;
        $backup->forceFill(['file' => 'platform-'.now('UTC')->format('Ymd-His-v').($driver === 'pgsql' ? '.dump' : '.sqlite.gz'), 'driver' => $driver, 'status' => 'running', 'trigger' => $trigger])->save();
        try {
            $path = $this->directory().'/'.$backup->file;
            match ($driver) {
                'sqlite' => $this->snapshotSqlite($connection, $path),
                'pgsql' => $this->dumpPostgres($connection, $path),
                default => throw new RuntimeException("Backing up {$driver} databases isn’t supported."),
            };
            $backup->forceFill(['status' => 'succeeded', 'size' => (int) filesize($path), 'sha256' => (string) hash_file('sha256', $path)])->save();
            if (($location = $this->offsite()) !== null) {
                $key = $this->remoteKey($backup->file);
                $this->s3->assertSuccessful('upload', $this->s3->request($location, 'PUT', $key, (string) file_get_contents($path), 600));
                $backup->forceFill(['remote_key' => $key, 'uploaded_at' => now()])->save();
            }
        } catch (Throwable $exception) {
            report($exception);
            $backup->forceFill(['status' => $backup->status === 'running' ? 'failed' : $backup->status, 'error' => Str::limit($exception->getMessage(), 2000)])->save();
            Notification::send(User::query()->where('is_platform_admin', true)->get(), new PlatformBackupFailed($backup));
        }
        $this->prune();

        return $backup;
    }

    /**
     * Replace the SQLite database with a backup: fetch it (from off-site storage when it's no longer local), check its
     * checksum and integrity, keep the current database beside it, and swap the restored file in.
     *
     * @param  PlatformBackup  $backup
     * @return string where the database as it was before the restore was kept
     *
     * @throws RuntimeException when the backup can't be used, or the database isn't a SQLite file
     */
    public function restore(PlatformBackup $backup): string
    {
        $database = (string) $this->db->connection()->getConfig('database');
        if ($this->db->connection()->getDriverName() !== 'sqlite' || $database === ':memory:' || ! is_file($database)) {
            throw new RuntimeException('Only a SQLite database file can be restored this way. Restore PostgreSQL dumps with pg_restore.');
        }
        if (! $backup->succeeded() || $backup->driver !== 'sqlite') {
            throw new RuntimeException('That backup didn’t finish, or isn’t a SQLite backup.');
        }
        $compressed = $this->fetch($backup);
        $restored = $database.'.restoring';
        $this->gunzip($compressed, $restored);
        $check = (new PDO('sqlite:'.$restored))->query('PRAGMA integrity_check');
        if ($check === false || $check->fetchColumn() !== 'ok') {
            @unlink($restored);
            throw new RuntimeException('The restored database failed its integrity check.');
        }
        $before = $database.'.before-restore-'.now('UTC')->format('Ymd-His');
        $this->db->disconnect();
        if (! copy($database, $before) || ! rename($restored, $database)) {
            throw new RuntimeException('Swapping the database file failed; the current database is unchanged.');
        }
        @unlink($database.'-wal');
        @unlink($database.'-shm');

        return $before;
    }

    /**
     * Determine whether off-site copies are set up.
     *
     * @return bool
     */
    public function offsiteConfigured(): bool
    {
        return $this->offsite() !== null;
    }

    /**
     * Get the most recent backup that finished.
     *
     * @return PlatformBackup|null
     */
    public function latestSuccessful(): ?PlatformBackup
    {
        return PlatformBackup::query()->where('status', 'succeeded')->latest('id')->first();
    }

    /**
     * Delete local copies beyond the newest few, and off-site copies older than the retention.
     *
     * @return void
     */
    public function prune(): void
    {
        $keep = max(1, (int) config('platform.backups.keep_local'));
        PlatformBackup::query()->where('status', 'succeeded')->whereNull('local_deleted_at')->latest('id')->skip($keep)->take(1000)->get()
            ->each(function (PlatformBackup $backup): void {
                @unlink($this->directory().'/'.$backup->file);
                $backup->forceFill(['local_deleted_at' => now()])->save();
            });
        $location = $this->offsite();
        if ($location === null) {
            return;
        }
        PlatformBackup::query()->whereNotNull('uploaded_at')->whereNull('remote_deleted_at')
            ->where('created_at', '<', now()->subDays(max(1, (int) config('platform.backups.keep_remote_days'))))
            ->where('id', '!=', $this->latestSuccessful()->id ?? 0)->get()
            ->each(function (PlatformBackup $backup) use ($location): void {
                try {
                    $this->s3->assertSuccessful('delete', $this->s3->request($location, 'DELETE', (string) $backup->remote_key));
                    $backup->forceFill(['remote_deleted_at' => now()])->save();
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }

    /**
     * Copy the SQLite database consistently, even while it's in use, then compress it. A database file is copied
     * through its own connection, so the snapshot never runs inside a transaction the app has open.
     *
     * @param  Connection  $connection
     * @param  string  $path
     * @return void
     */
    private function snapshotSqlite(Connection $connection, string $path): void
    {
        $snapshot = $path.'.tmp';
        @unlink($snapshot);
        $database = (string) $connection->getConfig('database');
        if ($database !== ':memory:' && is_file($database)) {
            $pdo = new PDO('sqlite:'.$database);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->prepare('VACUUM INTO ?')->execute([$snapshot]);
        } else {
            $connection->statement('VACUUM INTO ?', [$snapshot]);
        }
        try {
            $in = fopen($snapshot, 'rb');
            $out = gzopen($path, 'wb6');
            if ($in === false || $out === false) {
                throw new RuntimeException('The backup file couldn’t be written.');
            }
            while (! feof($in)) {
                gzwrite($out, (string) fread($in, 1 << 20));
            }
            fclose($in);
            gzclose($out);
        } finally {
            @unlink($snapshot);
        }
    }

    /**
     * Dump the PostgreSQL database in pg_dump's custom (already compressed) format.
     *
     * @param  Connection  $connection
     * @param  string  $path
     * @return void
     */
    private function dumpPostgres(Connection $connection, string $path): void
    {
        $config = fn (string $key): string => (string) $connection->getConfig($key);
        $process = new Process(['pg_dump', '--format=custom', '--no-owner', '--file='.$path, $config('database')], null, [
            'PGHOST' => $config('host'), 'PGPORT' => $config('port'), 'PGUSER' => $config('username'), 'PGPASSWORD' => $config('password'),
        ], null, 3600);
        $process->run();
        if (! $process->isSuccessful()) {
            throw new RuntimeException('pg_dump failed: '.Str::limit(trim($process->getErrorOutput()), 500));
        }
    }

    /**
     * Get a backup's compressed file, downloading the off-site copy when the local one is gone, and check its
     * checksum.
     *
     * @param  PlatformBackup  $backup
     * @return string the local path
     */
    private function fetch(PlatformBackup $backup): string
    {
        $path = $this->directory().'/'.$backup->file;
        if (! is_file($path)) {
            $location = $this->offsite();
            if ($location === null || ! $backup->isOffsite()) {
                throw new RuntimeException('That backup is no longer kept locally or off-site.');
            }
            $response = $this->s3->request($location, 'GET', (string) $backup->remote_key, timeout: 600);
            $this->s3->assertSuccessful('download', $response);
            file_put_contents($path, $response->body());
        }
        if (! hash_equals((string) $backup->sha256, (string) hash_file('sha256', $path))) {
            throw new RuntimeException('The backup file doesn’t match its checksum.');
        }

        return $path;
    }

    /**
     * Decompress a gzip file.
     *
     * @param  string  $from
     * @param  string  $to
     * @return void
     */
    private function gunzip(string $from, string $to): void
    {
        $in = gzopen($from, 'rb');
        $out = fopen($to, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('The backup couldn’t be decompressed.');
        }
        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1 << 20));
        }
        gzclose($in);
        fclose($out);
    }

    /**
     * Get the local backup directory, creating it (private to the web user) when needed.
     *
     * @return string
     */
    private function directory(): string
    {
        $directory = rtrim((string) config('platform.backups.path'), '/');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The backup directory couldn’t be created.');
        }

        return $directory;
    }

    /**
     * Get the off-site storage, or null when it isn't set up.
     *
     * @return S3Location|null
     */
    private function offsite(): ?S3Location
    {
        $s3 = (array) config('platform.backups.s3');
        foreach (['endpoint', 'bucket', 'key', 'secret'] as $key) {
            if (blank($s3[$key] ?? null)) {
                return null;
            }
        }

        return new S3Location((string) $s3['endpoint'], (string) ($s3['region'] ?? 'auto'), (string) $s3['bucket'], (string) $s3['key'], (string) $s3['secret']);
    }

    /**
     * Build the off-site key for a backup file.
     *
     * @param  string  $file
     * @return string
     */
    private function remoteKey(string $file): string
    {
        return trim((string) config('platform.backups.s3.prefix'), '/').'/'.$file;
    }
}
