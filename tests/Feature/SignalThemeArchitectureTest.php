<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SignalThemeArchitectureTest extends TestCase
{
    public function test_all_product_public_and_auth_layouts_share_the_signal_document(): void
    {
        $layouts = [
            'Core workspace' => 'resources/views/components/signal/layouts/platform.blade.php',
            'Deployer' => 'resources/views/components/layouts/app.blade.php',
            'Monitor document adapter' => 'app/Modules/Monitor/Views/components/ui/document.blade.php',
            'Analytics application' => 'app/Modules/Analytics/Views/layouts/app.blade.php',
            'Analytics authentication' => 'app/Modules/Analytics/Views/layouts/auth.blade.php',
            'Buildpusher public homepage' => 'app/Core/Views/marketing/home.blade.php',
            'Buildpusher product pages' => 'app/Core/Views/marketing/product.blade.php',
            'Buildpusher authentication' => 'app/Core/Views/auth/login.blade.php',
        ];

        foreach ($layouts as $name => $path) {
            $source = File::get(base_path($path));

            $this->assertMatchesRegularExpression(
                '/<x-(?:signal\.layouts\.core|layouts\.core|monitor::ui\.document)\b/',
                $source,
                "{$name} must use the shared Signal document layout or its forwarding adapter.",
            );
        }

        $monitorDocument = File::get(base_path('resources/views/components/layouts/core.blade.php'));
        $this->assertStringContainsString('<x-signal.layouts.core', $monitorDocument);
    }

    public function test_shared_document_loads_one_signal_stylesheet_and_theme_runtime(): void
    {
        $source = File::get(base_path('resources/views/components/signal/layouts/core.blade.php'));

        $this->assertStringContainsString("@vite('resources/css/app.css')", $source);
        $this->assertStringContainsString("@vite('resources/js/signal-theme-init.js')", $source);
        $this->assertStringContainsString("@vite('resources/js/signal-theme.js')", $source);
        $this->assertStringContainsString("@vite('resources/js/signal-drawer.js')", $source);
    }

    public function test_product_topbar_uses_the_shared_signal_mobile_sidebar_and_latest_content_gutter(): void
    {
        $topbar = File::get(base_path('resources/views/components/signal/layouts/topbar.blade.php'));
        $mobileNavigation = File::get(base_path('resources/views/components/signal/layouts/mobile-navigation.blade.php'));
        $mobileSidebar = File::get(base_path('resources/views/components/signal/layouts/mobile-sidebar.blade.php'));
        $deployerLayout = File::get(base_path('resources/views/components/layouts/app.blade.php'));
        $coreLayout = File::get(base_path('resources/views/components/signal/layouts/platform.blade.php'));
        $monitorLayout = File::get(base_path('app/Modules/Monitor/Views/layouts/app.blade.php'));
        $analyticsLayout = File::get(base_path('app/Modules/Analytics/Views/layouts/app.blade.php'));

        $this->assertStringContainsString('<x-signal.layouts.mobile-navigation', $topbar);
        $this->assertStringContainsString('class="topbar-nav-link"', $topbar);
        $this->assertStringContainsString('<x-signal.layouts.mobile-sidebar', $mobileNavigation);
        $this->assertStringContainsString('data-mobile-drawer', $mobileSidebar);
        $this->assertStringContainsString('data-mobile-breakpoint="{{ $breakpoint }}"', $mobileSidebar);
        $this->assertStringContainsString('data-mobile-header-selector="[data-mobile-header]"', $mobileSidebar);
        $this->assertStringContainsString('top: var(--signal-mobile-header-height, var(--header-height))', $mobileSidebar);
        $this->assertStringContainsString('bg-surface/90 backdrop-blur" data-mobile-header data-topbar-shell', $topbar);
        $this->assertStringContainsString('class="ui-layout-gutter mx-auto max-w-content"', $topbar);

        foreach ([$deployerLayout, $coreLayout, $monitorLayout, $analyticsLayout] as $layout) {
            $this->assertStringContainsString('ui-layout-gutter mx-auto', $layout);
            $this->assertStringContainsString('max-w-content', $layout);
            $this->assertStringNotContainsString('max-w-screen-2xl', $layout);
        }
    }

    public function test_shared_command_palette_uses_the_current_signal_command_composition(): void
    {
        $palette = File::get(base_path('resources/views/components/signal/layouts/command-palette.blade.php'));
        $styles = File::get(base_path('resources/css/signal/components.css'));

        $this->assertStringContainsString('class="ui-command-list', $palette);
        $this->assertStringContainsString('class="ui-command-section"', $palette);
        $this->assertStringContainsString('class="ui-command-heading"', $palette);
        $this->assertStringContainsString('.ui-command-row', $styles);
        $this->assertStringContainsString('.ui-chart-bar-group', $styles);
        $this->assertStringContainsString('.topbar-nav-link[aria-current="page"]', $styles);
    }
}
