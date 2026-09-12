<?php

namespace App\Http\Controllers;

use App\Actions\Recipe\CreateRecipeAction;
use App\Actions\Recipe\DeleteRecipeAction;
use App\Actions\Recipe\DuplicateRecipeAction;
use App\Actions\Recipe\UpdateRecipeAction;
use App\Http\Requests\RecipeIndexRequest;
use App\Http\Requests\RecipeRequest;
use App\Models\Recipe;
use App\Models\Server;
use App\Services\RecipeInventoryExporter;
use App\Services\RecipeInventoryQuery;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipesController extends Controller
{
    public function __construct(
        private readonly RecipeInventoryExporter $recipeInventoryExporter,
        private readonly RecipeInventoryQuery $recipeInventory,
    ) {}

    /**
     * Render filtered workspace recipes with server usage counts and published-source revision context.
     */
    public function index(RecipeIndexRequest $request): View
    {
        $filters = $request->filters();
        $recipes = $this->recipeInventory->for($request->user(), $filters)
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
            'metrics' => $this->recipeInventory->metrics($request->user(), $filters),
            'usages' => ['in_use', 'unused'],
        ]);
    }

    /**
     * Stream filtered workspace recipe metadata and assigned server labels as private CSV, excluding script bodies.
     */
    public function export(RecipeIndexRequest $request): StreamedResponse
    {
        return $this->recipeInventoryExporter->stream($request->user(), $request->filters());
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
}
