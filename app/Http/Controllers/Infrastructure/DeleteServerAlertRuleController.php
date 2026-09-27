<?php

declare(strict_types=1);

namespace App\Http\Controllers\Infrastructure;

use App\Actions\Infrastructure\DeleteServerAlertRule;
use App\Models\Project;
use App\Models\ServerAlertRule;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteServerAlertRuleController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, string $server, string $rule, DeleteServerAlertRule $delete): RedirectResponse
    {
        $delete->handle($project->account, $user, ServerAlertRule::query()->where('account_id', $project->account_id)->findOrFail((int) $rule));

        return to_route('infrastructure.servers.show', [$project, (int) $server])->withFragment('alerts')->with('status', __('Alert removed.'));
    }
}
