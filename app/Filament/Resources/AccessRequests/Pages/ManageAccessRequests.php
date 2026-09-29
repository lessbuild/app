<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequests\Pages;

use App\Filament\Resources\AccessRequests\AccessRequestResource;
use App\Models\AccessRequest;
use Filament\Resources\Pages\ManageRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

final class ManageAccessRequests extends ManageRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<AccessRequestResource>
     */
    protected static string $resource = AccessRequestResource::class;

    /**
     * Say whether registration is open, which decides whether requests still come in.
     *
     * @return string|Htmlable|null
     */
    public function getSubheading(): string|Htmlable|null
    {
        return config('platform.registration.open')
            ? __('Registration is open (REGISTRATION_OPEN), so new requests only come from the old form.')
            : __('Registration is by invitation. Inviting someone emails them a one-time sign-up link.');
    }

    /**
     * Get a tab per status, each with its count, waiting requests first.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $counts = AccessRequest::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status')->all();
        $tabs = [];
        foreach (AccessRequestResource::STATUS_LABELS as $status => $label) {
            $tabs[$status] = Tab::make(__($label))->badge((int) ($counts[$status] ?? 0))
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', $status));
        }

        return $tabs;
    }

    /**
     * Get the header's buttons: none, since requests come from the public form.
     *
     * @return array<int, never>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
