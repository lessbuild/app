<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalUiAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_initials_avatar_is_local_and_escapes_untrusted_names(): void
    {
        $html = Blade::render(
            '<x-avatar :name="$name" class="h-10 w-10" />',
            ['name' => 'Ada <script>alert(1)</script>'],
        );

        $this->assertStringContainsString('AA', $html);
        $this->assertStringContainsString('h-10 w-10', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringNotContainsString('http', $html);
    }

    public function test_public_and_authenticated_layouts_render_without_remote_visual_assets(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace']);

        $this->get('/')
            ->assertSuccessful()
            ->assertSee('Infrastructure overview')
            ->assertSee('Deployment completed successfully')
            ->assertDontSee('i.imgur.com', false)
            ->assertDontSee('gopayee.test', false);

        $this->get(route('login'))
            ->assertSuccessful()
            ->assertSee('Deploy with confidence')
            ->assertDontSee('fonts.googleapis.com', false)
            ->assertDontSee('cdnjs.cloudflare.com', false);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('AL')
            ->assertDontSee('ui-avatars.com', false);
    }

    public function test_view_sources_do_not_reintroduce_retired_external_asset_hosts(): void
    {
        $views = collect(File::allFiles(resource_path('views')))
            ->map(fn (\SplFileInfo $file): string => File::get($file->getPathname()))
            ->implode("\n");

        foreach ([
            'ui-avatars.com',
            'fonts.googleapis.com',
            'cdnjs.cloudflare.com',
            'i.imgur.com',
            'gopayee.test',
        ] as $host) {
            $this->assertStringNotContainsString($host, $views);
        }

        $this->assertDoesNotMatchRegularExpression(
            '/<(?:img|script|link)\b[^>]*(?:src|href)=["\']https?:\/\//i',
            $views,
        );
    }
}
