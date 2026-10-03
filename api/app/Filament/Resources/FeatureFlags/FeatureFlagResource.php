<?php

declare(strict_types=1);

namespace App\Filament\Resources\FeatureFlags;

use App\Actions\Admin\DeleteFeatureFlag;
use App\Actions\Admin\SaveFeatureFlag;
use App\Filament\Resources\FeatureFlags\Pages\ManageFeatureFlags;
use App\Filament\Support\CurrentAdmin;
use App\Models\Account;
use App\Models\FeatureFlag;
use BackedEnum;
use Closure;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Feature flags: switches the code reads with FeatureFlags::enabled('key', $account). New flags start off. */
final class FeatureFlagResource extends Resource
{
    /**
     * The flags' model.
     *
     * @var class-string<FeatureFlag>|null
     */
    protected static ?string $model = FeatureFlag::class;

    /**
     * The sidebar label.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'Feature flags';

    /**
     * The name of one record.
     *
     * @var string|null
     */
    protected static ?string $modelLabel = 'feature flag';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

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
    protected static ?int $navigationSort = 50;

    /**
     * Access is decided by the panel (platform admins with a second factor), not the customer-facing policies.
     *
     * @var bool
     */
    protected static bool $shouldSkipAuthorization = true;

    /**
     * What each state means.
     *
     * @var array<string, string>
     */
    public const STATE_LABELS = ['off' => 'Off', 'on' => 'On for everyone', 'accounts' => 'On for chosen accounts'];

    /**
     * Build the flag form: a new flag gets a key and description; an existing one a state, description and accounts.
     *
     * @param  Schema  $schema
     * @return Schema
     */
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('key')->label(__('Key'))->placeholder('deploy.new-scheduler')->required()->maxLength(60)
                ->regex('/\A[a-z0-9][a-z0-9.-]*\z/')->unique('feature_flags', 'key')->visibleOn('create'),
            Select::make('state')->label(__('State'))->options(array_map(__(...), self::STATE_LABELS))->required()->native(false)->visibleOn('edit'),
            TextInput::make('description')->label(__('What it switches'))->required()->maxLength(255),
            Textarea::make('account_ids')->label(__('Account IDs (for “chosen accounts”)'))->helperText(__('One a line. Find IDs under Accounts.'))
                ->rows(3)->maxLength(20000)->visibleOn('edit')
                ->formatStateUsing(fn (mixed $state): string => is_array($state) ? implode(PHP_EOL, $state) : (string) $state)
                ->rule(fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                    $ids = self::accountIds($value);
                    if (Account::query()->whereKey($ids)->count() !== count(array_unique($ids))) {
                        $fail(__('Every line must be an existing account ID.'));
                    }
                }),
        ])->columns(1);
    }

    /**
     * Build the flags table, with editing and deleting through the admin Actions (so each lands in the trail).
     *
     * @param  Table  $table
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')->label(__('Key'))->fontFamily('mono')->searchable()->sortable(),
                TextColumn::make('state')->label(__('State'))->badge()
                    ->formatStateUsing(fn (string $state): string => __(self::STATE_LABELS[$state] ?? $state))
                    ->color(fn (string $state): string => match ($state) {
                        'on' => 'success', 'accounts' => 'warning', default => 'gray'
                    }),
                TextColumn::make('description')->label(__('What it switches'))->wrap(),
                TextColumn::make('account_ids')->label(__('Accounts'))->state(fn (FeatureFlag $record): int => count($record->account_ids ?? [])),
                TextColumn::make('updated_at')->label(__('Changed'))->since()->sortable()
                    ->description(fn (FeatureFlag $record): string => $record->editor->name ?? ''),
            ])
            ->recordActions([
                EditAction::make()->using(fn (FeatureFlag $record, array $data): FeatureFlag => app(SaveFeatureFlag::class)->handle(CurrentAdmin::user(), $record, [
                    'state' => (string) $data['state'],
                    'description' => (string) $data['description'],
                    'account_ids' => self::accountIds($data['account_ids'] ?? ''),
                ])),
                DeleteAction::make()->using(function (FeatureFlag $record): bool {
                    app(DeleteFeatureFlag::class)->handle(CurrentAdmin::user(), $record);

                    return true;
                }),
            ]);
    }

    /**
     * Read account IDs typed one a line (or separated by commas or spaces).
     *
     * @param  mixed  $value
     * @return list<string>
     */
    public static function accountIds(mixed $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[\s,]+/', is_string($value) ? $value : '') ?: [])));
    }

    /**
     * Get the resource's page: one list with create and edit in modals.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ManageFeatureFlags::route('/')];
    }
}
