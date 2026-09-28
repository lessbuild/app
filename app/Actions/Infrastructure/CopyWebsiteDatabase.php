<?php

declare(strict_types=1);

namespace App\Actions\Infrastructure;

use App\Enums\EnvironmentKind;
use App\Jobs\Infrastructure\CopyDatabase;
use App\Models\DatabaseClone;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class CopyWebsiteDatabase
{
    /**
     * Replace the target website's database with a copy of the source's. The person types the target's name to confirm;
     * both must be on the same server, and websites linked to a production environment can't be overwritten.
     *
     * @param  User  $actor
     * @param  Website  $source
     * @param  Website  $target
     * @param  string  $confirmation
     * @return DatabaseClone
     */
    public function handle(User $actor, Website $source, Website $target, string $confirmation): DatabaseClone
    {
        Gate::forUser($actor)->authorize('manageDatabase', $source);
        Gate::forUser($actor)->authorize('manageDatabase', $target);
        if ($target->is($source) || $target->server_id !== $source->server_id) {
            throw ValidationException::withMessages(['target_website_id' => __('Choose another website on the same server.')]);
        }
        if ($target->environment?->kind === EnvironmentKind::Production) {
            throw ValidationException::withMessages(['target_website_id' => __('Websites linked to a production environment can’t be overwritten.')]);
        }
        if (! hash_equals($target->name, trim($confirmation))) {
            throw ValidationException::withMessages(['confirmation' => __('Type the name of the website being overwritten exactly.')]);
        }

        return DB::transaction(function () use ($actor, $source, $target): DatabaseClone {
            if (DatabaseClone::query()->whereIn('status', ['queued', 'running'])->where(fn ($query) => $query->whereIn('target_website_id', [$source->id, $target->id])->orWhere('source_website_id', $target->id))->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['target_website_id' => __('A copy involving these websites is already running.')]);
            }
            $clone = new DatabaseClone;
            $clone->forceFill(['source_website_id' => $source->id, 'target_website_id' => $target->id, 'requested_by' => $actor->id, 'status' => 'queued'])->save();
            CopyDatabase::dispatch($clone->id)->afterCommit();

            return $clone;
        });
    }
}
