<?php

namespace App\Http\Controllers;

use App\Actions\Repository\PromoteBuildAction;
use App\Data\BuildPromotionResult;
use App\Http\Requests\PromoteBuildRequest;
use App\Models\Build;
use App\Models\Environment;
use Illuminate\Http\RedirectResponse;

class BuildPromotionController extends Controller
{
    /**
     * Validate a target environment and optional promotion note within the source workspace.
     *
     * @return RedirectResponse The queued build, or the reason promotion could not proceed.
     */
    public function __invoke(PromoteBuildRequest $request, Build $build, PromoteBuildAction $promote): RedirectResponse
    {
        $organization = $build->repository->organization;
        $target = Environment::query()->whereKey($request->targetEnvironmentId())
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->firstOrFail();
        $result = $promote->handle($build, $target, $request->user(), $request->promotionNote());

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
