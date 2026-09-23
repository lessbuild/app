<?php

namespace App\Modules\Deployer\Http\Controllers;

use App\Modules\Deployer\Actions\Account\DeleteAccountAction;
use App\Modules\Deployer\Exceptions\AccountDeletionOperationException;
use App\Modules\Deployer\Http\Requests\DeleteAccountRequest;
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

        return redirect('/')->with('status', __('Your :app account and workspaces were deleted.', ['app' => config('app.name')]));
    }
}
