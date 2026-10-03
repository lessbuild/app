<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Models\User;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** People: find someone by email, name or ID and see their accounts, sign-in methods and latest sign-ins. */
final class UserResource extends Resource
{
    /**
     * The people's model.
     *
     * @var class-string<User>|null
     */
    protected static ?string $model = User::class;

    /**
     * The sidebar label.
     *
     * @var string|null
     */
    protected static ?string $navigationLabel = 'People';

    /**
     * The name of one record.
     *
     * @var string|null
     */
    protected static ?string $modelLabel = 'person';

    /**
     * The name of several records.
     *
     * @var string|null
     */
    protected static ?string $pluralModelLabel = 'people';

    /**
     * The URL segment.
     *
     * @var string|null
     */
    protected static ?string $slug = 'people';

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    /**
     * The sidebar group.
     *
     * @var string|UnitEnum|null
     */
    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    /**
     * The position in the group.
     *
     * @var int|null
     */
    protected static ?int $navigationSort = 20;

    /**
     * The attribute naming a record.
     *
     * @var string|null
     */
    protected static ?string $recordTitleAttribute = 'email';

    /**
     * Access is decided by the panel, not the customer-facing policies.
     *
     * @var bool
     */
    protected static bool $shouldSkipAuthorization = true;

    /**
     * Build the people table, searchable by email, name or ID.
     *
     * @param  Table  $table
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder(__('Email, name or ID'))
            ->columns([
                TextColumn::make('email')->label(__('Person'))->sortable()
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $like = '%'.addcslashes(mb_strtolower($search), '%_\\').'%';

                        return $query->where(fn (Builder $query) => $query->whereRaw('LOWER(email) LIKE ?', [$like])->orWhereRaw('LOWER(name) LIKE ?', [$like])->orWhere('id', $search));
                    })
                    ->description(fn (User $record): string => $record->name),
                IconColumn::make('email_verified_at')->label(__('Verified'))->boolean()->state(fn (User $record): bool => $record->email_verified_at !== null),
                IconColumn::make('two_factor_confirmed_at')->label(__('Authenticator app'))->boolean()->state(fn (User $record): bool => $record->two_factor_confirmed_at !== null),
                IconColumn::make('is_platform_admin')->label(__('Platform admin'))->boolean(),
                TextColumn::make('created_at')->label(__('Joined'))->date()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('is_platform_admin')->label(__('Platform admin')),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (User $record): string => self::getUrl('view', ['record' => $record]));
    }

    /**
     * Get the resource's pages: the searchable list and one person.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ListUsers::route('/'), 'view' => ViewUser::route('/{record}')];
    }
}
