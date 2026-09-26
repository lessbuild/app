<?php

namespace App\Core\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Recovery only releases a dead writer; normal product access must still finish its mapping. */
final class RecoverIdentityProjectionCommand extends Command
{
    protected $signature = 'identity-projections:recover {id : Exact identity projection operation ULID}
        {--apply : Mark this interrupted operation retryable}
        {--workers-stopped : Confirm all HTTP and queue processes that could own this operation are stopped}';

    protected $description = 'Inspect an interrupted identity projection and explicitly release its dead worker claim';

    public function handle(): int
    {
        $id = (string) $this->argument('id');
        if (! Str::isUlid($id)) {
            $this->error('Supply the exact identity projection operation ULID.');

            return self::INVALID;
        }
        $row = DB::connection('core')->table('identity_projection_operations')->where('id', $id)->first();
        if ($row === null) {
            $this->error('No unresolved projection exists for that operation.');

            return self::FAILURE;
        }
        $this->table(['Operation', 'Product', 'Kind', 'Canonical ID', 'State'], [[$row->id, $row->product, $row->kind, $row->canonical_id, $row->status]]);
        if (! $this->option('apply')) {
            $this->line('Read-only inspection. Deletion stays blocked until the source and Core mapping finish.');

            return self::SUCCESS;
        }
        if (! $this->option('workers-stopped')) {
            $this->error('Stop the HTTP and queue processes that could still own this operation before using --apply --workers-stopped. Elapsed time is not evidence that a writer has stopped.');

            return self::INVALID;
        }
        $changed = DB::connection('core')->table('identity_projection_operations')->where('id', $id)->where('token', $row->token)
            ->update(['status' => 'failed', 'updated_at' => now()]);
        if ($changed !== 1) {
            $this->error('The operation changed after inspection; no claim was released.');

            return self::FAILURE;
        }
        $this->info('The stopped operation can be retried. Restart the processes and open the affected app to finish its mapping. Deletion remains blocked until that succeeds.');

        return self::SUCCESS;
    }
}
