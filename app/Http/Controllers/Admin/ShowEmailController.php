<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Admin\EmailDelivery;
use Illuminate\Contracts\View\View;

final class ShowEmailController
{
    /**
     * Show how email is set up, what would stop it arriving, and the last test.
     *
     * @param  EmailDelivery  $email
     * @return View
     */
    public function __invoke(EmailDelivery $email): View
    {
        return view('admin.email', ['settings' => $email->settings(), 'problems' => $email->problems(), 'lastTest' => $email->lastTest()]);
    }
}
