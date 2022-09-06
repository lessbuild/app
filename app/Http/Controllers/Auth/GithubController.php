<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Exception;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;

class GithubController extends Controller
{
    /**
     * Redirect to the authentication page
     *
     * @param string $type
     * @return \Illuminate\Http\RedirectResponse
     */
    public function redirect(string $type): RedirectResponse
    {
        return match ($type) {
            'github' => Socialite::driver('github')->redirect(),
            'gitlab' => Socialite::driver('gitlab')->redirect(),
            'bitbucket' => Socialite::driver('bitbucket')->redirect(),
        };
    }

    /**
     * @param string $type
     * @return \Illuminate\Http\RedirectResponse
     */
    public function callback(string $type): RedirectResponse
    {
        $user = match ($type) {
            'github' => Socialite::driver('github')->user(),
            'gitlab' => Socialite::driver('gitlab')->user(),
            'bitbucket' => Socialite::driver('bitbucket')->user(),
        };

        $column = match ($type) {
            'github' => 'github_id',
            'gitlab' => 'gitlab_id',
            'bitbucket' => 'bitbucket_id',
        };

        if ($searchUser = User::where($column, $user->id)->first()) {
            Auth::login($searchUser);
        } else {
            $user = User::create([
                'name'      => $user->name,
                'email'     => $user->email,
                $column     => $user->id,
                'auth_type' => $type,
                'password'  => Hash::make(str_random(8)),
            ]);

            Auth::login($user);
        }

        return to_route('dashboard');
    }
}
