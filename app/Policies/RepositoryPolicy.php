<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\AccountPermission;
use App\Models\Project;
use App\Models\Repository;
use App\Models\User;
use App\Policies\Concerns\ChecksAccountRole;

/** Anyone with Deploy access sees repositories and their builds; members and above with Deploy access set them up and deploy. */
final class RepositoryPolicy
{
    use ChecksAccountRole;

    public function view(User $user, Repository $repository): bool
    {
        return $this->allows($user, $repository->project->account_id, AccountPermission::ViewProjects, 'deploy');
    }

    public function create(User $user, Project $project): bool
    {
        return $this->allows($user, $project->account_id, AccountPermission::ManageProjects, 'deploy');
    }

    public function update(User $user, Repository $repository): bool
    {
        return $this->allows($user, $repository->project->account_id, AccountPermission::ManageProjects, 'deploy');
    }

    public function delete(User $user, Repository $repository): bool
    {
        return $this->update($user, $repository);
    }

    /** Deploying, redeploying, rolling back and canceling. */
    public function deploy(User $user, Repository $repository): bool
    {
        return $this->update($user, $repository);
    }
}
