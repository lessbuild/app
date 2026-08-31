<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use PDO;

class SqliteBackupVerifier
{
    public function verify(string $path): void
    {
        if (! File::isFile($path)) {
            throw new \RuntimeException('The backup file does not exist.');
        }

        if (File::size($path) === 0) {
            throw new \RuntimeException('The backup file is empty.');
        }

        $snapshot = new PDO('sqlite:'.$path, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if ($snapshot->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') {
            throw new \RuntimeException('SQLite reported that the backup is not valid.');
        }
    }
}
