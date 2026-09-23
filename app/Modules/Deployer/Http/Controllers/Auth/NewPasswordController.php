<?php

namespace App\Modules\Deployer\Http\Controllers\Auth;

use App\Modules\Deployer\Actions\Account\ResetPasswordAction;
use App\Modules\Deployer\Http\Controllers\Controller;
use App\Modules\Deployer\Http\Requests\ResetPasswordRequest;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('scenes.auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     *
     * @throws ValidationException
     */
    public function store(ResetPasswordRequest $request, ResetPasswordAction $reset): RedirectResponse
    {
        $data = $request->resetData();
        $status = $reset->handle($data);

        return $status === PasswordBroker::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withInput(['email' => $data->email])
                ->withErrors(['email' => __('This password reset link is invalid or has expired.')]);
    }
}
