<?php

namespace App\Modules\Deployer\Services;

use App\Modules\Deployer\Data\ServerTroubleshootingBrokerLease;
use App\Modules\Deployer\Data\ServerTroubleshootingFrameData;
use App\Modules\Deployer\Enums\ServerTroubleshootingFrameDirection;
use App\Modules\Deployer\Exceptions\ServerTroubleshootingFrameException;
use App\Modules\Deployer\Models\ServerTroubleshootingFrame;
use App\Modules\Deployer\Models\ServerTroubleshootingSession;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServerTroubleshootingFrameStore
{
    /**
     * Append an input frame while the caller holds the session row lock.
     * Input is retained encrypted only until the bounded retention pass.
     */
    public function appendInputLocked(ServerTroubleshootingSession $session, string $payload): ServerTroubleshootingFrameData
    {
        $sequence = ((int) $session->input_sequence) + 1;
        $frame = $session->frames()->create([
            'direction' => ServerTroubleshootingFrameDirection::Input,
            'sequence' => $sequence,
            'payload' => $payload,
            'payload_bytes' => strlen($payload),
        ]);
        $session->update(['input_sequence' => $sequence]);

        return ServerTroubleshootingFrameData::fromModel($frame);
    }

    /**
     * Claim one input frame before remote write. Marking it first gives shell
     * input at-most-once semantics across a broker crash; an uncertain write
     * is discarded rather than replayed into a new shell.
     */
    public function claimNextInput(ServerTroubleshootingBrokerLease $lease): ?ServerTroubleshootingFrameData
    {
        return DB::transaction(function () use ($lease): ?ServerTroubleshootingFrameData {
            $session = $this->lockedLease($lease);
            if ($session === null || ! $this->acceptsBrokerActivity($session)) {
                return null;
            }

            $frame = $session->frames()
                ->where('direction', ServerTroubleshootingFrameDirection::Input->value)
                ->whereNull('sent_at')
                ->orderBy('sequence')
                ->lockForUpdate()
                ->first();

            if (! $frame) {
                return null;
            }

            $frame->update(['sent_at' => now()]);

            return ServerTroubleshootingFrameData::fromModel($frame);
        });
    }

    /**
     * Persist a bounded output chunk only for the current broker lease.
     * Pending output is capped before any encrypted row is inserted.
     */
    public function appendOutput(ServerTroubleshootingBrokerLease $lease, string $payload): int
    {
        if ($payload === '') {
            return 0;
        }

        $maximumFrameBytes = $this->maximumOutputFrameBytes();
        $chunks = str_split($payload, $maximumFrameBytes);

        return DB::transaction(function () use ($chunks, $lease): int {
            $session = $this->lockedLease($lease);
            if ($session === null || ! $this->acceptsBrokerActivity($session)) {
                return 0;
            }

            $pending = $session->frames()
                ->where('direction', ServerTroubleshootingFrameDirection::Output->value)
                ->whereNull('acknowledged_at');
            $pendingFrames = (int) $pending->count();
            $pendingBytes = (int) $pending->sum('payload_bytes');
            $addedBytes = array_sum(array_map('strlen', $chunks));

            if ($pendingFrames + count($chunks) > $this->maximumPendingOutputFrames()
                || $pendingBytes + $addedBytes > $this->maximumPendingOutputBytes()) {
                throw new ServerTroubleshootingFrameException(
                    'The troubleshooting output buffer is full; the connection must close.',
                );
            }

            $sequence = (int) $session->output_sequence;
            foreach ($chunks as $chunk) {
                $sequence++;
                $session->frames()->create([
                    'direction' => ServerTroubleshootingFrameDirection::Output,
                    'sequence' => $sequence,
                    'payload' => $chunk,
                    'payload_bytes' => strlen($chunk),
                ]);
            }
            $session->update(['output_sequence' => $sequence]);

            return count($chunks);
        });
    }

    /** Read only a bounded output window while the caller holds the session lock. */
    /**
     * @return list<ServerTroubleshootingFrameData>
     */
    public function readOutputLocked(ServerTroubleshootingSession $session, int $after, int $limit): array
    {
        /** @var Collection<int, ServerTroubleshootingFrame> $frames */
        $frames = $session->frames()
            ->where('direction', ServerTroubleshootingFrameDirection::Output->value)
            ->whereNull('acknowledged_at')
            ->where('sequence', '>', $after)
            ->orderBy('sequence')
            ->limit($limit)
            ->get();

        return $frames
            ->map(static fn (ServerTroubleshootingFrame $frame): ServerTroubleshootingFrameData => ServerTroubleshootingFrameData::fromModel($frame))
            ->values()
            ->all();
    }

    /** Acknowledge only output frames already persisted for this session. */
    public function acknowledgeOutputLocked(ServerTroubleshootingSession $session, int $through): int
    {
        return $session->frames()
            ->where('direction', ServerTroubleshootingFrameDirection::Output->value)
            ->whereNull('acknowledged_at')
            ->where('sequence', '<=', $through)
            ->update(['acknowledged_at' => now()]);
    }

    /** Delete a bounded batch of expired encrypted frames without decrypting them. */
    public function prune(int $limit = 500): int
    {
        $limit = max(1, min(5000, $limit));
        $cutoff = now()->subSeconds($this->retentionSeconds());
        $ids = ServerTroubleshootingFrame::query()
            ->where('created_at', '<=', $cutoff)
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        if ($ids->isEmpty()) {
            return 0;
        }

        return ServerTroubleshootingFrame::query()->whereKey($ids)->delete();
    }

    /** @return ServerTroubleshootingSession|null Session matching this exact broker owner. */
    private function lockedLease(ServerTroubleshootingBrokerLease $lease): ?ServerTroubleshootingSession
    {
        return ServerTroubleshootingSession::query()
            ->whereKey($lease->session->id)
            ->where('broker_lease_hash', hash('sha256', $lease->token))
            ->where('broker_attempt', $lease->attempt)
            ->where('broker_process_id', $lease->processId)
            ->lockForUpdate()
            ->first();
    }

    private function acceptsBrokerActivity(ServerTroubleshootingSession $session): bool
    {
        return $session->statusEnum()?->acceptsActivity() === true
            && ! $session->hasExpired()
            && ($session->broker_lease_expires_at?->isFuture() ?? false);
    }

    private function maximumOutputFrameBytes(): int
    {
        return max(1, (int) config('lessbuild.troubleshooting.output_frame_max_bytes', 16384));
    }

    private function maximumPendingOutputFrames(): int
    {
        return max(1, min(10000, (int) config('lessbuild.troubleshooting.max_pending_output_frames', 100)));
    }

    private function maximumPendingOutputBytes(): int
    {
        return max(1, min(100000000, (int) config('lessbuild.troubleshooting.max_pending_output_bytes', 262144)));
    }

    private function retentionSeconds(): int
    {
        return max(60, min(86400, (int) config('lessbuild.troubleshooting.frame_retention_seconds', 300)));
    }
}
