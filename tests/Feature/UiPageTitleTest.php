<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UiPageTitleTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_pages_have_distinct_browser_titles(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('<title>Dashboard · '.config('app.name').'</title>', false);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertSuccessful()
            ->assertSee('<title>Applications · '.config('app.name').'</title>', false);

        $this->actingAs($user)
            ->get(route('account.index'))
            ->assertSuccessful()
            ->assertSee('<title>Account · '.config('app.name').'</title>', false);
    }
}
