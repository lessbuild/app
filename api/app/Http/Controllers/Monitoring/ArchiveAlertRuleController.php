<?php

declare(strict_types=1);

namespace App\Http\Controllers\Monitoring;

use App\Actions\Monitoring\ArchiveAlertRule;
use App\Models\AlertRule;
use App\Models\Project;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class ArchiveAlertRuleController
{
    /**
     * Archive an alert rule, if it hasn't changed since the page was opened.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  Project  $project
     * @param  AlertRule  $rule
     * @param  ArchiveAlertRule  $archive
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, Project $project, AlertRule $rule, ArchiveAlertRule $archive): JsonResponse
    {
        $version = (int) $request->validate(['version' => ['required', 'integer', 'min:0']])['version'];
        $archive->handle($rule, $user, $version);

        return response()->json(['redirect' => route('monitoring.rules', $project, false), 'message' => __(':rule was archived.', ['rule' => $rule->name])]);
    }
}
