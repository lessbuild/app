<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\DomainRemoved;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Gate;

final class RemoveDomain
{
    public function handle(User $actor, Domain $domain): void
    {
        Gate::forUser($actor)->authorize('update', $domain->project);

        $domain->delete();
        DomainRemoved::dispatch($domain, $actor);
    }
}
