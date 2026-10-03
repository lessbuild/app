<?php

declare(strict_types=1);

namespace App\Filament\Widgets;

use App\Filament\Pages\Health;
use App\Filament\Pages\Queues;
use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Resources\Feedback\FeedbackResource;
use App\Filament\Resources\Users\UserResource;
use App\Models\AccessRequest;
use App\Models\Account;
use App\Models\Feedback;
use App\Models\User;
use App\Services\Admin\SystemHealth;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Queue\Failed\FailedJobProviderInterface;

/** The admin dashboard's headline numbers: people and accounts, and what needs attention. */
final class PlatformOverview extends StatsOverviewWidget
{
    /**
     * Rendered with the page rather than after it, since it's quick.
     *
     * @var bool
     */
    protected static bool $isLazy = false;

    /**
     * Where it sits on the dashboard.
     *
     * @var int|null
     */
    protected static ?int $sort = 1;

    /**
     * Get the numbers, each linking to where it can be dealt with.
     *
     * @return array<int, Stat>
     */
    protected function getStats(): array
    {
        $failing = collect(app(SystemHealth::class)->checks())->where('passed', false)->count();
        $failedJobs = count(app(FailedJobProviderInterface::class)->all());
        $waiting = AccessRequest::query()->where('status', 'pending')->count();
        $feedback = Feedback::query()->whereNull('resolved_at')->count();

        return [
            Stat::make(__('People'), number_format(User::query()->count()))->description(__(':count new in 30 days', ['count' => User::query()->where('created_at', '>=', now()->subDays(30))->count()]))->url(UserResource::getUrl()),
            Stat::make(__('Accounts'), number_format(Account::query()->count()))->url(AccountResource::getUrl()),
            Stat::make(__('Health checks'), $failing === 0 ? __('All pass') : trans_choice(':count fails|:count fail', $failing))->color($failing === 0 ? 'success' : 'danger')->url(Health::getUrl()),
            Stat::make(__('Failed jobs'), number_format($failedJobs))->color($failedJobs === 0 ? 'success' : 'warning')->url(Queues::getUrl()),
            Stat::make(__('Access requests waiting'), number_format($waiting))->color($waiting === 0 ? 'gray' : 'warning')->url(AccessRequestResource::getUrl()),
            Stat::make(__('Open feedback'), number_format($feedback))->color($feedback === 0 ? 'gray' : 'warning')->url(FeedbackResource::getUrl()),
        ];
    }
}
