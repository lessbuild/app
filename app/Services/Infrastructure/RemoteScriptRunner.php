<?php

declare(strict_types=1);

namespace App\Services\Infrastructure;

use App\Models\Server;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/** Uploads a shell script to a server over SSH and starts it in the background, returning its process ID and path. */
class RemoteScriptRunner
{
    /**
     * Starts scripts on servers.
     *
     * @param  Runner  $runner  Builds the SSH client.
     */
    public function __construct(private readonly Runner $runner) {}

    /**
     * Uploads the script (retrying the upload), then runs it detached as root with its output to a log and its process
     * ID in a file. Returns the process ID and script path.
     *
     * @return array{id: int, path: string}
     */
    public function start(Server $server, string $script, string $name): array
    {
        $ssh = $this->runner->server($server)->create();
        $fileName = (rtrim(Str::limit(Str::slug($name), 180, ''), '-') ?: 'script').'-'.Str::lower(Str::random(8));
        $local = tempnam(sys_get_temp_dir(), 'remote-script-');
        if ($local === false) {
            throw new RuntimeException('Unable to create a temporary script file.');
        }

        try {
            File::put($local, $script);
            $attempts = max(1, (int) config('infrastructure.ssh_upload_attempts', 3));
            for ($attempt = 1; ; $attempt++) {
                $upload = $ssh->upload($local, "/tmp/{$fileName}.sh");
                if ($upload->isSuccessful()) {
                    break;
                }
                if ($attempt >= $attempts) {
                    throw new RuntimeException(sprintf('Unable to upload the script after %d attempts: %s', $attempts, trim($upload->getErrorOutput() ?: $upload->getOutput())));
                }
                usleep(max(0, (int) config('infrastructure.ssh_retry_delay_ms', 1000)) * 1000);
            }
        } finally {
            File::delete($local);
        }

        $path = escapeshellarg("/tmp/{$fileName}.sh");
        $log = escapeshellarg("/tmp/{$fileName}.log");
        $pid = escapeshellarg("/tmp/{$fileName}.pid");
        $run = $ssh->execute("sudo chmod 700 -- {$path} && { nohup sudo setsid -- {$path} > {$log} 2>&1 < /dev/null & process_id=\$!; if printf '%s\\n' \"\$process_id\" | sudo tee {$pid} >/dev/null && sudo chmod 600 -- {$pid}; then echo \"\$process_id\"; else sudo kill -TERM -- \"-\$process_id\" 2>/dev/null || true; exit 1; fi; }");
        if (! $run->isSuccessful()) {
            throw new RuntimeException('Unable to start the remote script: '.trim($run->getErrorOutput() ?: $run->getOutput()));
        }
        $output = trim($run->getOutput());
        if (! ctype_digit($output) || (int) $output < 1) {
            throw new RuntimeException('The remote script started without returning a valid process ID.');
        }

        return ['id' => (int) $output, 'path' => "/tmp/{$fileName}.sh"];
    }
}
