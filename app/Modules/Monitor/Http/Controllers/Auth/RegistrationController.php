<?php

namespace App\Modules\Monitor\Http\Controllers\Auth;

use App\Modules\Monitor\Http\Controllers\Controller;
use App\Modules\Monitor\Http\Requests\Auth\RegisterRequest;
use App\Modules\Monitor\Models\User;
use App\Modules\Monitor\Services\CreateWorkspace;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class RegistrationController extends Controller
{
    public function create(): View
    {
        return view('monitor::auth.register');
    }

    public function store(RegisterRequest $request, CreateWorkspace $createWorkspace): RedirectResponse
    {
        $user = DB::connection('monitor')->transaction(function () use ($request, $createWorkspace): User {
            $user = User::query()->create($request->safe()->only(['name', 'email', 'password']));
            $createWorkspace->create($user, $request->validated('workspace_name'));

            return $user;
        });

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget('workspace_id');

        return redirect()->intended(route('monitor.dashboard'));
    }
}
