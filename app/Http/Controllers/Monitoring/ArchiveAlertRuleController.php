<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveAlertRule;
use App\Models\Project;
use App\Models\User;
use App\Queries\Monitoring\ProjectAlertRulesQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class ArchiveAlertRuleController
{
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, string $rule, ProjectAlertRulesQuery $rules, ArchiveAlertRule $archive): RedirectResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $target = $rules->find($project, $rule);
        $archive->handle($target, $user, $version);

        return to_route('monitoring.rules', $project)->with('status', __(':rule was archived.', ['rule' => $target->name]));
    }
}
