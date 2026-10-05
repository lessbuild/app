<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site;

use App\Support\Site\Comparisons;
use App\Support\Site\Copy;
use App\Support\Site\PageMeta;
use App\Support\StructuredData;
use Illuminate\Http\JsonResponse;

final class ShowComparisonController
{
    /**
     * Show how BuildPusher compares with another tool: at a glance, who should choose which, reasons people switch, a
     * look at the matching services, what each includes, how to move, common questions (also as FAQ structured data)
     * and the other comparisons. Unknown tools are a 404.
     *
     * @param  string  $competitor
     * @param  Comparisons  $comparisons
     * @return JsonResponse
     */
    public function __invoke(string $competitor, Comparisons $comparisons): JsonResponse
    {
        $copy = config('compare.competitors.'.$competitor);
        abort_unless(is_array($copy), 404);
        $app = (string) config('app.name');
        $name = (string) $copy['name'];
        $title = __(':app vs :other', ['app' => $app, 'other' => $name]);
        $description = __('Looking for a :other alternative? How :app compares with :other: what both do, where they differ, and when to choose each.', ['app' => $app, 'other' => $name]);
        $translated = [...Copy::translate($copy), 'name' => $name];
        $overlaps = $comparisons->overlaps((array) ($copy['overlaps'] ?? []));
        $prices = $comparisons->startingPrices((array) ($copy['overlaps'] ?? []));
        $faqs = $this->faqs($translated, $app, $prices);

        return response()->json([
            'meta' => PageMeta::for(__(':app vs :other: an alternative compared', ['app' => $app, 'other' => $name]), $description, route('compare', $competitor), null, [
                StructuredData::breadcrumbs([$app => route('home'), __('Coming from another tool?') => route('compare.index'), $title => route('compare', $competitor)]),
                StructuredData::faq(array_map(fn (array $faq): array => [$faq['title'], $faq['body']], $faqs)),
            ]),
            'slug' => $competitor,
            'copy' => $translated,
            'overlaps' => $overlaps,
            'previews' => $this->previews($overlaps),
            'startingPrices' => $prices,
            'faqs' => $faqs,
            'others' => array_values(array_filter($comparisons->all(), fn (array $other): bool => $other['slug'] !== $competitor)),
            'checked' => (string) config('compare.checked'),
        ])->header('Vary', 'Accept-Language');
    }

    /**
     * The illustrative dashboards of the services a tool overlaps, as on the service pages.
     *
     * @param  list<array{key: string, name: string}>  $overlaps
     * @return list<array<string, mixed>>
     */
    private function previews(array $overlaps): array
    {
        $previews = [];
        foreach ($overlaps as $service) {
            $marketing = config('marketing.services.'.$service['key']);
            if (is_array($marketing) && isset($marketing['preview'])) {
                $previews[] = [...$service, ...Copy::translate(['icon' => $marketing['icon'] ?? 'grid', 'accent' => $marketing['accent'] ?? 'blue', 'preview' => $marketing['preview']])];
            }
        }

        return $previews;
    }

    /**
     * The common questions: moving without downtime, what the two share, when the other tool is better, and the cost.
     *
     * @param  array<array-key, mixed>  $copy  The comparison's translated copy.
     * @param  string  $app  Our name.
     * @param  string  $prices  Where the matching services start.
     * @return list<array{title: string, body: string}>
     */
    private function faqs(array $copy, string $app, string $prices): array
    {
        $name = (string) $copy['name'];
        $hosting = array_intersect((array) ($copy['overlaps'] ?? []), ['deploy', 'infrastructure']) !== [];
        $steps = implode('. ', array_map(fn (mixed $step): string => rtrim((string) $step, '.'), (array) ($copy['migrate'] ?? []))).'.';
        $choose = (string) ($copy['choose_them'] ?? '');

        return [
            ['title' => __('Can I move from :other without downtime?', ['other' => $name]), 'body' => $steps.' '.($hosting
                ? __('Nothing changes at :other until you point DNS at the new server, so the old site keeps serving until then.', ['other' => $name])
                : __('You can run both side by side while you compare, then remove :other when you’re ready.', ['other' => $name]))],
            ['title' => __('What do :app and :other have in common?', ['app' => $app, 'other' => $name]), 'body' => implode(' ', (array) ($copy['same'] ?? []))],
            ['title' => __('When is :other the better choice?', ['other' => $name]), 'body' => trim(mb_strtoupper(mb_substr($choose, 0, 1)).mb_substr($choose, 1).' '.implode(' ', (array) ($copy['stronger'] ?? [])))],
            ['title' => __('How much does :app cost?', ['app' => $app]), 'body' => __('Every service has a free tier. Paid tiers: :prices. Each is a line on the same bill, and servers are billed by your cloud provider.', ['prices' => $prices])],
        ];
    }
}
