<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SignalThemeTokenFixtureTest extends TestCase
{
    public function test_export_signal_theme_token_fixtures(): void
    {
        $directory = getenv('BROWSER_FIXTURE_DIRECTORY');
        $this->assertNotFalse($directory);

        config(['app.url' => 'http://signal-theme.test']);
        url()->forceRootUrl('http://signal-theme.test');
        $this->withViewErrors([]);
        $this->withVite();
        File::ensureDirectoryExists($directory);

        $products = [
            'core' => 'core',
            'deployer' => 'deployer',
            'monitor' => 'monitor',
            'analytics' => 'analytics',
            'public' => null,
            'auth' => null,
        ];
        $navigation = [
            'groups' => [[
                'label' => 'Workspace',
                'items' => [[
                    'label' => 'Dashboard',
                    'href' => '/dashboard',
                    'active' => true,
                ]],
            ]],
        ];
        $productUrlOverrides = [
            'deployer' => '/theme-token-demo/deployer',
            'monitor' => '/theme-token-demo/monitor',
            'analytics' => '/theme-token-demo/analytics',
        ];

        foreach ($products as $fixture => $productKey) {
            $html = Blade::render(<<<'BLADE'
                <x-signal.layouts.core
                    title="Signal theme token proof"
                    :livewire="false"
                    :product-key="$productKey"
                >
                    @if ($productKey)
                        <x-signal.layouts.topbar
                            :navigation="$navigation"
                            title="Signal theme token proof"
                            :product-key="$productKey"
                            :product-url-overrides="$productUrlOverrides"
                            :show-notifications="false"
                            :show-project-context="false"
                            :show-environment-context="false"
                        />
                    @endif
                    <main class="ui-layout-gutter mx-auto max-w-3xl space-y-5 py-6">
                        <x-signal.ui.page-header
                            eyebrow="Workspace overview"
                            title="Shared theme proof"
                            description="Page titles and actions follow the same Signal theme as the rest of the workspace."
                            :breadcrumbs="$productKey === 'deployer' ? [['label' => 'Workspace', 'href' => '/theme-token-demo/deployer']] : []"
                            data-theme-demo-page-header
                        />
                        <x-signal.ui.panel as="section" class="space-y-5 p-6" data-theme-demo-panel>
                            <x-signal.ui.card tone="muted" class="p-4" data-theme-demo-card>Muted card variant</x-signal.ui.card>
                            <x-signal.ui.button variant="primary" data-theme-demo-button>Shared primary action</x-signal.ui.button>
                            <x-signal.ui.input-field name="email" label="Email address" type="email" data-theme-demo-input />
                        </x-signal.ui.panel>
                    </main>
                </x-signal.layouts.core>
                BLADE,
                ['navigation' => $navigation, 'productKey' => $productKey, 'productUrlOverrides' => $productUrlOverrides],
            );

            File::put($directory."/{$fixture}.html", $html);
        }
    }
}
