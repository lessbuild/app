<?php

declare(strict_types=1);

namespace App\Jobs\Deploy;

use App\Data\Deploy\DetectedApp;
use App\Models\Repository;
use App\Services\Deploy\RepositoryFiles;
use App\Services\Deploy\StackDetector;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\App;
use RuntimeException;

final class InspectRepository implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * One try: the repository page offers Look again.
     *
     * @var int
     */
    public int $tries = 1;

    /**
     * Create a new InspectRepository instance.
     *
     * Reads a repository's branch through its Git provider and keeps what's in it.
     *
     * @param  int  $repositoryId
     */
    public function __construct(public readonly int $repositoryId) {}

    /**
     * Read the repository and store its apps, or why it couldn't be read.
     *
     * @param  RepositoryFiles  $files
     * @param  StackDetector  $detector
     * @return void
     */
    public function handle(RepositoryFiles $files, StackDetector $detector): void
    {
        $repository = Repository::query()->with('provider')->find($this->repositoryId);
        if ($repository === null || $repository->provider === null) {
            return;
        }
        // Stored in English, as translation keys; pages translate them for whoever reads them.
        $locale = App::getLocale();
        App::setLocale('en');
        try {
            $apps = $detector->detect($files->open($repository->provider, $repository->url, $repository->branch));
            $repository->forceFill(['stack' => array_map(fn (DetectedApp $app): array => $app->toArray(), $apps), 'stack_error' => null, 'stack_checked_at' => now()])->save();
        } catch (RuntimeException $exception) {
            $repository->forceFill(['stack_error' => mb_substr($exception->getMessage(), 0, 500), 'stack_checked_at' => now()])->save();
        } finally {
            App::setLocale($locale);
        }
    }
}
