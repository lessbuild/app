<?php

namespace App\Http\Controllers;

use App\Actions\Repository\PromoteBuildAction;
use App\Data\BuildPromotionResult;
use App\Models\Build;
use App\Models\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BuildPromotionController extends Controller
{
    public function __invoke(Request $request, Build $build, PromoteBuildAction $promote): RedirectResponse
    {
        $this->authorize('view', $build);
        $organization = $build->repository->organization;
        abort_unless($organization?->permits($request->user(), 'deploy'), 403);
        $data = $request->validate([
            'target_environment_id' => ['required', 'integer'],
            'promotion_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $target = Environment::query()->whereKey($data['target_environment_id'])
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->firstOrFail();
        $result = $promote->handle($build, $target, $request->user(), $data['promotion_note'] ?? null);

        return match ($result->status) {
            BuildPromotionResult::QUEUED => redirect()->route('builds.show', $result->build)->with('success', __('Release promotion requested.')),
            BuildPromotionResult::INCOMPATIBLE => back()->with('error', __('The target must connect the same source repository and provider.')),
            BuildPromotionResult::UNAVAILABLE => back()->with('error', __('The target infrastructure and source connection must be ready.')),
            BuildPromotionResult::ACTIVE => back()->with('info', __('A deployment is already active on the target.')),
            BuildPromotionResult::BLOCKED => back()->with('error', __('The target deployment is locked or outside its maintenance window.')),
            default => back()->with('info', __('Only a successful immutable release can move to a higher environment.')),
        };
    }
}
