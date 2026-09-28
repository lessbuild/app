<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Preview;
use App\Models\User;

/**
 * Previews follow their source repository: whoever sees it sees its previews, whoever deploys it may close them or
 * retry their cleanup, and whoever manages it may approve secrets for them.
 */
final class PreviewPolicy
{
    /**
     * Create a new PreviewPolicy instance.
     *
     * Decides preview access through the source repository's policy.
     *
     * @param  RepositoryPolicy  $repositories  The source repository's rules.
     */
    public function __construct(private readonly RepositoryPolicy $repositories) {}

    /**
     * Determine whether the user can see the preview.
     *
     * @param  User  $user
     * @param  Preview  $preview
     * @return bool
     */
    public function view(User $user, Preview $preview): bool
    {
        return $this->repositories->view($user, $preview->sourceRepository);
    }

    /**
     * Determine whether the user can close the preview or retry its cleanup.
     *
     * @param  User  $user
     * @param  Preview  $preview
     * @return bool
     */
    public function operate(User $user, Preview $preview): bool
    {
        return $this->repositories->deploy($user, $preview->sourceRepository);
    }

    /**
     * Determine whether the user can approve source-environment secrets for the preview.
     *
     * @param  User  $user
     * @param  Preview  $preview
     * @return bool
     */
    public function approveSecrets(User $user, Preview $preview): bool
    {
        return $this->repositories->update($user, $preview->sourceRepository);
    }
}
