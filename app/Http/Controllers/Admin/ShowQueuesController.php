<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Admin\SystemHealth;
use Illuminate\Contracts\View\View;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

final class ShowQueuesController
{
    /**
     * Show each queue's backlog and the latest 100 failed jobs, each with its job class and the first line of its
     * error.
     *
     * @param  SystemHealth  $health
     * @param  FailedJobProviderInterface  $failed
     * @return View
     */
    public function __invoke(SystemHealth $health, FailedJobProviderInterface $failed): View
    {
        $jobs = array_map(function (object $job): array {
            $payload = json_decode((string) ($job->payload ?? ''), true);

            return [
                'uuid' => (string) ($job->uuid ?? $job->id ?? ''),
                'queue' => (string) ($job->queue ?? ''),
                'job' => is_array($payload) ? (string) ($payload['displayName'] ?? $payload['job'] ?? '') : '',
                'error' => mb_substr(strtok((string) ($job->exception ?? ''), "\n") ?: '', 0, 300),
                'failed_at' => (string) ($job->failed_at ?? ''),
            ];
        }, array_slice($failed->all(), 0, 100));

        return view('admin.queues', ['queues' => $health->queues(), 'jobs' => $jobs]);
    }
}
