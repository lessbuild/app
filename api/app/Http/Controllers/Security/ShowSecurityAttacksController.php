<?php

declare(strict_types=1);

namespace App\Http\Controllers\Security;

use App\Models\Project;
use App\Models\SecurityBlock;
use App\Models\SecuritySetting;
use App\Models\User;
use App\Queries\Projects\ProjectOverviewQuery;
use App\Services\Billing\Entitlements;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;

final class ShowSecurityAttacksController
{
    /**
     * Show the addresses blocked for attacking the project's servers (active ones first, then the last 50 lifted), and
     * the blocking settings.
     *
     * @param  User  $user
     * @param  Project  $project
     * @param  ProjectOverviewQuery  $overview
     * @param  Entitlements  $entitlements
     * @return JsonResponse
     */
    public function __invoke(#[CurrentUser] User $user, Project $project, ProjectOverviewQuery $overview, Entitlements $entitlements): JsonResponse
    {
        $blocks = SecurityBlock::query()->where('project_id', $project->id)->with('server')->latest('id');
        $settings = SecuritySetting::forProject($project->id);

        return response()->json([
            'overview' => $overview->handle($project, $user),
            'active' => (clone $blocks)->whereNull('lifted_at')->where('expires_at', '>', now())->get()->map($this->block(...))->values(),
            'history' => (clone $blocks)->where(fn ($query) => $query->whereNotNull('lifted_at')->orWhere('expires_at', '<=', now()))->limit(50)->get()->map($this->block(...))->values(),
            'settings' => ['autoblock' => $settings->autoblock, 'blockHours' => $settings->block_hours, 'allowlist' => $settings->allowlist ?? []],
            'included' => $entitlements->for($project->account)->has('security.autoblock'),
            'canManage' => $user->can('manageService', [$project, 'security']),
        ]);
    }

    /**
     * Describe a block for the page.
     *
     * @param  SecurityBlock  $block
     * @return array{id: int, ip: string, reason: string, server: string, detail: string|null, createdAt: string|null, expiresAt: string}
     */
    private function block(SecurityBlock $block): array
    {
        return [
            'id' => $block->id,
            'ip' => $block->ip,
            'reason' => __(SecurityBlock::REASONS[$block->reason] ?? $block->reason),
            'server' => $block->server->display_name ?: $block->server->name,
            'detail' => $block->detail,
            'createdAt' => $block->created_at?->toIso8601String(),
            'expiresAt' => $block->expires_at->toIso8601String(),
        ];
    }
}
