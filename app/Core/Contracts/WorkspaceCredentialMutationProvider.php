<?php

namespace App\Core\Contracts;

use App\Core\Data\Credentials\CredentialCreateOptions;
use App\Core\Data\Credentials\CredentialMutationCommand;
use App\Core\Data\Credentials\CredentialMutationOutcome;
use App\Core\Models\PlatformUser;
use App\Core\Models\Project;
use App\Core\Models\Workspace;
use Illuminate\Support\Collection;

interface WorkspaceCredentialMutationProvider
{
    /** @param Collection<int, Project> $projects */
    public function createOptions(PlatformUser $actor, Workspace $workspace, Collection $projects): CredentialCreateOptions;

    public function mutate(PlatformUser $actor, Workspace $workspace, CredentialMutationCommand $command): CredentialMutationOutcome;
}
