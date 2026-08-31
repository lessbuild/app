<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ActivityController extends Controller
{
    public function __invoke(Request $request): View
    {
        $filters = $this->filters($request);

        return view('activity.index', [
            'events' => $this->filteredEvents($request, $filters)
                ->with('parentable')
                ->latest()
                ->paginate(25)
                ->appends(array_filter($filters, fn ($value) => $value !== null)),
            'filters' => $filters,
            'categories' => Event::CATEGORIES,
        ]);
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->filters($request);
        $filename = 'lessbuild-activity-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Event ID',
                'Category',
                'Activity',
                'Resource type',
                'Resource ID',
                'Recorded at',
            ], ',', '"', '');

            $this->filteredEvents($request, $filters)
                ->latest('id')
                ->lazy(250)
                ->each(function (Event $event) use ($output): void {
                    fputcsv($output, [
                        $event->id,
                        $this->csvCell($event->category),
                        $this->csvCell($event->event),
                        $this->csvCell(class_basename($event->parentable_type)),
                        $event->parentable_id,
                        $event->created_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** @return array{search: ?string, category: ?string, date_from: ?string, date_to: ?string} */
    private function filters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $category = $request->string('category')->toString();
        $dateFrom = $this->date($request->string('date_from')->toString());
        $dateTo = $this->date($request->string('date_to')->toString());

        return [
            'search' => $search !== '' ? $search : null,
            'category' => in_array($category, Event::CATEGORIES, true) ? $category : null,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ];
    }

    /**
     * @param  array{search: ?string, category: ?string, date_from: ?string, date_to: ?string}  $filters
     */
    private function filteredEvents(Request $request, array $filters): HasMany
    {
        return $request->user()->events()
            ->when($filters['search'], fn ($query, string $value) => $query
                ->where('event', 'like', "%{$value}%"))
            ->when($filters['category'], fn ($query, string $value) => $query
                ->where('category', $value))
            ->when($filters['date_from'], fn ($query, string $value) => $query
                ->whereDate('created_at', '>=', $value))
            ->when($filters['date_to'], fn ($query, string $value) => $query
                ->whereDate('created_at', '<=', $value));
    }

    private function date(string $value): ?string
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    private function csvCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }
}
