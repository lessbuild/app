<?php

declare(strict_types=1);

namespace App\Actions\Deploy;

use App\Exceptions\AccountRuleViolation;
use App\Models\Preview;
use App\Models\Repository;
use App\Models\User;
use App\Services\Deploy\Previews;
use App\Support\GitRef;
use Illuminate\Support\Facades\Gate;

final class OpenBranchPreview
{
    /**
     * Create a new OpenBranchPreview instance.
     *
     * @param  Previews  $previews  Makes the preview's stack.
     */
    public function __construct(private readonly Previews $previews) {}

    /**
     * Open a preview of a branch of a repository that has previews on, closing after the chosen number of days. The
     * person must be able to deploy the repository.
     *
     * @param  User  $actor
     * @param  Repository  $source
     * @param  string  $branch
     * @param  int  $days  1 to 30
     * @return Preview
     */
    public function handle(User $actor, Repository $source, string $branch, int $days): Preview
    {
        Gate::forUser($actor)->authorize('deploy', $source);
        $branch = trim($branch);
        if (GitRef::normalize($branch) !== $branch || $branch === '' || $days < 1 || $days > 30) {
            throw new AccountRuleViolation('branch', __('Enter a branch name, and one to 30 days.'));
        }
        $result = $this->previews->openBranch($source, $branch, now()->toImmutable()->addDays($days));
        if (is_string($result)) {
            throw new AccountRuleViolation('branch', match ($result) {
                'preview_plan_required' => __('Previews come with the Pro Deploy plan and above.'),
                'preview_limit_reached' => __('The plan has no room for another preview or website. Close one first.'),
                default => __('Turn previews on for this repository and set its preview domain first.'),
            });
        }
        if ($result->status === Preview::STATUS_DEPLOYING) {
            $this->previews->reconfigure($result);
        }

        return $result;
    }
}
