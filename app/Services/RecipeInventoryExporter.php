<?php

namespace App\Services;

use App\Models\Recipe;
use App\Models\User;
use App\Support\CsvCell;
use Symfony\Component\HttpFoundation\StreamedResponse;

class RecipeInventoryExporter
{
    public function __construct(private readonly RecipeInventoryQuery $recipes) {}

    /**
     * Stream filtered workspace recipe metadata and assigned server labels as private CSV, excluding scripts.
     *
     * @param  array{search: ?string, usage: ?string}  $filters  Validated inventory filters.
     */
    public function stream(User $user, array $filters): StreamedResponse
    {
        $filename = 'lessbuild-recipes-'.now()->utc()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($user, $filters): void {
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

            $this->recipes->for($user, $filters)
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

    /** Escape values that could be interpreted as spreadsheet formulas. */
    private function csvCell(?string $value): ?string
    {
        return CsvCell::escape($value);
    }
}
