<?php

namespace App\Core\Http\Controllers;

use App\Core\Data\Feedback\WorkspaceFeedbackHistory;
use App\Core\Http\Requests\ReviewWorkspaceFeedbackRequest;
use App\Core\Http\Requests\StoreWorkspaceFeedbackRequest;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceFeedback;
use App\Core\Models\WorkspaceMembership;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\WorkspaceFeedbackHistoryProviderRegistry;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

final class WorkspaceFeedbackController
{
    public function index(
        Request $request,
        Workspace $workspace,
        WorkspaceProjectAccess $access,
        WorkspaceFeedbackHistoryProviderRegistry $historyProviders,
    ): View {
        [$user, $membership] = $this->context($request, $workspace, $access);
        $canReview = $access->canManageWorkspace($user, $workspace);
        $reviewableProducts = $this->reviewableProducts($membership);
        $status = in_array($request->query('status'), WorkspaceFeedback::STATUSES, true) ? $request->query('status') : null;
        $category = in_array($request->query('category'), WorkspaceFeedback::CATEGORIES, true) ? $request->query('category') : null;
        $product = in_array($request->query('product'), ['deployer', 'monitor', 'analytics'], true) ? $request->query('product') : null;

        $feedback = WorkspaceFeedback::query()
            ->where('workspace_id', $workspace->getKey())
            ->when(! $canReview, fn (Builder $query) => $query->where(function (Builder $visible) use ($user, $reviewableProducts): void {
                $visible->where('user_id', $user->getKey());
                if ($reviewableProducts !== []) {
                    $visible->orWhereIn('product', $reviewableProducts);
                }
            }))
            ->when($status, fn (Builder $query) => $query->where('status', $status))
            ->when($category, fn (Builder $query) => $query->where('category', $category))
            ->when($product, fn (Builder $query) => $query->where('product', $product))
            ->with(['submitter:id,name', 'reviewer:id,name'])
            ->latest()
            ->paginate(15)
            ->withQueryString();
        $legacyFeedback = ($product === null || $product === 'deployer')
            ? $historyProviders->get('deployer')?->forWorkspace(
                user: $user,
                workspace: $workspace,
                filters: ['status' => $status, 'category' => $category, 'limit' => 15],
                canReview: $canReview || in_array('deployer', $reviewableProducts, true),
            ) ?? new WorkspaceFeedbackHistory
            : new WorkspaceFeedbackHistory;

        return view('core::workspaces.feedback', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'feedback' => $feedback,
            'legacyFeedback' => $legacyFeedback,
            'canReview' => $canReview,
            'reviewableProducts' => $reviewableProducts,
            'status' => $status,
            'category' => $category,
            'product' => $product,
            'products' => $this->availableProducts($access, $membership),
        ]);
    }

    public function store(StoreWorkspaceFeedbackRequest $request, Workspace $workspace, WorkspaceProjectAccess $access): RedirectResponse
    {
        [$user, $membership] = $this->context($request, $workspace, $access);
        $data = $request->validated();
        abort_unless($access->hasProductAccess($membership, $data['product']), 403);

        WorkspaceFeedback::query()->create([
            ...$data,
            'workspace_id' => $workspace->getKey(),
            'user_id' => $user->getKey(),
            'status' => 'open',
        ]);

        return to_route('core.workspace.feedback.index', $workspace)->with('success', __('Feedback sent to the Buildpusher team.'));
    }

    public function update(ReviewWorkspaceFeedbackRequest $request, Workspace $workspace, WorkspaceFeedback $feedback, WorkspaceProjectAccess $access): RedirectResponse
    {
        [$user, $membership] = $this->context($request, $workspace, $access);

        $feedback = $workspace->feedback()->findOrFail($feedback->getKey());
        abort_unless($access->canManageWorkspace($user, $workspace)
            || in_array($feedback->product, $this->reviewableProducts($membership), true), 403);
        $data = $request->validated();
        $feedback->forceFill([
            'status' => $data['status'],
            'review_response' => $data['review_response'] ?? null,
            'reviewed_by_user_id' => $user->getKey(),
            'resolved_at' => in_array($data['status'], ['resolved', 'closed'], true) ? now() : null,
        ])->save();

        return to_route('core.workspace.feedback.index', $workspace)->with('success', __('Feedback review updated.'));
    }

    public function destroy(Request $request, Workspace $workspace, WorkspaceFeedback $feedback, WorkspaceProjectAccess $access): RedirectResponse
    {
        [$user, $membership] = $this->context($request, $workspace, $access);
        $feedback = $workspace->feedback()->findOrFail($feedback->getKey());
        $canReview = $access->canManageWorkspace($user, $workspace)
            || in_array($feedback->product, $this->reviewableProducts($membership), true);
        abort_unless($canReview || (string) $feedback->user_id === (string) $user->getKey(), 403);
        $feedback->delete();

        return to_route('core.workspace.feedback.index', $workspace)->with('success', __('Feedback removed.'));
    }

    /** @return array{PlatformUser, WorkspaceMembership} */
    private function context(Request $request, Workspace $workspace, WorkspaceProjectAccess $access): array
    {
        $user = $request->user('platform');
        abort_unless($user instanceof PlatformUser, 401);
        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        return [$user, $membership];
    }

    /** @return list<string> */
    private function availableProducts(WorkspaceProjectAccess $access, WorkspaceMembership $membership): array
    {
        return array_values(array_filter(
            ['deployer', 'monitor', 'analytics'],
            fn (string $product): bool => $access->hasProductAccess($membership, $product),
        ));
    }

    /** @return list<string> */
    private function reviewableProducts(WorkspaceMembership $membership): array
    {
        return WorkspaceProductAccess::query()
            ->where('membership_id', $membership->getKey())
            ->whereIn('role', ['owner', 'admin'])
            ->where('status', 'active')
            ->whereNull('revoked_at')
            ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->whereIn('product', ['deployer', 'monitor', 'analytics'])
            ->pluck('product')
            ->all();
    }

    /** @return Collection<int, Workspace> */
    private function workspacesFor(PlatformUser $user)
    {
        return Workspace::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->whereHas('memberships', fn (Builder $query) => $query->currentlyActive()->where('user_id', $user->getKey()))
            ->orderBy('name')
            ->get();
    }
}
