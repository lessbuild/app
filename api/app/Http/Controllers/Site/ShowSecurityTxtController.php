<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use Illuminate\Http\Response;

final class ShowSecurityTxtController
{
    /**
     * Tell security researchers how to report a problem (RFC 9116), valid for a year from now.
     *
     * @return Response
     */
    public function __invoke(): Response
    {
        $lines = [
            'Contact: mailto:'.config('legal.contact_email'),
            'Expires: '.now('UTC')->addYear()->startOfDay()->format('Y-m-d\TH:i:s\Z'),
            'Preferred-Languages: en',
            'Canonical: '.route('security-txt'),
            'Policy: '.route('legal', 'terms'),
        ];

        return response(implode("\n", $lines)."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'public, max-age=86400']);
    }
}
