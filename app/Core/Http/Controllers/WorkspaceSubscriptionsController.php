<?php

namespace App\Core\Http\Controllers;

use App\Core\Contracts\ProductPlanResolver;
use App\Core\Enums\ProductKey;
use App\Core\Models\CurrentProductSubscription;
use App\Core\Models\PlatformUser;
use App\Core\Models\Workspace;
use App\Core\Models\WorkspaceProductAccess;
use App\Core\Services\Billing\PlatformProductBillingLinks;
use App\Core\Services\Identity\ResolvePlatformUser;
use App\Core\Services\Identity\EnsureProductWorkspaceMapping;
use App\Core\Services\WorkspaceProjectAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Throwable;

final class WorkspaceSubscriptionsController
{
    public function __invoke(
        Request $request,
        Workspace $workspace,
        ResolvePlatformUser $platformUsers,
        WorkspaceProjectAccess $access,
        ProductPlanResolver $plans,
        PlatformProductBillingLinks $billingLinks,
        EnsureProductWorkspaceMapping $productWorkspaces,
    ): View {
        $principal = $request->user();
        abort_unless($principal !== null, 401);

        $user = $platformUsers->resolve($principal, 'deployer');
        abort_if($user === null, 403);

        $membership = $access->activeMembership($user, $workspace);
        abort_if($membership === null, 404);

        $canManageBilling = $access->canManageBilling($user, $workspace);
        $hasSubscriptionTables = Schema::connection('core')->hasTable('current_product_subscriptions')
            && Schema::connection('core')->hasTable('product_subscriptions');
        $subscriptions = $canManageBilling && $hasSubscriptionTables
            ? CurrentProductSubscription::query()
                ->where('workspace_id', $workspace->getKey())
                ->with('subscription')
                ->get()
                ->keyBy('product')
            : collect();
        $planResolutions = collect();
        $productAccessCounts = collect();
        $memberProductAccess = collect();
        $billingManagementLinks = collect();
        $billingLinkIssues = collect();

        foreach (ProductKey::cases() as $product) {
            $productKey = $product->value;

            $productAccessCounts->put($productKey, $canManageBilling
                ? WorkspaceProductAccess::query()
                    ->where('product', $productKey)
                    ->where('status', 'active')
                    ->whereNull('revoked_at')
                    ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->whereHas('membership', fn (Builder $query) => $query
                        ->where('workspace_id', $workspace->getKey())
                        ->currentlyActive())
                    ->count()
                : 0);

            $memberProductAccess->put($productKey, WorkspaceProductAccess::query()
                ->where('membership_id', $membership->getKey())
                ->where('product', $productKey)
                ->where('status', 'active')
                ->whereNull('revoked_at')
                ->where(fn (Builder $query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->exists());

            if ($canManageBilling && $hasSubscriptionTables) {
                $planResolutions->put($productKey, $plans->resolve((string) $workspace->getKey(), $product));

                if ($billingLinks->supports($productKey)) {
                    try {
                        $productWorkspaceId = $productWorkspaces->handle($productKey, $workspace);
                        $billingManagementLinks->put($productKey, $billingLinks->for($productKey, $productWorkspaceId));
                    } catch (Throwable $exception) {
                        report($exception);
                        $billingLinkIssues->put($productKey, __('Billing setup needs a workspace or identity review before it can open.'));
                    }
                }
            }
        }

        return view('core::workspaces.subscriptions', [
            'user' => $user,
            'workspace' => $workspace,
            'workspaces' => $this->workspacesFor($user),
            'products' => collect(ProductKey::cases())->mapWithKeys(fn (ProductKey $product): array => [
                $product->value => [
                    'label' => (string) config("platform.products.{$product->value}.label", str($product->value)->headline()),
                    'description' => match ($product) {
                        ProductKey::Deployer => __('Builds and deployments'),
                        ProductKey::Monitor => __('Checks and incidents'),
                        ProductKey::Analytics => __('Traffic and site reports'),
                    },
                ],
            ]),
            'subscriptions' => $subscriptions,
            'planResolutions' => $planResolutions,
            'productAccessCounts' => $productAccessCounts,
            'memberProductAccess' => $memberProductAccess,
            'billingManagementLinks' => $billingManagementLinks,
            'billingLinkIssues' => $billingLinkIssues,
            'canManageBilling' => $canManageBilling,
            'billingDataAvailable' => $hasSubscriptionTables,
        ]);
    }

    /** @return Collection<int, Workspace> */
    private function workspacesFor(PlatformUser $user): Collection
    {
        return $user->workspaceMemberships()
            ->currentlyActive()
            ->whereHas('workspace', fn (Builder $query) => $query
                ->where('status', 'active')
                ->whereNull('archived_at'))
            ->with('workspace')
            ->get()
            ->pluck('workspace')
            ->filter()
            ->values();
    }
}
