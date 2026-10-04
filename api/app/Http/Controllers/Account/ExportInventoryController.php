<?php

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Attributes\CurrentAccount;
use App\Models\Account;
use App\Models\User;
use App\Queries\Accounts\InventoryQuery;
use Illuminate\Container\Attributes\CurrentUser;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportInventoryController
{
    /**
     * Download one of the account's inventories as CSV: servers, websites, providers, recipes or repositories.
     *
     * @param  Account  $account
     * @param  User  $user
     * @param  string  $kind
     * @param  InventoryQuery  $inventory
     * @return StreamedResponse
     */
    public function __invoke(#[CurrentAccount] Account $account, #[CurrentUser] User $user, string $kind, InventoryQuery $inventory): StreamedResponse
    {
        $export = $inventory->handle($account, $user, $kind);

        return response()->streamDownload(function () use ($export): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, $export['header'], escape: '');
            foreach ($export['rows'] as $row) {
                fputcsv($out, $row, escape: '');
            }
            fclose($out);
        }, "{$account->slug}-{$kind}-".now()->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store, private']);
    }
}
