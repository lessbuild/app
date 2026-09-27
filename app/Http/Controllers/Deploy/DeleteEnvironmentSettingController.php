<?php

declare(strict_types=1);

namespace App\Http\Controllers\Deploy;

use App\Actions\Deploy\DeleteEnvironmentSetting;
use App\Models\Environment;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;

final class DeleteEnvironmentSettingController
{
    public function __invoke(#[CurrentUser] User $user, Project $project, Environment $environment, string $kind, string $setting, DeleteEnvironmentSetting $delete): RedirectResponse
    {
        $record = match ($kind) {
            'variables' => $environment->variables(),
            'processes' => $environment->processes(),
            default => $environment->resources(),
        };
        $delete->handle($user, $record->findOrFail((int) $setting));

        return to_route('deploy.environments.show', [$project, $environment])->withFragment($kind)->with('status', __('Removed. The server changes with the next deploy.'));
    }
}
