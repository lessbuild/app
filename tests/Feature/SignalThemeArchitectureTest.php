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
}
