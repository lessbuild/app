<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureFlags\Pages;

use App\Actions\Admin\SaveFeatureFlag;
use App\Filament\Resources\FeatureFlags\FeatureFlagResource;
use App\Filament\Support\CurrentAdmin;
use App\Models\FeatureFlag;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

final class ManageFeatureFlags extends ManageRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<FeatureFlagResource>
     */
    protected static string $resource = FeatureFlagResource::class;

    /**
     * Get the header's button: a new flag, which starts off.
     *
     * @return array<int, CreateAction>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label(__('New flag'))->modalDescription(__('New flags start off.'))
                ->using(fn (array $data): FeatureFlag => app(SaveFeatureFlag::class)->handle(CurrentAdmin::user(), null, ['key' => (string) $data['key'], 'description' => (string) $data['description']])),
        ];
    }
}
