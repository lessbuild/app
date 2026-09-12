<?php

namespace App\Http\Controllers;

use App\Actions\Recipe\CreateRecipeAction;
use App\Actions\Recipe\DeleteRecipeAction;
use App\Actions\Recipe\DuplicateRecipeAction;
use App\Actions\Recipe\UpdateRecipeAction;
use App\Http\Requests\RecipeRequest;
use App\Models\Recipe;
use App\Models\Server;
use App\Support\CsvCell;
use App\Support\SqlLike;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipesController extends Controller
{
    /**
     * Render filtered workspace recipes with server usage counts and published-source revision context.
     */
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

    /**
     * Stream filtered workspace recipe metadata and assigned server labels as private CSV, excluding script bodies.
     */
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
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Render the form for a new workspace recipe and its publication options.
     */
    public function create(): View
    {
        return view('scenes.recipes.create');
    }

    /**
     * Authorize recipe visibility and render its metadata plus servers owned by the request user and their status counts.
     */
    public function show(Request $request, Recipe $recipe): View
    {
        $this->authorize('view', $recipe);
        $recipe = $request->user()->workspaceRecipes()
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

    /**
     * Create a workspace recipe from validated script and publication settings, record its publication state, and redirect to the list.
     */
    public function store(RecipeRequest $request, CreateRecipeAction $create): RedirectResponse
    {
        $create->handle($request->user(), $request->validated());

        return redirect()->route('recipes.index')->with('status', __('Recipe created.'));
    }

    /**
     * Authorize recipe updates and render its editor with any currently published source and contributor context.
     */
    public function edit(Recipe $recipe): View
    {
        $this->authorize('update', $recipe);
        $recipe->load([
            'source' => fn ($query) => $query->published()->with('user:id,name'),
        ]);

        return view('scenes.recipes.edit', ['recipe' => $recipe]);
    }

    /**
     * Save validated recipe and publication settings after authorization, recording the resulting publication transition.
     */
    public function update(RecipeRequest $request, Recipe $recipe, UpdateRecipeAction $update): RedirectResponse
    {
        $this->authorize('update', $recipe);
        $update->handle($request->user(), $recipe, $request->validated());

        return redirect()->route('recipes.index')->with('status', __('Recipe updated.'));
    }

    /**
     * Authorize recipe deletion, remove its report notifications under a recipe lock, and redirect after deletion and activity recording.
     */
    public function destroy(Recipe $recipe, DeleteRecipeAction $delete): RedirectResponse
    {
        $this->authorize('delete', $recipe);
        $delete->handle($recipe);

        return redirect()->route('recipes.index')->with('status', __('Recipe deleted.'));
    }

    /**
     * Authorize the source recipe and create a workspace copy of its name, description, and script for review in the editor.
     */
    public function duplicate(Request $request, Recipe $recipe, DuplicateRecipeAction $duplicate): RedirectResponse
    {
        $this->authorize('update', $recipe);
        $copy = $duplicate->handle($request->user(), $recipe);

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
        return $request->user()->workspaceRecipes()
            ->when($filters['search'], function ($query, string $value): void {
                $pattern = SqlLike::contains($value);
                $query->where(function ($query) use ($pattern): void {
                    $query
                        ->whereRaw("name LIKE ? ESCAPE '!'", [$pattern])
                        ->orWhereRaw("description LIKE ? ESCAPE '!'", [$pattern]);
                });
            })
            ->when($filters['usage'] === 'in_use', fn ($query) => $query->inUse())
            ->when($filters['usage'] === 'unused', fn ($query) => $query->unused());
    }

    /**
     * Preserve null values and escape text that could be interpreted as a spreadsheet formula.
     */
    private function csvCell(?string $value): ?string
    {
        return CsvCell::escape($value);
    }
}
