<?php

namespace App\Abstracts;

use App\Models\Server;
use App\Services\Runner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Ssh\Ssh;

abstract class Publishable
{
    /**
     * Name of the file
     */
    protected ?string $fileName = null;

    /**
     * Path of the file
     */
    protected ?string $file = null;

    /**
     * The scripts to run
     */
    protected ?string $script = null;

    protected Ssh $runner;

    /**
     * Publishable constructor
     *
     *
     * @throws \Exception
     */
    public function __construct(Server $server, ?Runner $runner = null)
    {
        $this->runner = ($runner ?? new Runner)->server($server)->create();
    }

    /**
     * On class destruct
     *
     * @return void
     */
    public function __destruct()
    {
        if ($this->file) {
            File::delete($this->file);
        }
    }

    /**
     * Upload the script to the server
     *
     *
     * @throws \Exception
     */
    protected function upload(): bool
    {
        $attempts = max(1, (int) config('lessbuild.ssh_upload_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $upload = $this->runner->upload(
                sourcePath: $this->file,
                destinationPath: "/tmp/$this->fileName.sh"
            );

            if ($upload->isSuccessful()) {
                return true;
            }

            if ($attempt < $attempts) {
                usleep(max(0, (int) config('lessbuild.ssh_retry_delay_ms', 1000)) * 1000);
            }
        }

        throw new RuntimeException(sprintf(
            'Unable to upload deployment script after %d attempts: %s',
            $attempts,
            trim($upload->getErrorOutput() ?: $upload->getOutput())
        ));
    }

    /**
     * Run the script
     */
    protected function run(): string
    {
        $script = escapeshellarg("/tmp/$this->fileName.sh");
        $log = escapeshellarg("/tmp/$this->fileName.log");
        $pid = escapeshellarg("/tmp/$this->fileName.pid");
        $run = $this->runner->execute(
            "sudo chmod 700 -- $script && { nohup sudo setsid -- $script > $log 2>&1 < /dev/null & process_id=\$!; if printf '%s\\n' \"\$process_id\" | sudo tee $pid >/dev/null && sudo chmod 600 -- $pid; then echo \"\$process_id\"; else sudo kill -TERM -- \"-\$process_id\" 2>/dev/null || true; exit 1; fi; }",
        );

        if (! $run->isSuccessful()) {
            throw new RuntimeException('Unable to start remote script: '.trim($run->getErrorOutput() ?: $run->getOutput()));
        }

        return $run->getOutput();
    }

    /**
     * Generate the script file
     */
    protected function makeScriptFile(string $name, ?string $fileName = null): string
    {
        $slug = rtrim(Str::limit(Str::slug($name), 180, ''), '-') ?: 'deployment';
        if ($fileName !== null && ! preg_match('/\A[a-z0-9-]+\z/D', $fileName)) {
            throw new RuntimeException('The remote script identifier is invalid.');
        }
        $this->fileName = $fileName ?? $slug.'-'.Str::lower(Str::random(8));

        Storage::put($this->fileName, $this->script);

        $this->file = Storage::path($this->fileName);

        return $this->file;
    }
}
