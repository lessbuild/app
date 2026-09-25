<?php

namespace Tests\Feature\Core;

use Tests\TestCase;

final class WorkspaceSwitcherArchitectureTest extends TestCase
{
    public function test_desktop_and_mobile_navigation_share_the_accessible_workspace_dropdown(): void
    {
        $component = file_get_contents(resource_path('views/components/signal/layouts/workspace-switcher.blade.php'));
        $topbar = file_get_contents(resource_path('views/components/signal/layouts/topbar.blade.php'));
        $mobileNavigation = file_get_contents(resource_path('views/components/signal/layouts/mobile-navigation.blade.php'));

        $this->assertIsString($component);
        $this->assertIsString($topbar);
        $this->assertIsString($mobileNavigation);
        $this->assertStringContainsString('<details', $component);
        $this->assertStringContainsString('data-workspace-switcher', $component);
        $this->assertStringContainsString('aria-label="{{ __(\'Switch workspace\') }}"', $component);
        $this->assertStringContainsString('<x-signal.layouts.workspace-switcher', $topbar);
        $this->assertStringContainsString('<x-signal.layouts.workspace-switcher', $mobileNavigation);
        $this->assertStringNotContainsString('@foreach ($workspaceOptions as $workspace)', $mobileNavigation);
    }
}
