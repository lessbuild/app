<?php

namespace App\Abstracts;

use App\Models\Server;
use App\Services\Runner;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Ssh\Ssh;

Abstract Class Publishable
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
     * @param \App\Models\Server $server
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
        File::delete($this->file);
    }

    /**
     * Upload the script to the server
     *
     * @return bool
     * @throws \Exception
     */
    protected function upload(): bool
    {
        upload:
        $upload = $this->runner->upload(
            sourcePath: $this->file,
            destinationPath: "/tmp/$this->fileName.sh"
        );

        if (str_contains($upload->getOutput(), 'Connection refused') || ! $upload->isSuccessful()) {
            sleep(3);
            goto upload;
        }

        return true;
    }

    /**
     * Run the script
     *
     * @return string
     */
    protected function run(): string
    {
        $run = $this->runner->execute([
            "sudo chmod +x /tmp/$this->fileName.sh",
            "sudo echo '* * * * * root /tmp/$this->fileName.sh >> /tmp/$this->fileName.log' >> /etc/cron.d/$this->fileName",
        ]);

        return $run->getOutput();
    }

    /**
     * Generate the script file
     *
     * @param string $name
     * @return string
     */
    protected function makeScriptFile(string $name): string
    {
        $this->fileName = $name;

        Storage::put($this->fileName, $this->script);

        $this->file = Storage::path($this->fileName);

        return $this->file;
    }
}
