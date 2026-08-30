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
     *
     * @var string|null
     */
    protected ?string $fileName = null;

    /**
     * Path of the file
     *
     * @var string|null
     */
    protected ?string $file = null;

    /**
     * The scripts to run
     *
     * @var string|null
     */
    protected ?string $script = null;

    /**
     * @var \Spatie\Ssh\Ssh
     */
    protected Ssh $runner;

    /**
     * Publishable constructor
     *
     * @param  \App\Models\Server  $server
     *
     * @throws \Exception
     */
    public function __construct(Server $server)
    {
        $this->runner = (new Runner)->server($server)->create();
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
     * @return bool
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
     *
     * @return string
     */
    protected function run(): string
    {
        $script = escapeshellarg("/tmp/$this->fileName.sh");
        $log = escapeshellarg("/tmp/$this->fileName.log");
        $run = $this->runner->execute("sudo chmod 700 -- $script && nohup sudo $script > $log 2>&1 < /dev/null &");

        if (! $run->isSuccessful()) {
            throw new RuntimeException('Unable to start remote script: '.trim($run->getErrorOutput() ?: $run->getOutput()));
        }

        return $run->getOutput();
    }

    /**
     * Generate the script file
     *
     * @param  string  $name
     * @return string
     */
    protected function makeScriptFile(string $name): string
    {
        $this->fileName = Str::slug($name).'-'.Str::lower(Str::random(8));

        Storage::put($this->fileName, $this->script);

        $this->file = Storage::path($this->fileName);

        return $this->file;
    }
}
