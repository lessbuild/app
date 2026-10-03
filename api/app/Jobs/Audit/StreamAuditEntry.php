<?php

declare(strict_types=1);

namespace App\Jobs\Audit;

use App\Models\AuditEntry;
use App\Models\AuditStream;
use App\Services\Audit\AuditStreamSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Str;
use Throwable;

/** Sends a new audit entry to each of its account's enabled streams, keeping score of failures. */
final class StreamAuditEntry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * Create a new StreamAuditEntry instance.
     *
     * @param  string  $entryId  The audit entry.
     */
    public function __construct(public readonly string $entryId) {}

    /**
     * Send the entry to every enabled stream. A failure is recorded against the stream (paused after 20 in a row);
     * a success clears the count.
     *
     * @param  AuditStreamSender  $sender
     * @return void
     */
    public function handle(AuditStreamSender $sender): void
    {
        $entry = AuditEntry::query()->find($this->entryId);
        if ($entry === null || $entry->account_id === null) {
            return;
        }
        foreach (AuditStream::query()->where('account_id', $entry->account_id)->where('enabled', true)->with('destination')->get() as $stream) {
            try {
                $sender->send($stream, $entry);
                $stream->forceFill(['failure_count' => 0, 'last_error' => null, 'last_delivered_at' => now()])->save();
            } catch (Throwable $exception) {
                $failures = $stream->failure_count + 1;
                $stream->forceFill([
                    'failure_count' => $failures, 'last_error' => Str::limit($exception->getMessage(), 250),
                    'enabled' => $failures < AuditStream::MAX_FAILURES,
                ])->save();
            }
        }
    }
}
