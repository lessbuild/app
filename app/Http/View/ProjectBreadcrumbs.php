<?php

declare(strict_types=1);

namespace App\Http\View;

use App\Models\Project;
use App\Platform\ServiceRegistry;
use Illuminate\Http\Request;

/**
 * The breadcrumb trail above project pages: Projects, the project, then (inside a service) the service's landing page
 * and the section the page belongs to, so each step up goes to the page one level above rather than straight back to
 * the project.
 */
final class ProjectBreadcrumbs
{
    /**
     * Create a new ProjectBreadcrumbs instance.
     *
     * @param  Request  $request  The page being shown.
     * @param  ServiceRegistry  $services  The platform's services and their sections.
     */
    public function __construct(private readonly Request $request, private readonly ServiceRegistry $services) {}

    /**
     * Build the trail for a project page, leaving out the page itself (the header adds it as the last step).
     *
     * @param  Project  $project
     * @return list<array{label: string, href: string}>
     */
    public function handle(Project $project): array
    {
        $trail = [['label' => __('Projects'), 'href' => route('dashboard')]];
        if ($this->request->routeIs('projects.show')) {
            return $trail;
        }
        $trail[] = ['label' => $project->name, 'href' => route('projects.show', $project)];

        $service = $this->currentService();
        $definition = $service !== null ? $this->services->find($service) : null;
        $items = $definition?->navItems($project->id) ?? [];
        if ($definition === null || $items === []) {
            return $trail;
        }
        $here = $this->request->url();
        $landing = $items[0]->url;
        $section = null;
        foreach ($items as $item) {
            if ($section === null && $this->request->routeIs(...explode('|', $item->activePattern))) {
                $section = $item;
            }
        }
        if ($landing !== $here && $landing !== $section?->url) {
            $trail[] = ['label' => $definition->name(), 'href' => $landing];
        }
        if ($section !== null && $section->url !== $here) {
            $trail[] = ['label' => $section->label, 'href' => $section->url];
        }

        return $trail;
    }

    /**
     * Work out which service's pages are showing: the generic {service} pages, or a service's own routes (e.g.
     * analytics.*).
     *
     * @return string|null
     */
    public function currentService(): ?string
    {
        $service = $this->request->route('service');
        if (is_string($service)) {
            return $service;
        }
        foreach ($this->services->keys() as $key) {
            if ($this->request->routeIs($key.'.*')) {
                return $key;
            }
        }

        return null;
    }
}
