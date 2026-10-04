<?php

declare(strict_types=1);

namespace App\Support\Site;

use App\Support\StructuredData;

/** What a public page tells search engines and link previews: its title, description, address, image and JSON-LD. */
final class PageMeta
{
    /**
     * Describe a public page for the app to put in its head.
     *
     * @param  string  $title  The page's title, without the site's name (the app adds it).
     * @param  string  $description
     * @param  string  $canonical  The page's one address, without tracking parameters.
     * @param  string|null  $image  The link preview image's path under /images/og, such as "deploy.png"; the default otherwise.
     * @param  list<array<string, mixed>>  $structuredData  schema.org nodes besides the organisation and website.
     * @return array{title: string, description: string, canonical: string, image: string, structuredData: array<string, mixed>}
     */
    public static function for(string $title, string $description, string $canonical, ?string $image = null, array $structuredData = []): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $canonical,
            'image' => asset('images/og/'.($image ?? 'default.png')),
            'structuredData' => StructuredData::graph($structuredData),
        ];
    }
}
