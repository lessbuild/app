<?php

declare(strict_types=1);

use App\Http\Controllers\ComponentGalleryController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome');

Route::get('/_gallery', ComponentGalleryController::class)->name('gallery');
