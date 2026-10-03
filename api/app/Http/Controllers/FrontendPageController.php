<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The target of routes/frontend.php: the Next.js app's pages, named so Laravel can link to them. Caddy sends these
 * paths to Next.js, so Laravel only answers when it's run without Caddy, and then says where the page lives.
 */
final class FrontendPageController
{
    /**
     * Refuse to serve a page that belongs to the Next.js app.
     *
     * @return never
     *
     * @throws NotFoundHttpException
     */
    public function __invoke(): never
    {
        throw new NotFoundHttpException('This page is served by the Next.js app (web/).');
    }
}
