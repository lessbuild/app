<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Events\Projects\DomainAdded;
use App\Exceptions\ProjectRuleViolation;
use App\Models\Domain;
use App\Models\Project;
use App\Models\User;
use App\Support\Hostname;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class AddDomain
{
    /**
     * Claim a hostname for the project; it stays unverified until its TXT record is found.
     *
     * @param  User  $actor
     * @param  Project  $project
     * @param  string  $hostname
     * @param  string|null  $environmentId
     * @return Domain
     */
    public function handle(User $actor, Project $project, string $hostname, ?string $environmentId = null): Domain
    {
        Gate::forUser($actor)->authorize('update', $project);
        $ascii = Hostname::normalize($hostname) ?? throw ProjectRuleViolation::invalidHostname();
        if ($environmentId !== null && ! $project->environments()->whereKey($environmentId)->exists()) {
            throw ProjectRuleViolation::environmentNotInProject();
        }
        if ($project->domains()->where('hostname', $ascii)->exists()) {
            throw ProjectRuleViolation::domainAlreadyAdded();
        }

        $domain = new Domain;
        $domain->forceFill([
            'project_id' => $project->id,
            'environment_id' => $environmentId,
            'hostname' => $ascii,
            'verification_token' => Str::lower(Str::random(40)),
        ]);
        try {
            $domain->save();
        } catch (UniqueConstraintViolationException) {
            throw ProjectRuleViolation::domainAlreadyAdded();
        }

        DomainAdded::dispatch($domain, $actor);

        return $domain;
    }
}
