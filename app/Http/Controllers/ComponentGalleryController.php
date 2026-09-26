<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

/** Renders every Signal component as the visual reference; only available outside production. */
final class ComponentGalleryController
{
    public function __invoke(): View
    {
        abort_unless(app()->environment(['local', 'testing']), 404);

        return view('gallery.index');
    }
}
