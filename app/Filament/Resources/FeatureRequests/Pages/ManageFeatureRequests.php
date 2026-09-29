<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests\Pages;

use App\Actions\Roadmap\SaveFeatureRequest;
use App\Filament\Resources\FeatureRequests\FeatureRequestResource;
use App\Filament\Support\CurrentAdmin;
use App\Models\FeatureRequest;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageFeatureRequests extends ManageRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<FeatureRequestResource>
     */
    protected static string $resource = FeatureRequestResource::class;

    /**
     * Get the header's buttons: the public roadmap, and a new request.
     *
     * @return array<int, Action|CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('public')->label(__('Public roadmap'))->url(route('roadmap'))->openUrlInNewTab()->color('gray'),
            CreateAction::make()->label(__('New request'))
                ->using(fn (array $data): FeatureRequest => app(SaveFeatureRequest::class)->handle(CurrentAdmin::user(), null, FeatureRequestResource::details($data))),
        ];
    }
}
