<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Models\Build;

final class RecordBuildFailure
{
    /**
     * Records a failure reported by a running deploy script.
     *
     * @param  FinishBuild  $finish  Finishes the deploy as failed.
     */
    public function __construct(private readonly FinishBuild $finish) {}

    /**
     * The deployment script failed (signed callback); it has already put the previous release back.
     *
     * @param  Build  $build
     * @param  string  $message
     * @param  int|null  $exitCode
     * @return void
     */
    public function handle(Build $build, string $message, ?int $exitCode): void
    {
        $message = trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $message) ?? '') ?: 'Remote deployment script failed';
        $this->finish->handle($build, Build::STATUS_FAILED, mb_substr($message, 0, 500).($exitCode === null ? '' : " (exit code {$exitCode})"));
    }
}
