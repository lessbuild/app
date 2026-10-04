<?php

declare(strict_types=1);

namespace App\Data\Monitoring;

use App\Models\Monitor;
use App\Models\StatusPage;
use App\Models\StatusPageComponent;
use Illuminate\Support\Collection;

final readonly class StatusPageForm
{
    /**
     * Create a new StatusPageForm instance.
     *
     * The status page form's monitors and, when editing, the page's settings.
     *
     * @param  list<array{id: int, name: string, description: string}>  $monitors  The account's monitors that can be components.
     * @param  string  $baseUrl  The app's address, for the public address's hint.
     * @param  array{name: string, slug: string, description: string|null, published: bool, monthlyReport: bool, components: array<int, string|null>}|null  $page  When editing: the components chosen, by monitor, with their group.
     */
    public function __construct(
        public array $monitors,
        public string $baseUrl,
        public ?array $page,
    ) {}

    /**
     * Describe the form for a new status page, or for editing one.
     *
     * @param  Collection<int, Monitor>  $monitors
     * @param  StatusPage|null  $page
     * @return self
     */
    public static function for(Collection $monitors, ?StatusPage $page): self
    {
        $components = [];
        foreach ($page === null ? [] : $page->components as $component) {
            /** @var StatusPageComponent $component */
            $components[$component->monitor_id] = $component->group_name;
        }

        return new self(
            monitors: array_values($monitors->map(fn (Monitor $monitor): array => [
                'id' => $monitor->id, 'name' => $monitor->name, 'description' => __($monitor->typeLabel()).' · '.$monitor->environment->project->name.' / '.$monitor->environment->name,
            ])->all()),
            baseUrl: url('/'),
            page: $page === null ? null : [
                'name' => $page->name,
                'slug' => $page->slug,
                'description' => $page->description,
                'published' => (bool) $page->published,
                'monthlyReport' => (bool) $page->monthly_report,
                'components' => $components,
            ],
        );
    }
}
