<?php

namespace App\Modules\Deployer\Http\Controllers\Auth;

use App\Modules\Deployer\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationPromptController extends Controller
{
    /** Display the email verification prompt. */
    public function __invoke(Request $request): View|RedirectResponse
    {
        return $request->user()->hasVerifiedEmail()
            ? redirect()->route('dashboard')
            : view('scenes.auth.verify-email');
    }
}
