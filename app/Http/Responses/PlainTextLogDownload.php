<?php

namespace App\Http\Responses;

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

class PlainTextLogDownload
{
    /**
     * Return log contents as a private, noncacheable plain-text attachment.
     *
     * @param  string  $contents  The log bytes to include in the response body.
     * @param  string  $filename  The attachment filename validated by HeaderUtils.
     * @return Response A successful download response with MIME sniffing disabled.
     */
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
