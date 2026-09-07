<?php

namespace App\Services;

use App\Models\AccessRequest;
use App\Models\Build;
use App\Models\Organization;
use App\Models\SignInEvent;
use App\Models\User;
use Laravel\Cashier\Subscription;

class BusinessAnalytics
{
    /**
     * Bind plan-denial telemetry for the business dashboard.
     *
     * @param  MonetizationTelemetry  $telemetry  Supplies daily feature-denial counts.
     */
    public function __construct(private readonly MonetizationTelemetry $telemetry) {}

    /**
     * Aggregate account, subscription and deployment activity over the current 30-day window.
     *
     * @return array<string, mixed> Dashboard totals, plan distribution, daily trends and generation time; MRR is estimated from configured prices.
     */
    public function snapshot(): array
    {
        $start = now()->startOfDay()->subDays(29);
        $owners = Organization::query()->with('owner.subscriptions.items')->get()->pluck('owner')->unique('id');
        $plans = $owners->map->billingPlan()->countBy();
        $paid = $owners->filter(fn (User $owner): bool => $owner->billingPlan() !== 'free');
        $estimatedMrr = $paid->sum(function (User $owner): float {
            $plan = config('billing.plans.'.$owner->billingPlan(), []);

            return $owner->billingInterval() === 'yearly'
                ? ((float) ($plan['yearly_price'] ?? 0) / 12)
                : (float) ($plan['price'] ?? 0);
        });
        $signups = User::query()->where('created_at', '>=', $start)->get(['created_at']);
        $builds = Build::query()->where('created_at', '>=', $start)->get(['status', 'created_at']);

        $trend = collect(range(0, 29))->map(function (int $offset) use ($start, $signups, $builds): array {
            $day = $start->copy()->addDays($offset);
            $denials = $this->telemetry->deniedForDate($day->toDateString());

            return [
                'date' => $day->toDateString(),
                'signups' => $signups->filter(fn (User $user): bool => $user->created_at->isSameDay($day))->count(),
                'deployments' => $builds->filter(fn (Build $build): bool => $build->created_at->isSameDay($day))->count(),
                'denials' => $denials['total'],
            ];
        });

        return [
            'totals' => [
                'users' => User::query()->count(),
                'active_users' => SignInEvent::query()->where('signed_in_at', '>=', $start)->distinct()->count('user_id'),
                'workspaces' => $owners->count(),
                'paid_workspaces' => $paid->count(),
                'conversion_rate' => $owners->isEmpty() ? 0 : round(($paid->count() / $owners->count()) * 100, 1),
                'estimated_mrr' => round($estimatedMrr, 2),
                'churned_30d' => Subscription::query()->whereBetween('ends_at', [$start, now()])->count(),
                'deployments_30d' => $builds->count(),
                'denials_30d' => $trend->sum('denials'),
                'pending_access_requests' => AccessRequest::query()->where('status', 'pending')->count(),
            ],
            'plans' => collect(config('billing.plans'))->keys()->mapWithKeys(fn (string $plan): array => [$plan => (int) ($plans[$plan] ?? 0)]),
            'trend' => $trend,
            'generated_at' => now(),
        ];
    }
}
