<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Filament\Support\CurrentAdmin;
use App\Models\User;
use App\Queries\Admin\CustomersQuery;
use App\Services\Admin\PlatformAdmins;
use Filament\Resources\Pages\ViewRecord;

final class ViewUser extends ViewRecord
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<UserResource>
     */
    protected static string $resource = UserResource::class;

    /**
     * The page's Blade view.
     *
     * @var string
     */
    protected string $view = 'filament.resources.user';

    /**
     * Open the person and record in the admin trail that they were looked at.
     *
     * @param  int|string  $record
     * @return void
     */
    public function mount(int|string $record): void
    {
        parent::mount($record);
        $person = $this->getRecord();
        if ($person instanceof User) {
            app(PlatformAdmins::class)->record(CurrentAdmin::user(), 'customer.viewed', "Opened {$person->email}.", $person);
        }
    }

    /**
     * Give the view the person's accounts, sign-in methods and latest sign-ins.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return app(CustomersQuery::class)->user((string) $this->getRecord()->getKey());
    }
}
