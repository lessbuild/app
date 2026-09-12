<?php

namespace App\Http\Controllers;

use App\Actions\Account\DeleteAccountAction;
use App\Exceptions\AccountDeletionOperationException;
use App\Http\Requests\DeleteAccountRequest;
use Illuminate\Http\RedirectResponse;

class AccountDeletionController extends Controller
{
    /**
     * Validate email confirmation and applicable password/two-factor challenges before deleting the account.
     *
     * Shared memberships, remaining teammates, or active operations block deletion; success clears the session and redirects home.
     */
    public function __invoke(DeleteAccountRequest $request, DeleteAccountAction $delete): RedirectResponse
    {
        try {
            $delete->handle($request->user(), $request->deletionData());
        } catch (AccountDeletionOperationException $exception) {
            abort($exception->statusCode, $exception->getMessage());
        }

        return redirect('/')->with('status', __('Your BuildPusher account and workspaces were deleted.'));
    }
}
