<?php

declare(strict_types=1);

namespace App\Http\Controllers\Help;

use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowHelpController
{
    /**
     * List the help centre's guides by group, with each guide's text for searching on the page.
     *
     * @return JsonResponse
     */
    public function __invoke(): JsonResponse
    {
        $guides = collect((array) config('help.guides'));

        return response()->json([
            'meta' => PageMeta::for(__('Help centre'), __('Short guides to every part of :app.', ['app' => config('app.name')]), route('help'), null, [
                StructuredData::breadcrumbs([config('app.name') => route('home'), __('Help centre') => route('help')]),
            ]),
            'groups' => collect((array) config('help.groups'))->map(fn (array $group, string $key): array => [
                'key' => $key,
                'title' => __($group['title']),
                'summary' => __($group['summary']),
                'icon' => $group['icon'],
                'guides' => $guides->where('group', $key)->map(fn (array $guide, string $slug): array => [
                    'slug' => $slug,
                    'title' => __($guide['title']),
                    'summary' => __($guide['summary']),
                    'text' => mb_strtolower(__($guide['title']).' '.__($guide['summary']).' '.implode(' ', array_map(fn (array $step): string => __($step[0]).' '.__($step[1]), $guide['steps']))),
                ])->values(),
            ])->values(),
            'contactEmail' => (string) config('legal.contact_email'),
        ])->header('Vary', 'Accept-Language');
    }
}
