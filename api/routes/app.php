<?php

declare(strict_types=1);

// The API behind the Next.js app, under /api/app. These routes use the `web` middleware group: the session cookie
// signs people in, and writes need the X-XSRF-TOKEN header from the XSRF-TOKEN cookie. Sign-in, sign-up, two-factor
// and password reset are Fortify's routes under /api/app/auth (config/fortify.php).

use App\Http\Controllers\Shell\ShowShellController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'account.security'])->group(function (): void {
    Route::get('/shell', ShowShellController::class)->name('shell');
});
