<?php

namespace Tests\Feature;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecipeInventoryExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_filtered_inventory_export_is_owner_scoped_spreadsheet_safe_and_excludes_scripts(): void
    {
        $owner = User::factory()->create();
        $matching = $this->recipe($owner, '=Security baseline', [
            'description' => " \t@HANDOFF baseline",
            'script' => 'SECRET_TOKEN=never-export-this',
        ]);
        $firstServer = $owner->servers()->create(['name' => '+Production']);
        $secondServer = $owner->servers()->create(['name' => '-Disaster recovery']);
        $firstServer->recipes()->attach($matching, ['position' => 0]);
        $secondServer->recipes()->attach($matching, ['position' => 1]);

        $this->recipe($owner, 'Unused security helper');

        $other = User::factory()->create();
        $foreign = $this->recipe($other, 'Security private', [
            'script' => 'FOREIGN_SECRET=never-export-this-either',
        ]);
        $foreignServer = $other->servers()->create(['name' => 'Foreign server']);
        $foreignServer->recipes()->attach($foreign, ['position' => 0]);

        $filters = ['search' => 'Security', 'usage' => 'in_use'];
        $response = $this->actingAs($owner)->get(route('recipes.export', $filters));

        $response
            ->assertSuccessful()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString(
            'attachment; filename=lessbuild-recipes-',
            (string) $response->headers->get('content-disposition'),
        );

        $content = $response->streamedContent();
        $this->assertStringNotContainsString('never-export-this', $content);
        $this->assertStringNotContainsString('Security private', $content);
        $this->assertStringNotContainsString('Foreign server', $content);

        $rows = $this->csvRows($content);
        $this->assertSame([
            'Recipe ID',
            'Name',
            'Description',
            'Assigned servers',
            'Server count',
            'Created at',
            'Updated at',
        ], $rows[0]);
        $this->assertCount(2, $rows);
        $this->assertSame((string) $matching->id, $rows[1][0]);
        $this->assertSame("'=Security baseline", $rows[1][1]);
        $this->assertSame("' \t@HANDOFF baseline", $rows[1][2]);
        $this->assertSame("'+Production; -Disaster recovery", $rows[1][3]);
        $this->assertSame('2', $rows[1][4]);
        $this->assertSame($matching->created_at->toIso8601String(), $rows[1][5]);
        $this->assertSame($matching->updated_at->toIso8601String(), $rows[1][6]);

        $this->actingAs($owner)->get(route('recipes.index', $filters))
            ->assertSuccessful()
            ->assertSee(route('recipes.export', $filters));
    }

    public function test_unused_export_includes_only_unassigned_owned_recipes(): void
    {
        $owner = User::factory()->create();
        $unused = $this->recipe($owner, 'Unused recipe');
        $assigned = $this->recipe($owner, 'Assigned recipe');
        $server = $owner->servers()->create(['name' => 'Production']);
        $server->recipes()->attach($assigned, ['position' => 0]);

        $rows = $this->csvRows(
            $this->actingAs($owner)
                ->get(route('recipes.export', ['usage' => 'unused']))
                ->streamedContent(),
        );

        $this->assertCount(2, $rows);
        $this->assertSame((string) $unused->id, $rows[1][0]);
        $this->assertSame('0', $rows[1][4]);
    }

    public function test_export_requires_authentication(): void
    {
        $this->get(route('recipes.export'))->assertRedirect(route('login'));
    }

    /** @param array<string, mixed> $attributes */
    private function recipe(User $user, string $name, array $attributes = []): Recipe
    {
        return $user->recipes()->create([
            'name' => $name,
            'description' => 'Recipe description',
            'script' => 'echo provision',
            ...$attributes,
        ]);
    }

    /** @return list<list<string|null>> */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $stream = fopen('php://temp', 'w+b');
        $this->assertNotFalse($stream);
        fwrite($stream, substr($content, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }
}
