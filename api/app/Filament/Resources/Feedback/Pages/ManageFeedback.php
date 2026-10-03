<?php

declare(strict_types=1);

namespace App\Filament\Resources\Feedback\Pages;

use App\Filament\Resources\Feedback\FeedbackResource;
use App\Models\Feedback;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

final class ManageFeedback extends ManageRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<FeedbackResource>
     */
    protected static string $resource = FeedbackResource::class;

    /**
     * Get the tabs: open feedback first, and what's been resolved.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        return [
            'open' => Tab::make(__('Open'))->badge(Feedback::query()->whereNull('resolved_at')->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('resolved_at')),
            'resolved' => Tab::make(__('Resolved'))->badge(Feedback::query()->whereNotNull('resolved_at')->count())
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNotNull('resolved_at')),
        ];
    }

    /**
     * Get the header's buttons: none, since feedback comes from the app.
     *
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
