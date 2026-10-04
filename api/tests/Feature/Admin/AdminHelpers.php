<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\User;
use Filament\Facades\Filament;

/** Platform admins for the admin panel's tests. */
trait AdminHelpers
{
    /**
     * Make a platform admin with an authenticator app.
     *
     * @return User
     */
    protected function admin(): User
    {
        $user = User::factory()->create();
        Account::factory()->withMember($user)->create();
        $user->forceFill(['is_platform_admin' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    /**
     * Act as the admin with a freshly confirmed password, inside the admin panel.
     *
     * @param  User  $admin
     * @return $this
     */
    protected function as(User $admin): static
    {
        Filament::setCurrentPanel('admin');

        return $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }
}
