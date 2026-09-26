<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
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

    public function test_product_roots_resolve_to_dashboards_and_legacy_welcome_pages_are_removed(): void
    {
        $deployerRoutes = File::get(base_path('app/Modules/Deployer/Routes/web.php'));
        $monitorRoutes = File::get(base_path('app/Modules/Monitor/Routes/web.php'));
        $analyticsRoutes = File::get(base_path('app/Modules/Analytics/Routes/web.php'));

        $this->assertStringContainsString("Route::redirect('/', '/home')->name('entry')", $deployerRoutes);
        $this->assertStringContainsString("Route::get('home', DashboardController::class)->name('dashboard')", $deployerRoutes);
        $this->assertStringContainsString("Route::get('/', [DashboardController::class, 'index'])->name('dashboard')", $monitorRoutes);
        $this->assertStringContainsString("Route::redirect('/', '/dashboard')->name('home')", $analyticsRoutes);
        $this->assertFileDoesNotExist(base_path('app/Modules/Monitor/Views/welcome.blade.php'));
        $this->assertFileDoesNotExist(base_path('app/Modules/Analytics/Views/welcome.blade.php'));
    }

    public function test_shared_document_loads_one_signal_stylesheet_and_theme_runtime(): void
    {
        $source = File::get(base_path('resources/views/components/signal/layouts/core.blade.php'));

        $this->assertStringContainsString("@vite('resources/css/app.css')", $source);
        $this->assertStringContainsString("@vite('resources/js/signal-theme-init.js')", $source);
        $this->assertStringContainsString("@vite('resources/js/signal-theme.js')", $source);
        $this->assertStringContainsString("@vite('resources/js/signal-drawer.js')", $source);
        $this->assertStringContainsString("@vite('resources/js/signal-marketing.js')", $source);
        $this->assertStringContainsString("'interactiveMarketing' => false", $source);
    }

    public function test_deployer_compatibility_layout_renders_the_shared_signal_saas_shell(): void
    {
        $layout = File::get(base_path('resources/views/components/layouts/app.blade.php'));

        $this->assertStringContainsString('<x-signal.layouts.core', $layout);
        $this->assertStringContainsString('<x-signal.layouts.topbar', $layout);
        $this->assertStringContainsString('<x-signal.layouts.command-palette', $layout);
        $this->assertStringContainsString('ui-layout-gutter mx-auto w-full max-w-content', $layout);
        $this->assertStringNotContainsString('<x-layouts.sidebar', $layout);
        $this->assertStringNotContainsString('<x-layouts.topbar', $layout);
    }

    public function test_deployer_views_use_signal_components_for_surfaces_and_form_controls(): void
    {
        $rawSurfacePattern = '/<(?:a|div|section|article|aside|form|fieldset|details|li|p)\b[^>]*class="[^"]*\bui-(?:card|panel)\b[^"]*"/s';

        foreach (File::allFiles(base_path('app/Modules/Deployer/Views')) as $view) {
            $source = File::get($view->getPathname());
            $viewName = $view->getRelativePathname();

            $this->assertDoesNotMatchRegularExpression(
                '/<x-(?:ui|dialogs)\./',
                $source,
                "{$viewName} must call the current Signal component namespace directly.",
            );
            $this->assertDoesNotMatchRegularExpression(
                $rawSurfacePattern,
                $source,
                "{$viewName} must compose Signal card/panel surfaces through Blade components.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:dialog|x-(?:ui|dialogs)\.)\b/i',
                $source,
                "{$viewName} must compose overlays from the shared Signal namespace.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:button|input|select|textarea)\b/i',
                $source,
                "{$viewName} must compose form controls through Signal Blade components.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<(?!x-signal\.)[^>]*\bui-(?:alert(?:--?[\w-]+)?|badge(?:--?[\w-]+)?|progress|status-dot(?:-lg)?|stat)\b/s',
                $source,
                "{$viewName} must compose status, feedback, progress, and metric primitives through Signal Blade components.",
            );
        }
    }

    public function test_monitor_views_use_shared_signal_components_for_surfaces_and_form_controls(): void
    {
        $rawSurfacePattern = '/<(?:a|div|section|article|aside|form|fieldset|details|li|p)\b[^>]*class="[^"]*\bui-(?:card|panel)\b[^"]*"/s';

        foreach (File::allFiles(base_path('app/Modules/Monitor/Views')) as $view) {
            $source = File::get($view->getPathname());
            $viewName = $view->getRelativePathname();

            $this->assertDoesNotMatchRegularExpression(
                $rawSurfacePattern,
                $source,
                "{$viewName} must compose Signal card/panel surfaces through Blade components.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<(?!x-signal\.ui\.(?:card|panel))[^>]*\bui-(?:card|panel)\b/s',
                $source,
                "{$viewName} must not define a product-owned card or panel surface.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:button|input|select|textarea|dialog)\b/i',
                $source,
                "{$viewName} must compose controls and overlays through Signal Blade components.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<(?!x-signal\.ui\.(?:alert|badge|progress))[^>]*\bui-(?:alert(?:-[\w-]+)?|badge(?:-[\w-]+)?|progress)\b/s',
                $source,
                "{$viewName} must compose status, feedback, and progress primitives through Signal Blade components.",
            );
        }
    }

    public function test_analytics_views_use_shared_signal_components_for_surfaces_and_controls(): void
    {
        $rawSurfacePattern = '/<(?:a|div|section|article|aside|form|fieldset|details|li|p)\b[^>]*class="[^"]*\bui-(?:card|panel)\b[^\"]*"/s';

        foreach (File::allFiles(base_path('app/Modules/Analytics/Views')) as $view) {
            $source = File::get($view->getPathname());
            $viewName = $view->getRelativePathname();

            $this->assertDoesNotMatchRegularExpression(
                $rawSurfacePattern,
                $source,
                "{$viewName} must compose Signal card/panel surfaces through Blade components.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<(?!x-signal\.ui\.(?:alert|badge|progress|status-dot|stat))[^>]*\bui-(?:alert(?:--?[\w-]+)?|badge(?:--?[\w-]+)?|progress|status-dot(?:-lg)?|stat)\b/s',
                $source,
                "{$viewName} must compose status, feedback, progress, and metric primitives through Signal Blade components.",
            );
            $this->assertDoesNotMatchRegularExpression(
                '/<(?:button|input|select|textarea|dialog|x-(?:ui|dialogs)\.)\b/i',
                $source,
                "{$viewName} must use shared Signal components for controls and overlays.",
            );
        }
    }

    public function test_core_views_use_shared_signal_components_for_surfaces_and_controls(): void
    {
        $rawSurfacePattern = '/<(?:a|div|section|article|aside|form|fieldset|details|li|p)\b[^>]*class="[^"]*\bui-(?:card|panel)\b[^"]*"/s';
        $viewDirectories = [
            'app/Core/Views',
            'resources/views/core',
        ];

        foreach ($viewDirectories as $directory) {
            foreach (File::allFiles(base_path($directory)) as $view) {
                $source = File::get($view->getPathname());
                $viewName = $view->getRelativePathname();

                $this->assertDoesNotMatchRegularExpression(
                    $rawSurfacePattern,
                    $source,
                    "{$viewName} must compose Signal card/panel surfaces through Blade components.",
                );
                $this->assertDoesNotMatchRegularExpression(
                    '/<(?!x-signal\.ui\.(?:alert|badge|progress|status-dot|stat))[^>]*\bui-(?:alert(?:--?[\w-]+)?|badge(?:--?[\w-]+)?|progress|status-dot(?:-lg)?|stat)\b/s',
                    $source,
                    "{$viewName} must compose status, feedback, progress, and metric primitives through Signal Blade components.",
                );
                $this->assertDoesNotMatchRegularExpression(
                    '/<(?:button|input|select|textarea|dialog|x-(?:ui|dialogs)\.)\b/i',
                    $source,
                    "{$viewName} must use shared Signal components for controls and overlays.",
                );
            }
        }
    }

    public function test_signal_progress_component_clamps_values_and_exposes_meter_accessibility(): void
    {
        $meter = Blade::render('<x-signal.ui.progress role="meter" label="Monthly usage" value="-5" class="mt-3" />');
        $complete = Blade::render('<x-signal.ui.progress label="Setup progress" value="150" />');
        $overLimit = Blade::render('<x-signal.ui.progress label="Resource usage" value="100" bar-class="bg-danger" />');

        $this->assertStringContainsString('role="meter"', $meter);
        $this->assertStringContainsString('aria-label="Monthly usage"', $meter);
        $this->assertStringContainsString('aria-valuemin="0"', $meter);
        $this->assertStringContainsString('aria-valuemax="100"', $meter);
        $this->assertStringContainsString('aria-valuenow="0"', $meter);
        $this->assertStringContainsString('ui-progress', $meter);
        $this->assertStringContainsString('aria-valuenow="100"', $complete);
        $this->assertStringContainsString('style="width: 100%"', $complete);
        $this->assertStringContainsString('class="bg-danger"', $overLimit);
    }

    public function test_signal_alert_component_preserves_semantic_tag_and_tone(): void
    {
        $html = Blade::render('<x-signal.ui.alert as="section" tone="warning" role="status" class="mt-3">Review this setting.</x-signal.ui.alert>');

        $this->assertMatchesRegularExpression('/<section\b[^>]*role="status"[^>]*class="[^"]*ui-alert--warning/', $html);
        $this->assertStringContainsString('Review this setting.', $html);
    }

    public function test_signal_status_and_metric_components_support_themable_variants(): void
    {
        $dot = Blade::render('<x-signal.ui.status-dot size="lg" color="var(--ui-success)" aria-hidden="true" />');
        $stat = Blade::render('<x-signal.ui.stat as="div" slot-mode class="p-3"><span>Deployments</span></x-signal.ui.stat>');
        $emptyMetric = Blade::render('<x-signal.ui.stat label="Latest event" :value="null" />');

        $this->assertStringContainsString('class="ui-status-dot ui-status-dot-lg"', $dot);
        $this->assertStringContainsString('--ui-status-dot: var(--ui-success)', $dot);
        $this->assertStringContainsString('class="ui-stat p-3"', $stat);
        $this->assertStringContainsString('<span>Deployments</span>', $stat);
        $this->assertStringContainsString('Latest event', $emptyMetric);
    }

    public function test_deployer_and_shared_shells_use_signal_components_without_compatibility_aliases(): void
    {
        $views = [
            'Deployer application shell' => 'resources/views/components/layouts/app.blade.php',
            'Shared authentication shell' => 'resources/views/components/layouts/auth.blade.php',
            'Shared public navigation' => 'resources/views/components/layouts/public-header.blade.php',
            'Shared product topbar' => 'resources/views/components/signal/layouts/topbar.blade.php',
            'Shared mobile navigation' => 'resources/views/components/signal/layouts/mobile-navigation.blade.php',
            'Deployer mobile navigation' => 'resources/views/components/signal/layouts/mobile-quick-navigation.blade.php',
        ];

        foreach ($views as $name => $path) {
            $source = File::get(base_path($path));

            $this->assertDoesNotMatchRegularExpression('/<x-(?:ui|dialogs)\./', $source, "{$name} should call Signal components directly.");
            $this->assertDoesNotMatchRegularExpression('/<(?:button|input|select|textarea)\b/i', $source, "{$name} should compose controls through Signal components.");
        }

        $deployerShell = File::get(base_path($views['Deployer application shell']));
        $this->assertStringContainsString('<x-signal.overlays.modal', $deployerShell);
        $this->assertStringContainsString('<x-signal.layouts.mobile-quick-navigation', $deployerShell);
        $this->assertStringContainsString('<x-signal.ui.flash-messages', $deployerShell);
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
        $this->assertStringContainsString('class="ui-icon-btn shrink-0 xl:hidden"', $topbar);
        $this->assertStringContainsString('ui-horizontal-scroll hidden min-w-0 flex-1 items-center gap-1 overflow-x-auto pl-2 xl:flex', $topbar);
        $this->assertStringContainsString(':breakpoint="1280"', $mobileNavigation);
        $this->assertStringContainsString("'xl:hidden' => \$breakpoint >= 1280", $mobileSidebar);

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
