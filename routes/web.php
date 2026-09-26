<?php

declare(strict_types=1);

use App\Http\Controllers\Accounts\InvitationController;
use App\Http\Controllers\ComponentGalleryController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::get('/_gallery', ComponentGalleryController::class)->name('gallery');

Route::get('/invitations/{token}', [InvitationController::class, 'show'])->name('invitations.show');

Route::middleware(['auth', 'verified'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::post('/invitations/{token}', [InvitationController::class, 'store'])->middleware('throttle:10,1')->name('invitations.accept');
});
