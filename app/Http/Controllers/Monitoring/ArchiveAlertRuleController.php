<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveAlertRule;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ArchiveAlertRuleController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertRule $rule, ArchiveAlertRule $archive): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $archive->handle($rule, $user, $version);

        return to_route('monitoring.rules', $project)->with('status', __(':rule was archived.', ['rule' => $rule->name]));
    }
}
