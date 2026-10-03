<?php

declare(strict_types=1);

namespace App\Filament\Resources\Feedback;

use App\Actions\Feedback\ResolveFeedback;
use App\Actions\Roadmap\LinkFeedbackToFeatureRequest;
use App\Actions\Roadmap\SaveFeatureRequest;
use App\Filament\Resources\FeatureRequests\FeatureRequestResource;
use App\Filament\Resources\Feedback\Pages\ManageFeedback;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\CurrentAdmin;
use App\Models\FeatureRequest;
use App\Models\Feedback;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use UnitEnum;

/** What people sent from the Send feedback form. Messages are encrypted at rest. */
final class FeedbackResource extends Resource
{
    /**
     * The feedback's model.
     *
     * @var class-string<Feedback>|null
     */
    protected static ?string $model = Feedback::class;

    /**
     * The sidebar label.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Feedback';

    /**
     * The name of one record.
     *
     * @var string|null
     */
    protected static ?string $modelLabel = 'feedback';

    /**
     * The name of several records.
     *
     * @var string|null
     */
    protected static ?string $pluralModelLabel = 'feedback';

    /**
     * The URL segment.
     *
     * @var string|null
     */
    protected static ?string $slug = 'feedback';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

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
    protected static ?int $navigationSort = 30;

    /**
     * Access is decided by the panel, not the customer-facing policies.
     *
     * @var bool
     */
    protected static bool $shouldSkipAuthorization = true;

    /**
     * Show how much feedback is waiting in the sidebar.
     *
     * @return string|null
     */
    public static function getNavigationBadge(): ?string
    {
        $open = Feedback::query()->whereNull('resolved_at')->count();

        return $open > 0 ? (string) $open : null;
    }

    /**
     * Build the feedback table, newest first, with resolving and writing it up for the roadmap.
     *
     * @param  Table  $table
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['user', 'account', 'resolver', 'featureRequest']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('kind')->label(__('Kind'))->badge()->formatStateUsing(fn (string $state): string => __(Feedback::KINDS[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        'problem' => 'danger', 'question' => 'warning', 'praise' => 'success', default => 'info'
                    }),
                TextColumn::make('message')->label(__('Message'))->wrap()->description(fn (Feedback $record): ?string => $record->page ? (string) __('From :page', ['page' => $record->page]) : null),
                TextColumn::make('user.name')->label(__('From'))->placeholder(__('Deleted user'))
                    ->url(fn (Feedback $record): ?string => $record->user ? UserResource::getUrl('view', ['record' => $record->user]) : null)
                    ->description(fn (Feedback $record): ?string => $record->account?->name),
                TextColumn::make('featureRequest.title')->label(__('On the roadmap'))->placeholder('—')->url(fn (Feedback $record): ?string => $record->featureRequest ? FeatureRequestResource::getUrl() : null),
                TextColumn::make('created_at')->label(__('Sent'))->since()->sortable()
                    ->description(fn (Feedback $record): ?string => $record->resolved_at ? (string) __('Resolved by :name :when', ['name' => $record->resolver->name ?? __('someone'), 'when' => $record->resolved_at->diffForHumans()]) : null),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('Kind'))->options(array_map(__(...), Feedback::KINDS)),
            ])
            ->recordActions([
                self::roadmapAction(),
                Action::make('resolve')
                    ->label(fn (Feedback $record): string => $record->resolved_at ? __('Open again') : __('Mark resolved'))
                    ->icon(fn (Feedback $record): Heroicon => $record->resolved_at ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedCheck)
                    ->action(function (Feedback $record): void {
                        app(ResolveFeedback::class)->handle(CurrentAdmin::user(), $record, $record->resolved_at === null);
                    }),
            ]);
    }

    /**
     * Build "Add to roadmap": link the feedback to a request already on the roadmap, or write a new one. The sender's
     * vote is counted either way, and the feedback is resolved.
     *
     * @return Action
     */
    private static function roadmapAction(): Action
    {
        return Action::make('roadmap')
            ->label(__('Add to roadmap'))
            ->icon(Heroicon::OutlinedMap)
            ->visible(fn (Feedback $record): bool => $record->featureRequest === null)
            ->modalDescription(__('Link it to a request already on the roadmap, or write a new one. The sender’s vote is counted either way, and the feedback is resolved.'))
            ->schema([
                Select::make('feature_request_id')->label(__('Existing request'))->placeholder(__('New request'))->native(false)->live()
                    ->helperText(__('Leave on “New request” to write one below.'))
                    ->options(fn (): array => FeatureRequest::query()->whereNotIn('status', ['shipped', 'declined'])->orderBy('title')->get()
                        ->mapWithKeys(fn (FeatureRequest $request): array => [$request->id => $request->title.' ('.$request->statusLabel().')'])->all()),
                ...array_map(fn ($field) => $field->hidden(fn (Get $get): bool => filled($get('feature_request_id'))), FeatureRequestResource::fields(false)),
            ])
            ->action(function (Feedback $record, array $data): void {
                $admin = CurrentAdmin::user();
                if (filled($data['feature_request_id'] ?? null)) {
                    app(LinkFeedbackToFeatureRequest::class)->handle($admin, FeatureRequest::query()->findOrFail((int) $data['feature_request_id']), $record);
                } else {
                    $details = FeatureRequestResource::details($data);
                    if ($details['title'] === '') {
                        throw ValidationException::withMessages(['title' => __('Give the new request a title, or pick an existing one.')]);
                    }
                    app(SaveFeatureRequest::class)->handle($admin, null, $details, $record);
                }
                Notification::make()->success()->title(__('Added to the roadmap. The sender’s vote is counted.'))->send();
            });
    }

    /**
     * Get the resource's page: one list with Open and Resolved tabs.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ManageFeedback::route('/')];
    }
}
