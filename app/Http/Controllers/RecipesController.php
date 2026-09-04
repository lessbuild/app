<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecipeRequest;
use App\Models\Recipe;
use App\Models\Server;
use App\Services\ActivityRecorder;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipesController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $recipes = $this->filteredRecipes($request, $filters)
            ->with([
                'source' => fn ($query) => $query->published()->select(['id', 'gallery_revision_at']),
            ])
            ->withCount('servers')
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.recipes.index', [
            'recipes' => $recipes,
            'filters' => $filters,
            'metrics' => $this->indexMetrics($request, $filters),
            'usages' => ['in_use', 'unused'],
        ]);
    }

    /**
     * @param  array{search: ?string, usage: ?string}  $filters
     * @return array{total: int, in_use: int, unused: int, assignments: int, servers: int, latest_at: CarbonInterface|null}
     */
    private function indexMetrics(Request $request, array $filters): array
    {
        $latest = $this->filteredRecipes($request, $filters)
            ->select(['id', 'updated_at'])
            ->latest('updated_at')
            ->latest('id')
            ->first();
        $assignments = DB::table('recipe_server')->whereIn(
            'recipe_id',
            $this->filteredRecipes($request, $filters)->select('recipes.id'),
        );

        return [
            'total' => $this->filteredRecipes($request, $filters)->count(),
            'in_use' => $this->filteredRecipes($request, $filters)->inUse()->count(),
            'unused' => $this->filteredRecipes($request, $filters)->unused()->count(),
            'assignments' => (clone $assignments)->count(),
            'servers' => $assignments->distinct()->count('server_id'),
            'latest_at' => $latest?->updated_at,
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        $filters = $this->indexFilters($request);
        $filename = 'lessbuild-recipes-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($request, $filters): void {
            $output = fopen('php://output', 'wb');
            if ($output === false) {
                throw new \RuntimeException('Unable to open the CSV output stream.');
            }

            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, [
                'Recipe ID',
                'Name',
                'Description',
                'Assigned servers',
                'Server count',
                'Created at',
                'Updated at',
            ], ',', '"', '');

            $this->filteredRecipes($request, $filters)
                ->with(['servers:id,name,display_name'])
                ->withCount('servers')
                ->latest('recipes.id')
                ->lazy(250)
                ->each(function (Recipe $recipe) use ($output): void {
                    fputcsv($output, [
                        $recipe->id,
                        $this->csvCell($recipe->name),
                        $this->csvCell($recipe->description),
                        $this->csvCell($recipe->servers->map->label->implode('; ')),
                        $recipe->servers_count,
                        $recipe->created_at?->toIso8601String(),
                        $recipe->updated_at?->toIso8601String(),
                    ], ',', '"', '');
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function create(): View
    {
        return view('scenes.recipes.create');
    }

    public function show(Request $request, Recipe $recipe): View
    {
        $this->authorize('view', $recipe);
        $recipe = $request->user()->recipes()
            ->select(['id', 'user_id', 'name', 'description', 'is_published', 'created_at', 'updated_at'])
            ->findOrFail($recipe->id);
        $assignedServers = $recipe->servers()
            ->where('servers.user_id', $request->user()->id);
        $statusCounts = (clone $assignedServers)
            ->reorder()
            ->select('servers.provisioning_status', DB::raw('COUNT(*) as total'))
            ->groupBy('servers.provisioning_status')
            ->pluck('total', 'servers.provisioning_status');

        return view('scenes.recipes.show', [
            'recipe' => $recipe,
            'servers' => (clone $assignedServers)
                ->select([
                    'servers.id',
                    'servers.user_id',
                    'servers.name',
                    'servers.display_name',
                    'servers.type',
                    'servers.public_ip',
                    'servers.provisioning_status',
                    'servers.created_at',
                ])
                ->paginate()
                ->withQueryString(),
            'metrics' => [
                'total' => $statusCounts->sum(),
                'ready' => (int) $statusCounts->get(Server::STATUS_ACTIVE, 0),
                'provisioning' => collect(Server::ACTIVE_PROVISIONING_STATUSES)
                    ->sum(fn (string $status): int => (int) $statusCounts->get($status, 0)),
                'failed' => (int) $statusCounts->get(Server::STATUS_FAILED, 0),
            ],
        ]);
    }

    public function store(RecipeRequest $request, ActivityRecorder $activity): RedirectResponse
    {
        $data = $this->recipeData($request);
        $recipe = $request->user()->recipes()->create($data);
        $activity->record(
            $recipe,
            $request->user()->id,
            'recipe',
            $recipe->is_published
                ? "Recipe \"{$recipe->name}\" was created and published."
                : "Recipe \"{$recipe->name}\" was created.",
        );

        return redirect()->route('recipes.index')->with('status', __('Recipe created.'));
    }

    public function edit(Recipe $recipe): View
    {
        $this->authorize('update', $recipe);
        $recipe->load([
            'source' => fn ($query) => $query->published()->with('user:id,name'),
        ]);

        return view('scenes.recipes.edit', ['recipe' => $recipe]);
    }

    public function update(RecipeRequest $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        $this->authorize('update', $recipe);
        $wasPublished = $recipe->is_published;
        $recipe->update($this->recipeData($request, $recipe));
        $message = match (true) {
            ! $wasPublished && $recipe->is_published => "Recipe \"{$recipe->name}\" was published.",
            $wasPublished && ! $recipe->is_published => "Recipe \"{$recipe->name}\" was unpublished.",
            default => "Recipe \"{$recipe->name}\" was updated.",
        };
        $activity->record($recipe, $request->user()->id, 'recipe', $message);

        return redirect()->route('recipes.index')->with('status', __('Recipe updated.'));
    }

    public function destroy(Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        $this->authorize('delete', $recipe);
        $recipe->delete();
        $activity->record($recipe, $recipe->user_id, 'recipe', "Recipe \"{$recipe->name}\" was deleted.");

        return redirect()->route('recipes.index')->with('status', __('Recipe deleted.'));
    }

    public function duplicate(Request $request, Recipe $recipe, ActivityRecorder $activity): RedirectResponse
    {
        $this->authorize('update', $recipe);

        $copy = $request->user()->recipes()->create([
            'name' => Str::of("Copy of {$recipe->name}")->limit(255, '')->toString(),
            'description' => $recipe->description,
            'script' => $recipe->script,
        ]);
        $activity->record(
            $copy,
            $request->user()->id,
            'recipe',
            "Recipe \"{$recipe->name}\" was duplicated as \"{$copy->name}\".",
        );

        return redirect()
            ->route('recipes.edit', $copy)
            ->with('status', __('Recipe duplicated. Review and rename the copy before using it.'));
    }

    /** @return array{search: ?string, usage: ?string} */
    private function indexFilters(Request $request): array
    {
        $search = str($request->string('search')->toString())->trim()->limit(100, '')->toString();
        $usage = $request->string('usage')->toString();

        return [
            'search' => $search !== '' ? $search : null,
            'usage' => in_array($usage, ['in_use', 'unused'], true) ? $usage : null,
        ];
    }

    /** @param array{search: ?string, usage: ?string} $filters */
    private function filteredRecipes(Request $request, array $filters): HasMany
    {
        return $request->user()->recipes()
            ->when($filters['search'], function ($query, string $value): void {
                $query->where(function ($query) use ($value): void {
                    $query
                        ->where('name', 'like', "%{$value}%")
                        ->orWhere('description', 'like', "%{$value}%");
                });
            })
            ->when($filters['usage'] === 'in_use', fn ($query) => $query->inUse())
            ->when($filters['usage'] === 'unused', fn ($query) => $query->unused());
    }

    private function csvCell(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace("\0", '', $value);

        return preg_match('/\A[\x09\x0A\x0D ]*[=+\-@]/', $value) === 1 ? "'{$value}" : $value;
    }

    /** @return array<string, mixed> */
    private function recipeData(RecipeRequest $request, ?Recipe $recipe = null): array
    {
        $data = $request->validated();
        $published = (bool) $data['is_published'];
        $data['category'] = $published ? ($data['category'] ?? null) : null;
        if (! $published) {
            $data['published_at'] = null;
            $data['gallery_revision_at'] = null;

            return $data;
        }

        $newPublication = ! $recipe?->is_published || $recipe->published_at === null;
        $contentChanged = $recipe === null
            || $recipe->name !== $data['name']
            || $recipe->description !== ($data['description'] ?? null)
            || $recipe->script !== $data['script']
            || $recipe->category !== $data['category'];

        $data['published_at'] = $newPublication ? now() : $recipe->published_at;
        $data['gallery_revision_at'] = $newPublication || $contentChanged
            ? now()
            : ($recipe->gallery_revision_at ?? $recipe->published_at ?? now());

        return $data;
    }
}
