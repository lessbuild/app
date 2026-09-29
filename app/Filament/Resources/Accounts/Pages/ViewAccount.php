<?php

declare(strict_types=1);

namespace App\Filament\Resources\Accounts\Pages;

use App\Filament\Resources\Accounts\AccountResource;
use App\Filament\Support\CurrentAdmin;
use App\Models\Account;
use App\Queries\Admin\CustomersQuery;
use App\Services\Admin\PlatformAdmins;
use Filament\Resources\Pages\ViewRecord;

final class ViewAccount extends ViewRecord
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<AccountResource>
     */
    protected static string $resource = AccountResource::class;

    /**
     * The page's Blade view.
     *
     * @var string
     */
    protected string $view = 'filament.resources.account';

    /**
     * Open the account and record in the admin trail that it was looked at.
     *
     * @param  int|string  $record
     * @return void
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        $account = $this->getRecord();
        if ($account instanceof Account) {
            app(PlatformAdmins::class)->record(CurrentAdmin::user(), 'customer.viewed', "Opened the account {$account->name}.", null, $account);
        }
    }

    /**
     * Give the view the account's members, projects, billing, usage and latest activity.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(CustomersQuery::class)->account((string) $this->getRecord()->getKey());
    }
}
