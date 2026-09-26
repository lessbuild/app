<?php

namespace App\Modules\Monitor\Console\Commands;

use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Models\Workspace;
use App\Modules\Monitor\Services\DeliverIssueDigest;
use App\Modules\Monitor\Services\WorkspacePlanLimits;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Collection;
use Throwable;

#[Signature('issues:send-digest {--workspace= : Restrict the digest to a workspace ID} {--from= : Inclusive UTC period start} {--until= : Exclusive UTC period end}')]
#[Description('Send daily issue digests to eligible verified workspace members')]
class SendIssueDigest extends Command
{
    public function __construct(
        private readonly WorkspacePlanLimits $limits,
        private readonly DeliverIssueDigest $deliver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $workspaceId = $this->workspaceId();
        if ($workspaceId === false) {
            return 2;
        }

        $until = $this->timestamp('until');
        if ($until === false) {
            return 2;
        }
        $until ??= CarbonImmutable::now('UTC');
        $from = $this->timestamp('from');
        if ($from === false) {
            return 2;
        }
        $from ??= $until->subDay();

        if ($from->greaterThanOrEqualTo($until)) {
            $this->error('The from timestamp must be before the until timestamp.');

            return 2;
        }

        $query = Workspace::query()
            ->with([
                'owner',
                'members' => function (BelongsToMany $members): void {
                    $members->whereNotNull('email_verified_at');
                },
                'issueDigestPreferences',
            ])
            ->whereHas('members', fn (Builder $members): Builder => $members->whereNotNull('email_verified_at'));
        if ($workspaceId !== null) {
            $query->whereKey($workspaceId);
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        foreach ($query->orderBy('id')->lazyById(100) as $workspace) {
            if (! $this->limits->issueDigestEnabled($workspace)) {
                $skipped++;

                continue;
            }

            $recipients = $this->recipients($workspace);
            if ($recipients->isEmpty()) {
                $skipped++;

                continue;
            }

            foreach ($recipients as $recipient) {
                try {
                    match ($this->deliver->deliver($workspace, $recipient, $from, $until)) {
                        'sent' => $sent++,
                        'failed' => $failed++,
                        default => $skipped++,
                    };
                } catch (Throwable $exception) {
                    report($exception);
                    $failed++;
                    $this->error('Could not send the digest for workspace #'.$workspace->id.' to '.$recipient->email.'.');
                }
            }
        }

        $this->info(sprintf('Issue digests: %d sent, %d skipped, %d failed.', $sent, $skipped, $failed));

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return Collection<int, User> */
    private function recipients(Workspace $workspace): Collection
    {
        $preferences = $workspace->issueDigestPreferences->keyBy('user_id');

        return $workspace->members
            ->filter(function (User $member) use ($workspace, $preferences): bool {
                $preference = $preferences->get($member->id);

                if ($preference === null) {
                    return $member->id === $workspace->owner_id;
                }

                return $preference->enabled && $preference->frequency === 'daily';
            })
            ->values();
    }

    private function workspaceId(): int|false|null
    {
        $value = $this->option('workspace');
        if ($value === null) {
            return null;
        }

        $workspaceId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($workspaceId === false) {
            $this->error('The workspace must be a positive integer.');

            return false;
        }

        return $workspaceId;
    }

    private function timestamp(string $option): CarbonImmutable|false|null
    {
        $value = $this->option($option);
        if ($value === null) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            $this->error('The '.$option.' timestamp is invalid.');

            return false;
        }
    }
}
