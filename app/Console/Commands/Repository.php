<?php

namespace App\Console\Commands;

use App\Rules\GitBranch;
use App\Rules\SourceRepositoryUrl;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class Repository extends Command
{
    protected $signature = 'lessbuild:repository
        {repository : Public GitHub, GitLab, or Bitbucket repository URL}
        {branch=main : Branch to check out}
        {--name= : Safe destination directory name; generated when omitted}
        {--force : Replace an existing checkout only after the new clone succeeds}
        {--timeout=300 : Clone timeout in seconds (1-3600)}';

    protected $description = 'Safely clone a public source repository into local checkout storage';

    public function handle(): int
    {
        $input = $this->validatedInput();
        if ($input === null) {
            return self::FAILURE;
        }

        $root = $this->checkoutRoot();
        if ($root === null) {
            return self::FAILURE;
        }

        $destination = $root.DIRECTORY_SEPARATOR.$input['name'];
        if (is_link($destination)) {
            $this->error('Checkout destination cannot be a symbolic link.');

            return self::FAILURE;
        }
        if (File::exists($destination) && ! File::isDirectory($destination)) {
            $this->error("Checkout destination is not a directory: {$destination}");

            return self::FAILURE;
        }
        if (File::isDirectory($destination) && ! $this->option('force')) {
            $this->error('Checkout already exists. Use --force to replace it after a successful clone.');

            return self::FAILURE;
        }

        $temporary = $root.DIRECTORY_SEPARATOR.'.'.$input['name'].'.'.Str::lower(Str::random(12)).'.tmp';
        try {
            $result = Process::timeout($input['timeout'])
                ->env([
                    'GIT_TERMINAL_PROMPT' => '0',
                    'GIT_CONFIG_NOSYSTEM' => '1',
                ])
                ->run([
                    'git',
                    'clone',
                    '--depth',
                    '1',
                    '--single-branch',
                    '--branch',
                    $input['branch'],
                    '--no-tags',
                    '--',
                    'https://'.$input['repository'],
                    $temporary,
                ]);
        } catch (Throwable $exception) {
            $this->deleteCheckout($temporary);
            report($exception);
            $this->error('Repository checkout failed before it completed.');

            return self::FAILURE;
        }

        if (! $result->successful() || ! File::isDirectory($temporary.DIRECTORY_SEPARATOR.'.git')) {
            $this->deleteCheckout($temporary);
            $message = trim($result->errorOutput()) ?: trim($result->output());
            $this->error($message === '' ? 'Git did not create a valid checkout.' : Str::limit($message, 500));

            return self::FAILURE;
        }

        if (! $this->publish($temporary, $destination, $root, $input['name'])) {
            return self::FAILURE;
        }

        $this->info("Repository checked out to {$destination}");

        return self::SUCCESS;
    }

    private function checkoutRoot(): ?string
    {
        $configured = rtrim((string) config('lessbuild.repository_checkout_directory'), DIRECTORY_SEPARATOR);
        if ($configured === '') {
            $this->error('Repository checkout storage is not configured.');

            return null;
        }

        try {
            File::ensureDirectoryExists($configured, 0755, true);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Repository checkout storage could not be created.');

            return null;
        }

        $root = realpath($configured);
        if ($root === false || dirname($root) === $root) {
            $this->error('Repository checkout storage must be a dedicated directory below a filesystem root.');

            return null;
        }

        return $root;
    }

    /** @return array{repository: string, branch: string, name: string, timeout: int}|null */
    private function validatedInput(): ?array
    {
        $repository = SourceRepositoryUrl::normalize((string) $this->argument('repository'));
        $branch = trim((string) $this->argument('branch'));
        $name = trim((string) ($this->option('name') ?: Str::lower(Str::random(16))));
        $validator = Validator::make([
            'repository' => $repository,
            'branch' => $branch,
            'name' => $name,
            'timeout' => $this->option('timeout'),
        ], [
            'repository' => ['required', 'string', 'max:255', new SourceRepositoryUrl],
            'branch' => ['required', 'string', 'max:255', new GitBranch],
            'name' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9](?:[A-Za-z0-9._-]{0,63})\z/D'],
            'timeout' => ['required', 'integer', 'min:1', 'max:3600'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return null;
        }

        return [
            'repository' => $repository,
            'branch' => $branch,
            'name' => $name,
            'timeout' => (int) $this->option('timeout'),
        ];
    }

    private function publish(string $temporary, string $destination, string $root, string $name): bool
    {
        $backup = null;

        try {
            if (File::isDirectory($destination)) {
                $backup = $root.DIRECTORY_SEPARATOR.'.'.$name.'.'.Str::lower(Str::random(12)).'.backup';
                if (! File::moveDirectory($destination, $backup)) {
                    throw new \RuntimeException('The existing checkout could not be staged for replacement.');
                }
            }

            if (! File::moveDirectory($temporary, $destination)) {
                throw new \RuntimeException('The completed checkout could not be moved into place.');
            }

            if ($backup !== null) {
                File::deleteDirectory($backup);
            }

            return true;
        } catch (Throwable $exception) {
            $this->deleteCheckout($temporary);
            if ($backup !== null && File::isDirectory($backup) && ! File::exists($destination)) {
                File::moveDirectory($backup, $destination);
            }

            report($exception);
            $this->error($exception->getMessage());

            return false;
        }
    }

    private function deleteCheckout(string $path): void
    {
        if (is_link($path)) {
            File::delete($path);

            return;
        }

        File::deleteDirectory($path);
    }
}
