<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use Filament\Resources\Pages\ListRecords;

final class ListAccounts extends ListRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<AccountResource>
     */
    protected static string $resource = AccountResource::class;
}
