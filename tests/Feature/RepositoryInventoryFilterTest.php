<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RepositoryInventoryFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_repository_filters_are_collapsed_by_default_and_open_when_active(): void
    {
        $owner = User::factory()->create();

        $default = $this->actingAs($owner)->get(route('repositories.index'));
        $defaultContent = $default->getContent();

        $default
            ->assertSuccessful()
            ->assertSee('Filter repositories');
        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*\bid="repositories-filters")(?=[^>]*\bdata-filter-dialog\b)[^>]*>/',
            $defaultContent,
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<dialog(?=[^>]*\bid="repositories-filters")(?=[^>]*\bdata-filter-dialog\b)(?=[^>]*(?:\sopen(?:\s|>)))[^>]*>/',
            $defaultContent,
        );

        $active = $this->actingAs($owner)->get(route('repositories.index', ['search' => 'storefront']));

        $active
            ->assertSuccessful()
            ->assertSee('1 active')
            ->assertSee('value="storefront"', false);
        $this->assertMatchesRegularExpression(
            '/<dialog(?=[^>]*\bid="repositories-filters")(?=[^>]*\bdata-filter-dialog\b)(?=[^>]*(?:\sopen(?:\s|>)))[^>]*>/',
            $active->getContent(),
        );
    }
}
