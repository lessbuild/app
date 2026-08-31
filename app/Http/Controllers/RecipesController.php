<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecipeRequest;
use App\Models\Recipe;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipesController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $this->indexFilters($request);
        $recipes = $this->filteredRecipes($request, $filters)
            ->withCount('servers')
            ->latest()
            ->paginate()
            ->appends(array_filter($filters, fn ($value) => $value !== null));

        return view('scenes.recipes.index', [
            'recipes' => $recipes,
            'filters' => $filters,
            'usages' => ['in_use', 'unused'],
        ]);
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
                ->with(['servers:id,name'])
                ->withCount('servers')
                ->latest('recipes.id')
                ->lazy(250)
                ->each(function (Recipe $recipe) use ($output): void {
                    fputcsv($output, [
                        $recipe->id,
                        $this->csvCell($recipe->name),
                        $this->csvCell($recipe->description),
                        $this->csvCell($recipe->servers->pluck('name')->implode('; ')),
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

    public function store(RecipeRequest $request): RedirectResponse
    {
        $request->user()->recipes()->create($request->validated());

        return redirect()->route('recipes.index')->with('status', __('Recipe created.'));
    }

    public function edit(Recipe $recipe): View
    {
        $this->authorize('update', $recipe);

        return view('scenes.recipes.edit', ['recipe' => $recipe]);
    }

    public function update(RecipeRequest $request, Recipe $recipe): RedirectResponse
    {
        $this->authorize('update', $recipe);
        $recipe->update($request->validated());

        return redirect()->route('recipes.index')->with('status', __('Recipe updated.'));
    }

    public function destroy(Recipe $recipe): RedirectResponse
    {
        $this->authorize('delete', $recipe);
        $recipe->delete();

        return redirect()->route('recipes.index')->with('status', __('Recipe deleted.'));
    }

    public function duplicate(Request $request, Recipe $recipe): RedirectResponse
    {
        $this->authorize('update', $recipe);

        $copy = $request->user()->recipes()->create([
            'name' => Str::of("Copy of {$recipe->name}")->limit(255, '')->toString(),
            'description' => $recipe->description,
            'script' => $recipe->script,
        ]);

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
            ->when($filters['usage'] === 'in_use', fn ($query) => $query
                ->whereHas('servers'))
            ->when($filters['usage'] === 'unused', fn ($query) => $query
                ->whereDoesntHave('servers'));
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
