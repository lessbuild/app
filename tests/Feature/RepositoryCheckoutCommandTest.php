<?php

namespace Tests\Feature;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Tests\TestCase;

class RepositoryCheckoutCommandTest extends TestCase
{
    private string $checkoutRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->checkoutRoot = storage_path('framework/testing/repository-checkouts-'.Str::lower(Str::random(12)));
        config(['lessbuild.repository_checkout_directory' => $this->checkoutRoot]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->checkoutRoot);

        parent::tearDown();
    }

    public function test_command_normalizes_and_clones_a_supported_public_repository_without_a_shell(): void
    {
        Process::fake(function (PendingProcess $process) {
            $destination = $process->command[array_key_last($process->command)];
            File::ensureDirectoryExists($destination.'/.git');
            File::put($destination.'/README.md', 'new checkout');

            return Process::result();
        });

        $this->artisan('lessbuild:repository', [
            'repository' => 'git@GitHub.com:lessbuild/app.git',
            'branch' => 'release/2026.08',
            '--name' => 'release-candidate',
            '--timeout' => '45',
        ])->assertSuccessful();

        $destination = $this->checkoutRoot.'/release-candidate';
        $this->assertSame('new checkout', File::get($destination.'/README.md'));
        Process::assertRan(function (PendingProcess $process): bool {
            $this->assertSame([
                'git',
                'clone',
                '--depth',
                '1',
                '--single-branch',
                '--branch',
                'release/2026.08',
                '--no-tags',
                '--',
                'https://github.com/lessbuild/app.git',
            ], array_slice($process->command, 0, -1));
            $this->assertMatchesRegularExpression(
                '#^'.preg_quote($this->checkoutRoot, '#').'/\.release-candidate\.[a-z0-9]{12}\.tmp$#',
                $process->command[array_key_last($process->command)],
            );
            $this->assertSame(45, $process->timeout);
            $this->assertSame('0', $process->environment['GIT_TERMINAL_PROMPT']);
            $this->assertSame('1', $process->environment['GIT_CONFIG_NOSYSTEM']);

            return true;
        });
    }

    public function test_invalid_source_branch_name_and_timeout_never_start_git(): void
    {
        Process::fake();

        foreach ([
            ['repository' => 'github.com/lessbuild/app.git; touch /tmp/pwned', '--name' => 'safe'],
            ['repository' => 'https://user@github.com/lessbuild/app.git', '--name' => 'safe'],
            ['repository' => 'github.com/lessbuild/app.git', 'branch' => 'main; id', '--name' => 'safe'],
            ['repository' => 'github.com/lessbuild/app.git', '--name' => '../escape'],
            ['repository' => 'github.com/lessbuild/app.git', '--name' => 'safe', '--timeout' => '0'],
        ] as $arguments) {
            $this->artisan('lessbuild:repository', $arguments)->assertFailed();
        }

        Process::assertNothingRan();
        $this->assertDirectoryDoesNotExist(dirname($this->checkoutRoot).'/escape');
    }

    public function test_existing_checkout_requires_force_and_failed_clone_preserves_it(): void
    {
        $destination = $this->checkoutRoot.'/application';
        File::ensureDirectoryExists($destination.'/.git');
        File::put($destination.'/marker.txt', 'existing checkout');
        Process::fake(['*' => Process::result(errorOutput: 'remote branch unavailable', exitCode: 128)]);

        $arguments = [
            'repository' => 'github.com/lessbuild/app.git',
            '--name' => 'application',
        ];
        $this->artisan('lessbuild:repository', $arguments)->assertFailed();
        Process::assertNothingRan();

        $this->artisan('lessbuild:repository', [...$arguments, '--force' => true])
            ->expectsOutputToContain('remote branch unavailable')
            ->assertFailed();
        $this->assertSame('existing checkout', File::get($destination.'/marker.txt'));
        $this->assertSame([], File::glob($this->checkoutRoot.'/.application.*'));
    }

    public function test_force_rejects_a_symbolic_link_destination_without_touching_its_target(): void
    {
        $external = $this->checkoutRoot.'-external';
        File::ensureDirectoryExists($external);
        File::put($external.'/marker.txt', 'outside checkout storage');
        File::ensureDirectoryExists($this->checkoutRoot);
        $this->assertTrue(symlink($external, $this->checkoutRoot.'/application'));
        Process::fake();

        $this->artisan('lessbuild:repository', [
            'repository' => 'github.com/lessbuild/app.git',
            '--name' => 'application',
            '--force' => true,
        ])->expectsOutputToContain('cannot be a symbolic link')
            ->assertFailed();

        Process::assertNothingRan();
        $this->assertSame('outside checkout storage', File::get($external.'/marker.txt'));
        File::deleteDirectory($external);
    }

    public function test_force_publishes_a_complete_clone_and_then_removes_the_previous_checkout(): void
    {
        $destination = $this->checkoutRoot.'/application';
        File::ensureDirectoryExists($destination.'/.git');
        File::put($destination.'/old.txt', 'old checkout');
        Process::fake(function (PendingProcess $process) {
            $temporary = $process->command[array_key_last($process->command)];
            File::ensureDirectoryExists($temporary.'/.git');
            File::put($temporary.'/new.txt', 'new checkout');

            return Process::result();
        });

        $this->artisan('lessbuild:repository', [
            'repository' => 'github.com/lessbuild/app.git',
            '--name' => 'application',
            '--force' => true,
        ])->assertSuccessful();

        $this->assertFileDoesNotExist($destination.'/old.txt');
        $this->assertSame('new checkout', File::get($destination.'/new.txt'));
        $this->assertSame([], File::glob($this->checkoutRoot.'/.application.*'));
    }
}
