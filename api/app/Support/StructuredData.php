<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\HtmlString;

/** Builds the schema.org JSON-LD that tells search engines what a public page is: the organisation, its breadcrumbs, an article, its questions. */
final class StructuredData
{
    /**
     * Render nodes as one JSON-LD script tag for the page's head, after the organisation and website they refer to.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return HtmlString
     */
    public static function script(array $nodes): HtmlString
    {
        $json = json_encode(['@context' => 'https://schema.org', '@graph' => [self::organization(), self::website(), ...$nodes]], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_THROW_ON_ERROR);

        return new HtmlString('<script type="application/ld+json">'.$json.'</script>');
    }

    /**
     * Describe the company behind the site.
     *
     * @return array<string, mixed>
     */
    private static function organization(): array
    {
        return ['@type' => 'Organization', '@id' => route('home').'#organization', 'name' => config('app.name'), 'url' => route('home'), 'logo' => asset('images/og/default.png')];
    }

    /**
     * Describe the website itself, published by the organisation.
     *
     * @return array<string, mixed>
     */
    private static function website(): array
    {
        return ['@type' => 'WebSite', '@id' => route('home').'#website', 'name' => config('app.name'), 'url' => route('home'), 'publisher' => ['@id' => route('home').'#organization']];
    }

    /**
     * Describe where a page sits in the site, from the home page down.
     *
     * @param  array<string, string>  $trail  each crumb's name => URL, home first
     * @return array<string, mixed>
     */
    public static function breadcrumbs(array $trail): array
    {
        $items = [];
        foreach (array_keys($trail) as $index => $name) {
            $items[] = ['@type' => 'ListItem', 'position' => $index + 1, 'name' => $name, 'item' => $trail[$name]];
        }

        return ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }

    /**
     * Describe a page's questions and their answers.
     *
     * @param  iterable<array{0: string, 1: string}>  $questions  each question and its answer
     * @return array<string, mixed>
     */
    public static function faq(iterable $questions): array
    {
        $entities = [];
        foreach ($questions as [$question, $answer]) {
            $entities[] = ['@type' => 'Question', 'name' => $question, 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $answer]];
        }

        return ['@type' => 'FAQPage', 'mainEntity' => $entities];
    }

    /**
     * Describe a how-to guide as an article published by the organisation.
     *
     * @param  string  $title
     * @param  string  $summary
     * @param  string  $url
     * @return array<string, mixed>
     */
    public static function article(string $title, string $summary, string $url): array
    {
        return ['@type' => 'TechArticle', 'headline' => $title, 'description' => $summary, 'url' => $url, 'mainEntityOfPage' => $url, 'inLanguage' => app()->getLocale(), 'publisher' => ['@id' => route('home').'#organization']];
    }
}
