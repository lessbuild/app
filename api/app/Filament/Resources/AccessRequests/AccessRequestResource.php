<?php

declare(strict_types=1);

namespace App\Filament\Resources\AccessRequests;

use App\Actions\AccessRequests\ReviewAccessRequest;
use App\Filament\Resources\AccessRequests\Pages\ManageAccessRequests;
use App\Filament\Support\CurrentAdmin;
use App\Models\AccessRequest;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** People asking to join while registration is by invitation. Inviting someone emails them a one-time sign-up link. */
final class AccessRequestResource extends Resource
{
    /**
     * The access requests' model.
     *
     * @var class-string<AccessRequest>|null
     */
    protected static ?string $model = AccessRequest::class;

    /**
     * The sidebar label.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Access requests';

    /**
     * The name of one record.
     *
     * @var string|null
     */
    protected static ?string $modelLabel = 'access request';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelopeOpen;

    /**
     * The sidebar group.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Growth';

    /**
     * The position in the group.
     *
     * @var int|null
     */
    protected static ?int $navigationSort = 20;

    /**
     * Access is decided by the panel, not the customer-facing policies.
     *
     * @var bool
     */
    protected static bool $shouldSkipAuthorization = true;

    /**
     * What each status is called.
     *
     * @var array<string, string>
     */
    public const STATUS_LABELS = ['pending' => 'Waiting', 'contacted' => 'Contacted', 'invited' => 'Invited', 'accepted' => 'Signed up', 'declined' => 'Declined'];

    /**
     * Show how many requests are waiting in the sidebar.
     *
     * @return string|null
     */
    public static function getNavigationBadge(): ?string
    {
        $waiting = AccessRequest::query()->where('status', 'pending')->count();

        return $waiting > 0 ? (string) $waiting : null;
    }

    /**
     * Build the requests table, oldest first, with a Review action for requests that are still open.
     *
     * @param  Table  $table
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('reviewer'))
            ->defaultSort('created_at')
            ->columns([
                TextColumn::make('name')->label(__('Person'))->description(fn (AccessRequest $record): string => $record->email),
                TextColumn::make('company')->label(__('Company'))->placeholder('—')->description(fn (AccessRequest $record): string => __('team of :size', ['size' => $record->team_size ?? '?'])),
                TextColumn::make('use_case')->label(__('What for'))->wrap()->lineClamp(4),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (string $state): string => __(self::STATUS_LABELS[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        'invited' => 'info', 'accepted' => 'success', 'declined' => 'gray', 'contacted' => 'warning', default => 'primary'
                    })
                    ->description(fn (AccessRequest $record): ?string => $record->reviewed_at ? (string) __('by :name :when', ['name' => $record->reviewer->name ?? __('someone'), 'when' => $record->reviewed_at->diffForHumans()]) : null),
                TextColumn::make('created_at')->label(__('Asked'))->since()->sortable(),
            ])
            ->recordActions([
                Action::make('review')
                    ->label(__('Review'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (AccessRequest $record): bool => $record->status !== 'accepted')
                    ->fillForm(fn (AccessRequest $record): array => ['status' => $record->status, 'review_notes' => $record->review_notes, 'resend' => false])
                    ->schema([
                        Select::make('status')->label(__('Status'))->required()->native(false)->live()
                            ->options(array_map(__(...), array_intersect_key(self::STATUS_LABELS, array_flip(['pending', 'contacted', 'invited', 'declined']))))
                            ->helperText(__('Inviting someone emails them a one-time sign-up link.')),
                        Textarea::make('review_notes')->label(__('Notes (admins only)'))->rows(3)->maxLength(2000),
                        Toggle::make('resend')->label(__('Send a new invitation'))
                            ->visible(fn (Get $get, AccessRequest $record): bool => $get('status') === 'invited' && $record->status === 'invited'),
                    ])
                    ->action(function (AccessRequest $record, array $data): void {
                        app(ReviewAccessRequest::class)->handle(CurrentAdmin::user(), $record, (string) $data['status'], filled($data['review_notes'] ?? null) ? (string) $data['review_notes'] : null, (bool) ($data['resend'] ?? false));
                        Notification::make()->success()->title($data['status'] === 'invited' ? __('Invitation sent.') : __('Saved.'))->send();
                    }),
            ]);
    }

    /**
     * Get the resource's page: one list with a tab per status.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ManageAccessRequests::route('/')];
    }
}
