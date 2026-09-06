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
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

        $this->get('/')
            ->assertSuccessful()
            ->assertSee('Live workspace preview')
            ->assertSee('Health verification')
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
            ->assertSee('Send feedback')
            ->assertDontSee('ui-avatars.com', false);
    }

    public function test_documentation_covers_onboarding_operations_and_troubleshooting(): void
    {
        $this->get(route('docs'))
            ->assertSuccessful()
            ->assertSee('From empty workspace to verified release')
            ->assertSee('First deployment')
            ->assertSee('Daily operations')
            ->assertSee('Recovery drill')
            ->assertSee('Release and security checklist')
            ->assertSee('Troubleshooting')
            ->assertSee('Unexpected 500 response')
            ->assertSee(route('platform-status.show'))
            ->assertSee(route('api-docs'));
    }

    public function test_public_navigation_and_calls_to_action_are_functional_and_truthful(): void
    {
        $guestHtml = $this->get('/')
            ->assertSuccessful()
            ->assertSee('<title>Deploy with clarity · '.config('app.name').'</title>', false)
            ->assertSee('name="description" content="Provision infrastructure, ship Git releases, monitor health, and recover confidently from one focused control plane."', false)
            ->assertSee('name="robots" content="index, follow"', false)
            ->assertSee('rel="canonical" href="'.url('/').'"', false)
            ->assertSee('property="og:type" content="website"', false)
            ->assertSee('property="og:site_name" content="'.config('app.name').'"', false)
            ->assertSee('property="og:title" content="Deploy with clarity · '.config('app.name').'"', false)
            ->assertSee('property="og:url" content="'.url('/').'"', false)
            ->assertSee('name="twitter:card" content="summary"', false)
            ->assertSee('name="theme-color" content="#111827"', false)
            ->assertSee('rel="icon" href="/favicon.svg" type="image/svg+xml"', false)
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content" tabindex="-1"', false)
            ->assertSee('aria-controls="navbarCollapse"', false)
            ->assertSee(':aria-expanded="navigationOpen.toString()"', false)
            ->assertSee('@keydown.escape.window="navigationOpen = false"', false)
            ->assertSee('aria-label="Homepage navigation"', false)
            ->assertSee('aria-label="Mobile homepage navigation"', false)
            ->assertSee('<noscript>', false)
            ->assertSee('aria-label="Homepage navigation without JavaScript"', false)
            ->assertSee('id="features"', false)
            ->assertSee('id="product"', false)
            ->assertSee('id="how-it-works"', false)
            ->assertSee('id="questions"', false)
            ->assertSee('Deploy with clarity. Recover with confidence.')
            ->assertSee('Provision without the guesswork')
            ->assertSee('Make every deployment traceable')
            ->assertSee('See health in context')
            ->assertSee('Act safely when it matters')
            ->assertSee('Framework-ready runtimes')
            ->assertSee('Safe release strategies')
            ->assertSee('Preview environments')
            ->assertSee('Cloudflare automation')
            ->assertSee('High availability')
            ->assertSee('Managed data services')
            ->assertSee('Server telemetry')
            ->assertSee('Threshold alerts')
            ->assertSee('Scheduled tasks')
            ->assertSee('CLI and MCP')
            ->assertSee('Organizations and roles')
            ->assertSee('Enterprise sign-in')
            ->assertSee('Access policies')
            ->assertSee('Verified backups')
            ->assertSee('Platform diagnostics')
            ->assertSee('Preflight and approvals')
            ->assertSee('Automatic recovery')
            ->assertSee('Cost visibility')
            ->assertSee('Incident command centre')
            ->assertSee("activeFeature: 'ship'", false)
            ->assertSee("x-show=\"activeFeature === 'recover'\"", false)
            ->assertSee('36</strong> capabilities', false)
            ->assertSee('Works with the providers you already use')
            ->assertSee('DigitalOcean')
            ->assertSee('GitHub')
            ->assertSee('GitLab')
            ->assertSee('Bitbucket')
            ->assertSee('Live workspace')
            ->assertSee('Designed for the difficult day.')
            ->assertSee('Exact-revision recovery')
            ->assertSee('Where does my application run?')
            ->assertSee('Can I recover a previous release?')
            ->assertSee('One workspace, every operational stage.')
            ->assertSee('One calm operational overview')
            ->assertSee('Infrastructure with visible progress')
            ->assertSee('Every release stays explainable')
            ->assertSee('Health checks that tell a story')
            ->assertSee('Actions keep their accountability')
            ->assertSee('Repeat the setup that works')
            ->assertSee('Nothing here is a screenshot or a real account.')
            ->assertSee('role="tablist"', false)
            ->assertSee('aria-label="Product areas"', false)
            ->assertSee("activeArea: 'overview'", false)
            ->assertSee('moveArea(offset)', false)
            ->assertSee(':tabindex="activeArea ===', false)
            ->assertSee('@keydown.right.prevent="moveArea(1)"', false)
            ->assertSee('@keydown.left.prevent="moveArea(-1)"', false)
            ->assertSee('@keydown.home.prevent="focusArea(0)"', false)
            ->assertSee('@keydown.end.prevent="focusArea(areas.length - 1)"', false)
            ->assertSee("x-show=\"activeArea === 'monitor'\"", false)
            ->assertSee('aria-controls="area-panel-reuse"', false)
            ->assertSee('Make the next deployment the clear one.')
            ->assertSee(route('register'))
            ->assertSee('Get started')
            ->assertDontSee('Choose plan')
            ->assertDontSee('£5')
            ->assertDontSee('£15')
            ->assertDontSee('£25')
            ->getContent();

        $this->assertGreaterThanOrEqual(3, substr_count($guestHtml, 'href="'.route('register').'"'));
        $this->assertSame(6, substr_count($guestHtml, 'role="tabpanel"'));

        $homepage = File::get(resource_path('views/scenes/index.blade.php'));
        $coreLayout = File::get(resource_path('views/components/layouts/core.blade.php'));
        $styles = File::get(resource_path('css/app.css'));
        $this->assertStringContainsString(':livewire="false"', $homepage);
        $this->assertStringContainsString('@if ($livewire)', $coreLayout);
        $this->assertStringContainsString('[x-cloak]', $styles);
        $this->assertStringContainsString('#main-content .border:is(', $styles);
        $this->assertStringContainsString('background-color: var(--bg-primary)', $styles);
    }

    public function test_private_pages_are_not_indexable_and_do_not_emit_public_share_metadata(): void
    {
        $html = $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('name="robots" content="noindex, nofollow"', false)
            ->getContent();

        $this->assertStringNotContainsString('rel="canonical"', $html);
        $this->assertStringNotContainsString('property="og:', $html);
        $this->assertStringNotContainsString('name="twitter:', $html);
    }

    public function test_local_favicon_is_a_nonempty_script_free_svg(): void
    {
        $favicon = File::get(public_path('favicon.svg'));

        $this->assertNotSame('', trim($favicon));
        $this->assertStringContainsString('<svg', $favicon);
        $this->assertStringNotContainsString('<script', $favicon);
        $this->assertDoesNotMatchRegularExpression('/(?:href|src)=["\']https?:\/\//', $favicon);

        $this->get('/favicon.ico')
            ->assertMovedPermanently()
            ->assertRedirect('/favicon.svg')
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    public function test_robots_file_allows_the_homepage_and_discourages_private_route_crawling(): void
    {
        $robots = File::get(public_path('robots.txt'));

        $this->assertStringContainsString("User-agent: *\nAllow: /\n", $robots);
        foreach (['/account', '/api', '/login', '/system-health', '/websites'] as $path) {
            $this->assertStringContainsString("Disallow: {$path}\n", $robots);
        }
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

    public function test_authenticated_layout_has_live_accessible_shell_navigation(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('class="motion-safe:scroll-smooth"', false)
            ->assertSee('id="primary-navigation"', false)
            ->assertSee('aria-controls="primary-navigation"', false)
            ->assertSee('aria-label="Primary navigation"', false)
            ->assertSee('aria-label="Toggle navigation"', false)
            ->assertSee('aria-label="Close navigation"', false)
            ->assertSee('x-ref="navigationToggle"', false)
            ->assertSee('x-ref="closeNavigation"', false)
            ->assertSee('x-cloak', false)
            ->assertSee('$refs.closeNavigation.focus()', false)
            ->assertSee('$refs.navigationToggle.focus()', false)
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content" tabindex="-1"', false)
            ->assertSee('@keydown.escape.window="if (palette) { palette = false;', false)
            ->assertSee('role="dialog" aria-modal="true" aria-labelledby="command-palette-title"', false)
            ->assertSee('x-trap.inert.noscroll="palette"', false)
            ->assertSee('x-trap.inert.noscroll="menu"', false)
            ->assertSee('h-[100dvh]', false)
            ->assertSee('overflow-y-auto', false)
            ->assertSee('overscroll-contain', false)
            ->assertSee('z-50', false)
            ->assertSee('pb-[max(1rem,env(safe-area-inset-bottom))]', false)
            ->assertSee('data-mobile-account', false)
            ->assertSee('ada@example.test')
            ->assertSee('class="mx-4 mt-4 border-t border-primary pt-4 lg:hidden"', false)
            ->assertSee('action="'.route('logout').'" method="post"', false)
            ->assertSee('button tertiary w-full justify-center', false)
            ->assertSee('@click="if ($event.target.closest(\'a\')) menu = false"', false)
            ->assertSee('aria-label="Account settings"', false)
            ->assertSee('aria-label="Footer navigation"', false)
            ->assertSee(route('activity.index'), false)
            ->assertSee('&copy; '.now()->year.' '.config('app.name'), false)
            ->assertDontSee('href="#"', false)
            ->assertDontSee('Copyright 2020');

        $javascript = File::get(resource_path('js/app.js'));
        $publicJavascript = File::get(resource_path('js/alpine.js'));
        $coreLayout = File::get(resource_path('views/components/layouts/core.blade.php'));
        $this->assertStringNotContainsString("from 'alpinejs'", $javascript);
        $this->assertStringContainsString("import Alpine from 'alpinejs'", $publicJavascript);
        $this->assertStringContainsString('@if (! $livewire)', $coreLayout);
        $this->assertStringContainsString("@vite('resources/js/alpine.js')", $coreLayout);
    }

    public function test_activity_is_discoverable_and_marked_current_in_primary_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('activity.index'))
            ->assertSuccessful()
            ->assertSee('href="'.route('activity.index').'"', false)
            ->assertSee('aria-current="page"', false);

        $this->assertMatchesRegularExpression(
            '/<a href="'.preg_quote(route('activity.index'), '/').'"[^>]*aria-current="page"[^>]*>/s',
            $response->getContent(),
        );
    }

    public function test_every_primary_destination_marks_its_active_link_as_current(): void
    {
        $user = User::factory()->create();
        config(['lessbuild.diagnostics.systemd_timers' => false]);

        foreach ([
            'dashboard',
            'system-health.index',
            'activity.index',
            'commands.index',
            'websites.index',
            'servers.index',
            'builds.index',
            'repositories.index',
            'notifications.index',
            'providers.index',
            'recipes.index',
            'gallery.index',
            'account.index',
        ] as $routeName) {
            $url = route($routeName);
            $html = $this->actingAs($user)->get($url)
                ->assertSuccessful()
                ->getContent();

            $this->assertMatchesRegularExpression(
                '/<a href="'.preg_quote($url, '/').'"[^>]*aria-current="page"[^>]*>/s',
                $html,
                "The {$routeName} sidebar link was not marked as the current page.",
            );
            $dom = new \DOMDocument;
            @$dom->loadHTML($html);
            $xpath = new \DOMXPath($dom);
            foreach (['desktop-navigation', 'primary-navigation'] as $navigation) {
                $current = $xpath->query('//*[@id="'.$navigation.'"]//a[@aria-current="page"]');
                $this->assertCount(1, $current, "The {$navigation} menu must mark exactly one current destination.");
                $this->assertSame($url, $current->item(0)->getAttribute('href'));
            }
        }
    }

    public function test_delete_dialog_openers_are_non_submit_buttons(): void
    {
        foreach ([
            resource_path('views/scenes/websites/show.blade.php'),
            resource_path('views/scenes/repositories/show.blade.php'),
            resource_path('views/scenes/providers/show.blade.php'),
            resource_path('views/livewire/scenes/servers/show.blade.php'),
        ] as $view) {
            $source = File::get($view);

            $this->assertMatchesRegularExpression(
                '/<button\s+type="button"[^>]+\.showModal\(\)/s',
                $source,
                basename($view).' must not submit an enclosing form when it opens a delete dialog.',
            );
        }
    }

    public function test_every_blade_button_declares_its_behavior_explicitly(): void
    {
        foreach (File::allFiles(resource_path('views')) as $file) {
            $source = File::get($file->getPathname());
            preg_match_all('/<button\b[^>]*>/i', $source, $buttons, PREG_OFFSET_CAPTURE);

            foreach ($buttons[0] as [$button, $offset]) {
                $line = substr_count(substr($source, 0, $offset), "\n") + 1;

                $this->assertMatchesRegularExpression(
                    '/\btype\s*=\s*["\'](?:button|submit|reset)["\']/i',
                    $button,
                    $file->getRelativePathname().":{$line} must declare an explicit button type.",
                );
            }
        }
    }

    public function test_confirmations_do_not_interpolate_blade_values_into_javascript_strings(): void
    {
        $views = collect(File::allFiles(resource_path('views')))
            ->map(fn (\SplFileInfo $file): string => File::get($file->getPathname()))
            ->implode("\n");

        $this->assertDoesNotMatchRegularExpression(
            '/confirm\(\s*["\']\s*\{\{/i',
            $views,
        );
    }
}
