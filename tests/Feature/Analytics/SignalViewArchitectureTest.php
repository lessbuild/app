<?php

namespace Tests\Feature\Analytics;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class SignalViewArchitectureTest extends TestCase
{
    public function test_analytics_pages_use_shared_signal_controls_and_surfaces(): void
    {
        $viewsPath = base_path('app/Modules/Analytics/Views');
        $views = collect(File::allFiles($viewsPath))
            ->filter(fn (\SplFileInfo $view): bool => $view->getExtension() === 'php')
            ->reject(fn (\SplFileInfo $view): bool => str_contains($view->getPath(), DIRECTORY_SEPARATOR.'components'))
            ->reject(fn (\SplFileInfo $view): bool => $view->getFilename() === 'welcome.blade.php');

        $this->assertNotEmpty($views);

        foreach ($views as $view) {
            $source = File::get($view->getPathname());
            $relativePath = str_replace(base_path().DIRECTORY_SEPARATOR, '', $view->getPathname());

            $this->assertDoesNotMatchRegularExpression(
                '/<\s*(?:input|select|textarea|button|dialog|pre)\b/i',
                $source,
                "Use a shared Signal control in {$relativePath}.",
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\bclass\s*=\s*(["\'])[^"\']*\bui-(?:panel|btn|input|select)\b/i',
                $source,
                "Use a shared Signal surface or control variant in {$relativePath}.",
            );
        }
    }

    public function test_analytics_product_and_auth_layouts_share_signal_shells(): void
    {
        $productLayout = File::get(base_path('app/Modules/Analytics/Views/layouts/app.blade.php'));
        $authLayout = File::get(base_path('app/Modules/Analytics/Views/layouts/auth.blade.php'));

        $this->assertStringContainsString('<x-signal.layouts.topbar', $productLayout);
        $this->assertStringContainsString('<x-signal.layouts.core', $authLayout);
    }

    public function test_signal_code_block_escapes_the_sample_as_text(): void
    {
        $html = Blade::render(<<<'BLADE'
            @php($snippet = '<script>alert(1)</script>')
            <x-signal.ui.code-block :code="$snippet" />
            BLADE,
        );

        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)', $html);
    }
}
