<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

final class ListUsers extends ListRecords
{
    /**
     * The resource this page belongs to.
     *
     * @var class-string<UserResource>
     */
    protected static string $resource = UserResource::class;
}
