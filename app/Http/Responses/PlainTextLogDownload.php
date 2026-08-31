<?php

namespace App\Http\Responses;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PlainTextLogDownload
{
    public function make(string $contents, string $filename): Response
    {
        return response($contents, 200, [
            'Cache-Control' => 'private, no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $filename,
            ),
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
