<?php

declare(strict_types=1);

namespace App\Domain\Projects\Actions;

use App\Domain\Identity\Models\User;
use App\Domain\Projects\Events\DomainRemoved;
use App\Domain\Projects\Models\Domain;
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
