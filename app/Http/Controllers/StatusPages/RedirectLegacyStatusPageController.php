<?php

declare(strict_types=1);

namespace App\Http\Controllers\StatusPages;

use Illuminate\Http\RedirectResponse;

/** The unified app served pages at `/status/{product}/{slug}`; they now live at `/status/{slug}`. */
final class RedirectLegacyStatusPageController
{
    /**
     * Permanently redirect the old address to the new one.
     *
     * @param  string  $product
     * @param  string  $slug
     * @return RedirectResponse
     */
    public function __invoke(string $product, string $slug): RedirectResponse
    {
        return redirect()->route('status.show', $slug, 301);
    }
}
