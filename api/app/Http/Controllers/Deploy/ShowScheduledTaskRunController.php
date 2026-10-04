<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Models\Environment;
use App\Models\Project;
use Illuminate\Http\Response;

final class ShowScheduledTaskRunController
{
    /**
     * Show a task run's output as plain text, never cached, since it can hold secrets.
     *
     * @param  Project  $project
     * @param  Environment  $environment
     * @param  string  $task
     * @param  string  $run
     * @return Response
     */
    public function __invoke(Project $project, Environment $environment, string $task, string $run): Response
    {
        $record = $environment->scheduledTasks()->findOrFail((int) $task)->runs()->findOrFail((int) $run);

        return response($record->output ?: __('No output was recorded.'), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
