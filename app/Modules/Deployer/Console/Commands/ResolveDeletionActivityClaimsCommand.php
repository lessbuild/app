<?php

namespace App\Modules\Deployer\Console\Commands;

use App\Modules\Deployer\Models\ProductDeletionActivityClaim;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

final class ResolveDeletionActivityClaimsCommand extends Command
{
    protected $signature = 'buildpusher:deletion-claims {claim_group_id? : Claim group to inspect or resolve} {--resolve-stopped : Confirm the worker and all related effects have stopped} {--reason= : Required operator reason for explicit recovery}';

    protected $description = 'Inspect or explicitly recover Deployer deletion activity claims left by stopped workers';

    public function handle(): int
    {
        if (! Schema::connection('deployer')->hasTable('product_deletion_activity_claims')) {
            $this->error('The Deployer deletion activity claim table is not available.');

            return self::FAILURE;
        }

        $groupId = $this->argument('claim_group_id');
        $query = ProductDeletionActivityClaim::query()->where('status', 'claimed');
        if (filled($groupId)) {
            $query->where('claim_group_id', $groupId);
        }

        if (! $this->option('resolve-stopped')) {
            $claims = $query->orderBy('started_at')->get([
                'claim_group_id', 'actor_source_id', 'workspace_source_id', 'operation', 'started_at',
            ]);
            if ($claims->isEmpty()) {
                $this->info('No unresolved Deployer deletion activity claims matched.');

                return self::SUCCESS;
            }
            $this->table(['Claim group', 'Actor', 'Workspace', 'Operation', 'Started'], $claims->map(fn (ProductDeletionActivityClaim $claim): array => [
                $claim->claim_group_id,
                $claim->actor_source_id,
                $claim->workspace_source_id ?? '(actor only)',
                $claim->operation,
                $claim->started_at?->toDateTimeString(),
            ])->all());
            $this->warn('Claims never expire automatically. Resolve one only after confirming the worker and all related remote effects have stopped.');

            return self::SUCCESS;
        }

        $reason = trim((string) $this->option('reason'));
        if (! filled($groupId) || mb_strlen($reason) < 8) {
            $this->error('Resolving requires a claim group and an operator reason of at least 8 characters.');

            return self::FAILURE;
        }

        $updated = $query->update([
            'status' => 'operator_recovered',
            'completed_at' => now(),
            'recovered_at' => now(),
            'recovery_reason' => mb_substr($reason, 0, 500),
            'updated_at' => now(),
        ]);
        if ($updated === 0) {
            $this->error('No unresolved claims in that group were changed.');

            return self::FAILURE;
        }

        $this->warn("Explicitly recovered {$updated} claim row(s) for group {$groupId}.");

        return self::SUCCESS;
    }
}
