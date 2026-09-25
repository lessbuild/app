<?php

namespace App\Modules\Deployer\Services\Core;

use App\Core\Contracts\WorkspaceFeedbackHistoryProvider;
use App\Core\Data\Feedback\WorkspaceFeedbackHistory;
use App\Core\Data\Feedback\WorkspaceFeedbackHistoryEntry;
use App\Core\Models\LegacyIdentityMap;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Services\LegacyIdentityResolver;
use App\Core\Services\PlatformProductRouteLinks;
use App\Core\Services\WorkspaceProjectAccess;
use App\Modules\Deployer\Models\Organization;
use App\Modules\Deployer\Models\ProductFeedback;
use App\Modules\Deployer\Models\User;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;

/** Reads legacy feedback through explicit workspace and user mappings; source rows remain unchanged. */
final class DeployerWorkspaceFeedbackHistoryProvider implements WorkspaceFeedbackHistoryProvider
{
    public function __construct(
        private readonly LegacyIdentityResolver $identities,
        private readonly WorkspaceProjectAccess $workspaceAccess,
        private readonly PlatformProductRouteLinks $productLinks,
    ) {}

    public function forWorkspace(
        PlatformUser $user,
        Workspace $workspace,
        array $filters,
        bool $canReview,
    ): WorkspaceFeedbackHistory {
        try {
            $membership = $this->workspaceAccess->activeMembership($user, $workspace);
            if ($membership === null || ! $this->workspaceAccess->hasProductAccess($membership, 'deployer')) {
                return new WorkspaceFeedbackHistory;
            }

            $organizationIds = LegacyIdentityMap::query()
                ->where('source_product', 'deployer')
                ->where('source_entity', 'organization')
                ->where('canonical_entity', 'workspace')
                ->where('canonical_id', (string) $workspace->getKey())
                ->where('status', 'reconciled')
                ->pluck('source_id')
                ->map(static fn ($id): string => (string) $id)
                ->unique()
                ->values();
            $userIds = $this->identities->sourceIdsFor($user, 'deployer');

            if ($organizationIds->count() !== 1 || count($userIds) !== 1) {
                return new WorkspaceFeedbackHistory;
            }

            $organization = Organization::query()->find($organizationIds->sole());
            $productUser = User::query()->find($userIds[0]);

            if (! $organization instanceof Organization
                || ! $productUser instanceof User
                || ! $organization->permits($productUser, 'view')) {
                return new WorkspaceFeedbackHistory;
            }

            $limit = max(1, min(50, (int) ($filters['limit'] ?? 15)));
            $query = ProductFeedback::query()
                ->where('organization_id', $organization->getKey())
                ->when(! $canReview, fn ($query) => $query->where('user_id', $productUser->getKey()))
                ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
                ->when($filters['category'] ?? null, fn ($query, string $category) => $query->where('category', $category))
                ->with('submitter:id,name')
                ->orderByDesc('created_at')
                ->orderByDesc('id');
            $rows = collect();
            $cursor = null;

            do {
                $pageQuery = clone $query;
                if ($cursor !== null) {
                    $pageQuery->where(function ($ordered) use ($cursor): void {
                        $ordered->where('created_at', '<', $cursor['created_at'])
                            ->orWhere(function ($tie) use ($cursor): void {
                                $tie->where('created_at', $cursor['created_at'])
                                    ->where('id', '<', $cursor['id']);
                            });
                    });
                }

                $page = $pageQuery->limit($limit)->get();
                if ($page->isEmpty()) {
                    break;
                }

                $sourceIds = $page->map(static fn (ProductFeedback $feedback): string => (string) $feedback->getKey());
                $importedIds = LegacyIdentityMap::query()
                    ->where('source_product', 'deployer')
                    ->where('source_entity', 'product_feedback')
                    ->where('canonical_entity', 'workspace_feedback')
                    ->where('status', 'reconciled')
                    ->where('metadata->workspace_id', (string) $workspace->getKey())
                    ->whereIn('source_id', $sourceIds)
                    ->pluck('source_id')
                    ->map(static fn ($id): string => (string) $id)
                    ->all();

                $rows = $rows->concat($page->reject(
                    static fn (ProductFeedback $feedback): bool => in_array(
                        (string) $feedback->getKey(),
                        $importedIds,
                        true,
                    ),
                ));

                $last = $page->last();
                $cursor = [
                    'created_at' => $last->getRawOriginal('created_at'),
                    'id' => (string) $last->getKey(),
                ];
            } while ($page->count() === $limit && $rows->count() < $limit);

            $rows = $rows->take($limit);

            return new WorkspaceFeedbackHistory(
                entries: $rows->map(fn (ProductFeedback $feedback): WorkspaceFeedbackHistoryEntry => new WorkspaceFeedbackHistoryEntry(
                    sourceId: (string) $feedback->getKey(),
                    product: 'deployer',
                    category: (string) $feedback->category,
                    severity: (string) $feedback->severity,
                    status: (string) $feedback->status,
                    title: (string) $feedback->title,
                    description: (string) $feedback->description,
                    reproductionSteps: $feedback->reproduction_steps,
                    reviewResponse: $feedback->review_response,
                    page: $feedback->page,
                    submitterName: $feedback->submitter?->name,
                    createdAt: $feedback->created_at->toImmutable(),
                ))->all(),
                manageUrl: $canReview
                    ? $this->productLinks->to('deployer', 'feedback.index', [
                        'organization_id' => (string) $organization->getKey(),
                    ])
                    : null,
            );
        } catch (DecryptException|LostConnectionException|QueryException) {
            return new WorkspaceFeedbackHistory(available: false);
        }
    }
}
