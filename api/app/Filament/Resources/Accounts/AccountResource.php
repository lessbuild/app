<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts;

use App\Filament\Resources\Accounts\Pages\ListAccounts;
use App\Filament\Resources\Accounts\Pages\ViewAccount;
use App\Models\Account;
use App\Models\BillingAccount;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/** Customer accounts: find one by name, ID or Stripe customer ID and see its members, projects, billing and activity. */
final class AccountResource extends Resource
{
    /**
     * The accounts' model.
     *
     * @var class-string<Account>|null
     */
    protected static ?string $model = Account::class;

    /**
     * The sidebar icon.
     *
     * @var string|BackedEnum|null
     */
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

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
    protected static ?int $navigationSort = 10;

    /**
     * The attribute naming a record.
     *
     * @var string|null
     */
    protected static ?string $recordTitleAttribute = 'name';

    /**
     * Access is decided by the panel, not the customer-facing policies.
     *
     * @var bool
     */
    protected static bool $shouldSkipAuthorization = true;

    /**
     * Build the accounts table, searchable by name, ID or Stripe customer ID.
     *
     * @param  Table  $table
     * @return Table
     */
    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount(['memberships', 'projects']))
            ->defaultSort('created_at', 'desc')
            ->searchPlaceholder(__('Name, ID or Stripe customer ID'))
            ->columns([
                TextColumn::make('name')->label(__('Account'))->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query
                        ->whereRaw('LOWER(name) LIKE ?', ['%'.addcslashes(mb_strtolower($search), '%_\\').'%'])
                        ->orWhere('id', $search)
                        ->orWhereIn('id', BillingAccount::query()->where('stripe_customer_id', $search)->select('account_id'))))
                    ->description(fn (Account $record): string => $record->id),
                TextColumn::make('memberships_count')->label(__('Members'))->numeric()->sortable(),
                TextColumn::make('projects_count')->label(__('Projects'))->numeric()->sortable(),
                TextColumn::make('created_at')->label(__('Created'))->date()->sortable(),
            ])
            ->recordActions([ViewAction::make()])
            ->recordUrl(fn (Account $record): string => self::getUrl('view', ['record' => $record]));
    }

    /**
     * Get the resource's pages: the searchable list and one account.
     *
     * @return array<string, \Filament\Resources\Pages\PageRegistration>
     */
    public static function getPages(): array
    {
        return ['index' => ListAccounts::route('/'), 'view' => ViewAccount::route('/{record}')];
    }
}
