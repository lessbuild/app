<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Support\Site\Copy;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowLegalPageController
{
    /**
     * Show the privacy policy or the terms of service, with the date they took effect and where to send questions.
     *
     * @param  string  $page  privacy or terms.
     * @return JsonResponse
     */
    public function __invoke(string $page): JsonResponse
    {
        $copy = config('legal.pages.'.$page);
        abort_unless(is_array($copy), 404);
        $other = $page === 'privacy' ? 'terms' : 'privacy';

        return response()->json([
            'meta' => PageMeta::for(__($copy['title']), __($copy['description']), route('legal', $page), null, [
                StructuredData::breadcrumbs([config('app.name') => route('home'), __($copy['title']) => route('legal', $page)]),
            ]),
            'page' => $page,
            'copy' => Copy::translate($copy),
            'effectiveDate' => (string) config('legal.effective_date'),
            'contactEmail' => (string) config('legal.contact_email'),
            'other' => ['page' => $other, 'title' => __((string) config('legal.pages.'.$other.'.title'))],
        ])->header('Vary', 'Accept-Language');
    }
}
