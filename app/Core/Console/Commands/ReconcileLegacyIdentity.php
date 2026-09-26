<?php

namespace App\Core\Console\Commands;

use App\Core\Services\Identity\ReconcileLegacyIdentity as ReconcileLegacyIdentityService;
use Illuminate\Console\Command;

final class ReconcileLegacyIdentity extends Command
{
    protected $signature = 'platform:reconcile-legacy-identity
        {product : One of deployer, monitor, or analytics}
        {source_id : The legacy product user ID held for review}
        {platform_user_id : The existing Core user ULID}
        {--reviewer= : Required with --apply; operator identity recorded in the mapping}
        {--evidence-ref= : Required with --apply; non-secret ownership-review reference}
        {--apply : Reconcile only after independent ownership review; default is read-only}';

    protected $description = 'Preview or explicitly reconcile one held legacy user mapping after ownership review';

    public function handle(ReconcileLegacyIdentityService $reconciler): int
    {
        $apply = (bool) $this->option('apply');
        $result = $reconciler->run(
            (string) $this->argument('product'),
            (string) $this->argument('source_id'),
            (string) $this->argument('platform_user_id'),
            (string) ($this->option('reviewer') ?? ''),
            (string) ($this->option('evidence-ref') ?? ''),
            $apply,
        );

        if ($result['status'] === 'blocked') {
            $this->components->error('Identity mapping was not reconciled: '.($result['reason'] ?? 'review_required').'.');

            return self::FAILURE;
        }

        if ($result['status'] === 'already_reconciled') {
            $this->components->info('The requested identity mapping is already reconciled.');

            return self::SUCCESS;
        }

        if ($result['status'] === 'ready') {
            $this->components->info('The held mapping passed consistency checks and is ready for operator review.');
            $this->line('No data was changed. Matching verified email is a consistency check, not ownership proof.');
            $this->line('Apply only after independently verifying ownership, with --apply, --reviewer, and --evidence-ref.');

            return self::SUCCESS;
        }

        $this->components->info('The identity mapping was reconciled and the reviewer reference was recorded.');

        return self::SUCCESS;
    }
}
