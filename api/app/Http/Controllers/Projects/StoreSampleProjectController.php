<?php

declare(strict_types=1);

namespace App\Http\Controllers\Projects;

use App\Actions\Projects\CreateSampleProject;
use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class StoreSampleProjectController
{
    /**
     * Create a sample project full of made-up data and open it.
     *
     * @param  User  $user
     * @param  Account  $account
     * @param  CreateSampleProject  $sample
     * @return RedirectResponse
     */
    public function __invoke(#[CurrentUser] User $user, #[CurrentAccount] Account $account, CreateSampleProject $sample): RedirectResponse
    {
        $project = $sample->handle($user, $account);

        return to_route('analytics.overview', $project)->with('status', __('Here’s a sample project with a month of made-up visits and a day of made-up errors. Look around, then delete it from its settings.'));
    }
}
