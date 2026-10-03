<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureRequests;

use App\Actions\Roadmap\SaveFeatureRequest;
use App\Filament\Resources\FeatureRequests\Pages\ManageFeatureRequests;
use App\Filament\Support\CurrentAdmin;
use App\Models\FeatureRequest;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** The public roadmap's requests: what's being built, what's next, and what people are asking for. */
final class FeatureRequestResource extends Resource
{
    /**
     * The roadmap requests' model.
     *
     * @var class-string<FeatureRequest>|null
     */
    protected static ?string $model = FeatureRequest::class;

    /**
     * The sidebar label.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Roadmap';

    /**
     * The name of one record.
     *
     * @var string|null
     */
    protected static ?string $modelLabel = 'roadmap request';

    /**
     * The URL segment.
     *
     * @var string|null
     */
    protected static ?string $slug = 'roadmap';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

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
    protected static ?int $navigationSort = 40;

    /**
     * Access is decided by the panel, not the customer-facing policies.
     *
     * @var bool
     */
    protected static bool $shouldSkipAuthorization = true;

    /**
     * Build the request form: its public title, description and status.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::fields(true))->columns(1);
    }

    /**
     * Get the fields of a roadmap request, shared with "Add to roadmap" on feedback.
     *
     * @param  bool  $titleRequired  whether the title must be filled (not when an existing request can be picked instead)
     * @return list<TextInput|Textarea|Select>
     */
    public static function fields(bool $titleRequired): array
    {
        return [
            TextInput::make('title')->label(__('Public title'))->maxLength(120)->required($titleRequired),
            Textarea::make('description')->label(__('Public description'))->helperText(__('Optional. Shown on the roadmap; don’t quote private feedback.'))->rows(3)->maxLength(2000),
            Select::make('status')->label(__('Status'))->options(array_map(__(...), FeatureRequest::STATUSES))->default('under_review')->required()->native(false),
        ];
    }

    /**
     * Build the roadmap table: what's being built first, then by votes.
     *
     * @param  Table  $table
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('feedback')
                ->orderByRaw("case status when 'in_progress' then 0 when 'planned' then 1 when 'under_review' then 2 when 'shipped' then 3 else 4 end")
                ->orderByDesc('votes_count')->orderByDesc('id'))
            ->columns([
                TextColumn::make('title')->label(__('Title'))->searchable()->wrap()->description(fn (FeatureRequest $record): ?string => $record->description),
                TextColumn::make('status')->label(__('Status'))->badge()->formatStateUsing(fn (FeatureRequest $record): string => $record->statusLabel())
                    ->color(fn (string $state): string => match ($state) {
                        'in_progress' => 'info', 'planned' => 'primary', 'shipped' => 'success', 'declined' => 'gray', default => 'warning'
                    }),
                TextColumn::make('votes_count')->label(__('Votes'))->numeric()->sortable(),
                TextColumn::make('feedback_count')->label(__('Feedback'))->numeric(),
                TextColumn::make('shipped_at')->label(__('Shipped'))->date()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('Status'))->options(array_map(__(...), FeatureRequest::STATUSES)),
            ])
            ->recordActions([
                EditAction::make()->using(fn (FeatureRequest $record, array $data): FeatureRequest => app(SaveFeatureRequest::class)->handle(CurrentAdmin::user(), $record, self::details($data))),
            ]);
    }

    /**
     * Turn the form's data into what SaveFeatureRequest expects.
     *
     * @param  array<string, mixed>  $data
     * @return array{title: string, description: string|null, status: string}
     */
    public static function details(array $data): array
    {
        $description = trim((string) ($data['description'] ?? ''));

        return ['title' => trim((string) ($data['title'] ?? '')), 'description' => $description !== '' ? $description : null, 'status' => (string) ($data['status'] ?? 'under_review')];
    }

    /**
     * Get the resource's page: one list with create and edit in modals.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ManageFeatureRequests::route('/')];
    }
}
