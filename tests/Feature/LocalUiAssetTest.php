<?php

namespace Tests\Feature;

use App\Modules\Deployer\Models\User;
use App\Modules\Deployer\View\Navigation\WorkspaceNavigation;
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

    public function test_signal_checkbox_keeps_unchecked_fallback_and_accessible_validation_feedback(): void
    {
        $this->withViewErrors([
            'name' => 'Choose an environment name.',
            'is_protected' => 'Choose whether this environment is protected.',
        ]);

        $html = Blade::render(<<<'BLADE'
            <x-signal.ui.input-field id="environment-name" name="name" label="Name" field-class="sm:col-span-2" required />
            <x-signal.ui.checkbox id="environment-is-protected" name="is_protected" :checked="true" :restore="false" unchecked-value="0" description="Stop unapproved production deploys.">
                Protect production
            </x-signal.ui.checkbox>
            <x-signal.ui.checkbox id="custom-invalid-checkbox" name="custom_flag" :checked="true" :restore="false" :error-key="false" :show-errors="false" aria-invalid="true" aria-describedby="external-checkbox-help">
                Custom invalid checkbox
            </x-signal.ui.checkbox>
            BLADE,
        );

        $this->assertStringContainsString('class="grid min-w-0 gap-2 sm:col-span-2"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('environment-name-error', $html);
        $this->assertStringContainsString('Choose an environment name.', $html);
        $this->assertStringContainsString('<input type="hidden" name="is_protected" value="0">', $html);
        $this->assertStringContainsString('id="environment-is-protected-help"', $html);
        $this->assertStringContainsString('environment-is-protected-help environment-is-protected-error', $html);
        $this->assertStringContainsString('id="environment-is-protected-error"', $html);
        $this->assertStringContainsString('Choose whether this environment is protected.', $html);
        $this->assertStringContainsString('aria-describedby="external-checkbox-help"', $html);
        preg_match('/<input\b(?=[^>]*id="environment-is-protected")(?=[^>]*name="is_protected")(?=[^>]*type="checkbox")[^>]*>/', $html, $checkbox);
        $this->assertArrayHasKey(0, $checkbox);
        $this->assertStringContainsString('checked', $checkbox[0]);
        $this->assertStringContainsString('aria-invalid="true"', $checkbox[0]);
        preg_match('/<input\b(?=[^>]*id="custom-invalid-checkbox")(?=[^>]*name="custom_flag")(?=[^>]*type="checkbox")[^>]*>/', $html, $customCheckbox);
        $this->assertArrayHasKey(0, $customCheckbox);
        $this->assertStringContainsString('aria-invalid="true"', $customCheckbox[0]);
    }

    public function test_signal_controls_cover_transport_inputs_dynamic_buttons_and_livewire_dialog_shells(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-signal.ui.input id="deployment-mode" name="deployment_mode" type="radio" value="manual" checked :restore="false" />
            <x-signal.ui.input name="operation_token" type="hidden" value="signed-token" :restore="false" />
            <x-signal.ui.button type="button" variant="stateful" size="sm" x-bind:class="selected ? 'ui-btn-primary' : 'ui-btn-quiet'">Monthly</x-signal.ui.button>
            <x-signal.ui.button type="submit" variant="link">Remove</x-signal.ui.button>
            <x-signal.overlays.dialog-shell id="server-command-dialog" :open="true" class="ui-command-dialog" data-livewire-dialog aria-labelledby="server-command-dialog-title">
                <h2 id="server-command-dialog-title">Run command</h2>
            </x-signal.overlays.dialog-shell>
            BLADE,
        );

        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*\bid="deployment-mode")(?=[^>]*\bname="deployment_mode")(?=[^>]*\btype="radio")(?=[^>]*\bvalue="manual")(?=[^>]*\bchecked)[^>]*class="[^"]*ui-check[^\"]*"[^>]*>/s',
            $html,
        );
        $this->assertMatchesRegularExpression(
            '/<input(?=[^>]*\bname="operation_token")(?=[^>]*\btype="hidden")(?=[^>]*\bvalue="signed-token")[^>]*>/s',
            $html,
        );
        $this->assertStringNotContainsString('id="operation_token"', $html);
        $this->assertStringContainsString('x-bind:class="selected ?', $html);
        $this->assertMatchesRegularExpression('/<button type="button"[^>]*class="ui-btn ui-btn-sm"[^>]*x-bind:class=/s', $html);
        $this->assertStringContainsString('class="ui-link"', $html);
        $this->assertMatchesRegularExpression('/<dialog\s+id="server-command-dialog"/s', $html);
        $this->assertStringContainsString('data-modal-initial-open="true"', $html);
        $this->assertStringContainsString('data-livewire-dialog', $html);
        $this->assertStringContainsString('aria-labelledby="server-command-dialog-title"', $html);
    }

    public function test_signal_input_addons_keep_validation_associations_and_joined_control_edges(): void
    {
        $this->withViewErrors(['url' => 'Enter a website URL.']);

        $html = Blade::render(<<<'BLADE'
            <x-signal.ui.input-field id="website-url" name="url" label="Website URL" value="example.test" description="The host for this website.">
                <x-slot:prefix><x-signal.ui.input-addon>http://</x-signal.ui.input-addon></x-slot:prefix>
            </x-signal.ui.input-field>
            BLADE,
        );

        $this->assertStringContainsString('http://', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('rounded-control rounded-r-none border-r-0', $html);
        $this->assertStringContainsString('rounded-l-none', $html);
        $this->assertStringContainsString('value="example.test"', $html);
        $this->assertStringContainsString('aria-describedby="website-url-help website-url-error"', $html);
        $this->assertStringContainsString('Enter a website URL.', $html);

        $suffixHtml = Blade::render(<<<'BLADE'
            <x-signal.ui.input-field id="website-domain" name="domain" label="Domain" value="example" hide-label>
                <x-slot:suffix><x-signal.ui.input-addon position="suffix">.test</x-signal.ui.input-addon></x-slot:suffix>
            </x-signal.ui.input-field>
            BLADE,
        );

        $this->assertStringContainsString('.test', $suffixHtml);
        $this->assertStringContainsString('rounded-control rounded-l-none border-l-0', $suffixHtml);
        $this->assertStringContainsString('rounded-r-none', $suffixHtml);
        $this->assertStringContainsString('for="website-domain"', $suffixHtml);
        $this->assertStringContainsString('sr-only', $suffixHtml);
    }

    public function test_signal_textarea_fields_associate_indexed_validation_errors(): void
    {
        $this->withViewErrors(['paths.0' => 'Use a repository-relative path.']);

        $html = Blade::render(<<<'BLADE'
            <x-signal.ui.textarea-field id="repository-paths" name="paths" error-key="paths.*" label="Include paths" value="apps/**" description="One path per line." />
            BLADE,
        );

        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('aria-describedby="repository-paths-help repository-paths-error"', $html);
        $this->assertStringContainsString('id="repository-paths-error"', $html);
        $this->assertStringContainsString('Use a repository-relative path.', $html);
    }

    public function test_public_and_authenticated_layouts_render_without_remote_visual_assets(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

        $this->get('/')
            ->assertSuccessful()
            ->assertSee('One workspace for the work behind your software.')
            ->assertSee('The Buildpusher apps')
            ->assertSee('Projects carry across apps')
            ->assertSee('Deployer')
            ->assertSee('Monitor')
            ->assertSee('Analytics')
            ->assertSee('bg-surface-muted', false)
            ->assertSee('text-emphasis-ink', false)
            ->assertDontSee('i.imgur.com', false)
            ->assertDontSee('gopayee.test', false);

        $this->get(route('login'))
            ->assertSuccessful()
            ->assertSee('Deploy with confidence')
            ->assertSee('ui-panel', false)
            ->assertSee('data-auth-brand', false)
            ->assertDontSee('ui-auth-shell', false)
            ->assertDontSee('ui-auth-aside', false)
            ->assertSee('data-theme-toggle', false)
            ->assertDontSee('fonts.googleapis.com', false)
            ->assertDontSee('cdnjs.cloudflare.com', false);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('AL')
            ->assertSee('Send feedback')
            ->assertSee('data-network-status', false)
            ->assertSee('data-offline-message=', false)
            ->assertSee('data-online-message=', false)
            ->assertDontSee('ui-avatars.com', false);
    }

    public function test_signal_public_theme_source_and_application_component_adaptations_are_pinned(): void
    {
        foreach ([
            'css/signal/theme.css' => 'fa483deb8b7d06921f9b3fa975e82f5813ecbe240ea0cc3d0568340d2d8d31e3',
            'css/signal/components.css' => 'e403ef10109308fd58c7093b61e5ff4559ecce70d7721ab5eca0a421d57caad6',
            'css/signal/themes.json' => 'abb484b2897b144830e45e7f51f34a420972ba0371b47676880d4664b74b6872',
        ] as $relativePath => $expectedHash) {
            $this->assertSame(
                $expectedHash,
                hash_file('sha256', resource_path($relativePath)),
                $relativePath.' must match its reviewed upstream source or application adaptation.',
            );
        }

        $html = Blade::render(
            <<<'BLADE'
            <x-signal.blocks.cta eyebrow="Release" title="Ship safely" copy="Trace every step." href="/deployments" label="View releases" />
            <x-signal.blocks.faq-list :items="$items" />
            BLADE,
            ['items' => [['q' => 'Who uses this?', 'a' => 'Teams who ship software.']]],
        );

        $this->assertStringContainsString('ui-emphasis relative overflow-hidden rounded-panel p-6 sm:p-10', $html);
        $this->assertStringContainsString('ui-btn ui-btn-primary ui-btn-lg shrink-0', $html);
        $this->assertStringContainsString('href="/deployments"', $html);
        $this->assertStringContainsString('group rounded-card border border-line bg-surface p-4', $html);
        $this->assertStringContainsString('Who uses this?', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function test_signal_site_footer_uses_the_shared_layout_and_product_navigation(): void
    {
        $html = Blade::render(
            <<<'BLADE'
            <x-signal.site-footer
                description="One clear control plane."
                :explore-links="$links"
                closing-eyebrow="Keep shipping clearly"
                closing-copy="Review the product guide."
                action-href="/login"
                action-label="Open the workspace"
            />
            BLADE,
            ['links' => [
                ['label' => 'Product', 'href' => '#product'],
                ['label' => 'Docs', 'href' => '/docs?from=footer&tab=guide'],
            ]],
        );

        $this->assertStringContainsString('border-t border-line bg-surface', $html);
        $this->assertStringContainsString('grid max-w-content gap-10 px-5 py-12 sm:px-8 md:grid-cols-[1.4fr_1fr_1fr] md:py-16', $html);
        $this->assertStringContainsString('aria-label="Footer navigation"', $html);
        $this->assertStringContainsString('href="#product"', $html);
        $this->assertStringContainsString('href="/docs?from=footer&amp;tab=guide"', $html);
        $this->assertStringContainsString('href="/login"', $html);
        $this->assertStringContainsString('Open the workspace', $html);
        $this->assertStringContainsString((string) now()->year, $html);
    }

    public function test_shared_resource_headers_and_local_navigation_have_accessible_structure(): void
    {
        $html = Blade::render(
            <<<'BLADE'
            <x-layouts.partials.heading eyebrow="Infrastructure" icon="server" title="Servers" description="Manage capacity.">
                <x-slot:buttons><x-ui.button href="/servers/create" variant="primary">Add server</x-ui.button></x-slot:buttons>
            </x-layouts.partials.heading>
            <x-ui.local-nav label="Server sections">
                <a class="ui-local-nav__link" href="#inventory">Inventory</a>
            </x-ui.local-nav>
            BLADE,
        );

        $this->assertStringContainsString('data-ui-page-header', $html);
        $this->assertStringContainsString('ui-page-header__eyebrow', $html);
        $this->assertStringContainsString('ui-eyebrow', $html);
        $this->assertStringContainsString('text-3xl font-extrabold tracking-tight text-ink', $html);
        $this->assertStringNotContainsString('ui-panel', $html);
        $this->assertStringContainsString('Infrastructure', $html);
        $this->assertStringContainsString('data-ui-page-header-actions', $html);
        $this->assertStringContainsString('aria-label="Server sections"', $html);
        $this->assertStringContainsString('ui-local-nav__scroll', $html);
        $this->assertStringContainsString('href="#inventory"', $html);
    }

    public function test_latest_signal_page_header_supports_breadcrumbs_and_deployer_uses_it_directly(): void
    {
        $html = Blade::render(
            <<<'BLADE'
            <x-signal.ui.page-header
                id="servers-page-header"
                title-id="servers-title"
                eyebrow="Infrastructure"
                title="Servers"
                description="Manage capacity."
                :breadcrumbs="[['label' => 'Deployer', 'href' => '/'], ['label' => 'Infrastructure', 'href' => '/infrastructure']]"
            >
                <x-slot:actions><x-signal.ui.button href="/servers/create" variant="primary">Add server</x-signal.ui.button></x-slot:actions>
            </x-signal.ui.page-header>
            BLADE,
        );

        $this->assertStringContainsString('id="servers-page-header"', $html);
        $this->assertStringContainsString('id="servers-title"', $html);
        $this->assertStringContainsString('data-page-header', $html);
        $this->assertStringContainsString('aria-label="Breadcrumb"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('border-b border-line pb-6', $html);
        $this->assertStringContainsString('data-page-actions', $html);
        $this->assertStringContainsString('Add server', $html);

        $deployerViews = collect(File::allFiles(app_path('Modules/Deployer/Views')))
            ->map(fn ($file) => File::get($file->getPathname()))
            ->implode("\n");
        $this->assertStringNotContainsString('x-layouts.partials.heading', $deployerViews);
        $this->assertGreaterThan(45, substr_count($deployerViews, '<x-signal.ui.page-header'));
        $this->assertStringNotContainsString('<x-dialogs.', $deployerViews);
        $this->assertStringNotContainsString('<x-avatar ', $deployerViews);
        $this->assertGreaterThan(70, substr_count($deployerViews, '<x-signal.overlays.modal'));

        $dashboard = File::get(app_path('Modules/Deployer/Views/dashboard.blade.php'));
        $this->assertStringContainsString('<x-signal.ui.page-header', $dashboard);
        $this->assertStringContainsString('<x-slot:actions>', $dashboard);
        $this->assertStringNotContainsString('<h1 id="dashboard-title"', $dashboard);
    }

    public function test_latest_signal_side_sheet_is_a_shared_component_and_trigger(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-signal.overlays.side-sheet-trigger sheet="environment-sheet">Browse environments</x-signal.overlays.side-sheet-trigger>
            <x-signal.overlays.side-sheet id="environment-sheet" eyebrow="Storefront" title="Environments" description="Jump to a deployment target.">
                <nav aria-label="Project environments"><a href="#production" data-sheet-close>Production</a></nav>
            </x-signal.overlays.side-sheet>
            BLADE,
        );

        $this->assertStringContainsString('data-sheet-open="environment-sheet"', $html);
        $this->assertStringContainsString('aria-controls="environment-sheet"', $html);
        $this->assertStringContainsString('aria-expanded="false"', $html);
        $this->assertStringContainsString('id="environment-sheet"', $html);
        $this->assertStringContainsString('role="dialog"', $html);
        $this->assertStringContainsString('aria-modal="true"', $html);
        $this->assertStringContainsString('aria-describedby="environment-sheet-description"', $html);
        $this->assertStringContainsString('data-sheet-close', $html);
        $this->assertStringContainsString('class="ui-sheet hidden', $html);

        $coreLayout = File::get(resource_path('views/components/signal/layouts/core.blade.php'));
        $this->assertStringContainsString("@vite('resources/js/signal-overlays.js')", $coreLayout);
    }

    public function test_mobile_dialog_and_filter_primitives_expose_native_sheet_hooks(): void
    {
        $modal = File::get(resource_path('views/components/signal/overlays/modal.blade.php'));
        $filter = File::get(resource_path('views/components/signal/ui/filter-panel.blade.php'));
        $commandPalette = File::get(resource_path('views/components/signal/layouts/command-palette.blade.php'));

        $this->assertStringContainsString('data-modal-sheet', $modal);
        $this->assertStringContainsString('data-modal-sheet', $commandPalette);
        $this->assertStringContainsString('data-modal-panel', $modal);
        $this->assertStringContainsString('data-modal-header', $modal);
        $this->assertStringContainsString('data-modal-body', $modal);
        $this->assertStringContainsString("class(['ui-dialog'])", $modal);
        $this->assertStringNotContainsString('ui-modal', $modal);
        $this->assertStringContainsString('data-filter-dialog', $filter);
        $this->assertStringContainsString('data-filter-dialog-trigger', $filter);
        $this->assertStringContainsString('data-filter-dialog-close', $filter);
        $this->assertStringContainsString("['ui-dialog', 'ui-filter-dialog']", $filter);
        $this->assertStringContainsString('data-filter-panel', $filter);
        $this->assertStringContainsString('data-filter-dialog-body', $filter);

        $appStyles = File::get(resource_path('css/app.css'));
        $this->assertStringContainsString('html:has(dialog[data-modal-sheet][open]:not([data-filter-dialog]))', $appStyles);
        $this->assertStringContainsString('html:not([data-modal-js-ready]) dialog.ui-filter-dialog', $appStyles);
        $this->assertStringContainsString('body[data-modal-open]', $appStyles);
        $this->assertStringNotContainsString('ui-modal', $appStyles);

        $componentStyles = File::get(resource_path('css/components/ui.css'));
        $this->assertStringContainsString('.ui-dialog[data-modal-sheet]', $componentStyles);
        $this->assertStringContainsString('display: none;', $componentStyles);
        $this->assertStringContainsString('.ui-dialog[data-modal-sheet][open]', $componentStyles);
        $this->assertStringContainsString('[data-filter-dialog-body]', $componentStyles);
    }

    public function test_shared_signal_controls_use_the_source_icon_and_radius_primitives(): void
    {
        foreach ([
            resource_path('views/components/signal/overlays/modal.blade.php'),
            resource_path('views/components/signal/ui/filter-panel.blade.php'),
            resource_path('views/components/layouts/public-header.blade.php'),
            resource_path('views/components/layouts/mobile-navigation.blade.php'),
            resource_path('views/livewire/scenes/servers/command.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringContainsString('/assets/images/icons.svg#close', $source, $viewPath);
            $this->assertStringNotContainsString('aria-hidden="true">×</span>', $source, $viewPath);
        }

        $emptyState = File::get(resource_path('views/components/signal/ui/empty-state.blade.php'));

        $this->assertStringContainsString('rounded-card bg-primary-soft', $emptyState);
        $this->assertStringNotContainsString('rounded-2xl', $emptyState);
        $this->assertStringContainsString('id="close"', File::get(public_path('assets/images/icons.svg')));
    }

    public function test_configuration_surfaces_use_signal_card_radius_tokens(): void
    {
        foreach ([
            resource_path('views/scenes/projects/configuration.blade.php'),
            resource_path('views/scenes/projects/configuration-dialog.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringContainsString('rounded-card', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-lg', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-xl', $source, $viewPath);
        }
    }

    public function test_observability_surfaces_use_signal_card_radius_tokens(): void
    {
        foreach ([
            resource_path('views/observability/index.blade.php'),
            resource_path('views/observability/environment-context.blade.php'),
            resource_path('views/observability/_operational-incident-card.blade.php'),
            resource_path('views/observability/_operational-incidents.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringContainsString('rounded-card', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-lg', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-xl', $source, $viewPath);
        }
    }

    public function test_native_disclosure_focus_targets_use_signal_control_radius(): void
    {
        foreach ([
            resource_path('views/notifications/index.blade.php'),
            resource_path('views/observability/index.blade.php'),
            resource_path('views/observability/environment-context.blade.php'),
            resource_path('views/observability/_operational-incidents.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('rounded-md', $source, $viewPath);
            $this->assertGreaterThan(0, preg_match_all('/<summary[^>]*class="[^"]*rounded-control[^"]*focus-visible:ring-2/', $source), $viewPath);
        }
    }

    public function test_deployment_detail_surfaces_use_signal_card_radius_tokens(): void
    {
        foreach ([
            resource_path('views/scenes/repositories/show.blade.php'),
            resource_path('views/livewire/scenes/servers/show.blade.php'),
            resource_path('views/scenes/servers/commands.blade.php'),
            resource_path('views/components/scenes/repositories/webhook-delivery-content.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringContainsString('rounded-card', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-lg', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-xl', $source, $viewPath);
        }
    }

    public function test_evidence_content_surfaces_use_signal_card_radius_tokens(): void
    {
        foreach ([
            resource_path('views/components/scenes/builds/comparison-content.blade.php'),
            resource_path('views/scenes/gallery/compare.blade.php'),
            resource_path('views/components/scenes/servers/command-history-content.blade.php'),
            resource_path('views/feedback/index.blade.php'),
            resource_path('views/livewire/build-deployment-status.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringContainsString('rounded-card', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-lg', $source, $viewPath);
        }
    }

    public function test_remaining_controls_and_detail_surfaces_use_signal_primitives(): void
    {
        $choices = [
            resource_path('views/components/scenes/observability/status-page-create-dialog.blade.php'),
        ];

        foreach ($choices as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringContainsString('ui-choice', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-lg', $source, $viewPath);
            $this->assertStringNotContainsString('rounded-xl', $source, $viewPath);
        }

        $deploymentControls = File::get(resource_path('views/components/scenes/projects/deployment-controls-dialog.blade.php'));
        $this->assertStringContainsString('<x-signal.ui.input-field', $deploymentControls);
        $this->assertStringContainsString('<x-signal.ui.select-field', $deploymentControls);
        $this->assertStringContainsString('<x-signal.ui.checkbox', $deploymentControls);
        $this->assertStringContainsString('<x-signal.ui.button', $deploymentControls);

        $search = File::get(resource_path('views/search/_workspace-results.blade.php'));
        $billing = File::get(resource_path('views/scenes/billing/index.blade.php'));
        $projects = File::get(resource_path('views/scenes/projects/show.blade.php'));
        $loadBalancers = File::get(resource_path('views/load-balancers/index.blade.php'));
        $users = File::get(resource_path('views/scenes/users/index.blade.php'));

        $this->assertStringContainsString('ui-command-item', $search);
        $this->assertStringContainsString('rounded-card', $search);
        $this->assertStringContainsString('rounded-control', $billing);
        $this->assertStringContainsString('rounded-control', $projects);
        $this->assertStringContainsString('rounded-card', $loadBalancers);
        $this->assertStringContainsString('rounded-card', $users);

        foreach ([$search, $billing, $projects, $loadBalancers, $users] as $source) {
            $this->assertStringNotContainsString('rounded-lg', $source);
        }
    }

    public function test_inventory_avatars_and_checkboxes_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/scenes/websites/index.blade.php') => 'ui-avatar-md',
            resource_path('views/scenes/websites/show.blade.php') => 'ui-avatar-sm',
            resource_path('views/scenes/builds/index.blade.php') => 'ui-avatar-md',
            resource_path('views/scenes/repositories/index.blade.php') => 'ui-avatar-md',
            resource_path('views/scenes/providers/index.blade.php') => 'ui-avatar-md',
            resource_path('views/scenes/providers/show.blade.php') => 'ui-avatar-md',
            resource_path('views/scenes/servers/index.blade.php') => 'ui-avatar-md',
            resource_path('views/livewire/scenes/servers/show.blade.php') => 'ui-avatar-sm',
            resource_path('views/scenes/projects/index.blade.php') => 'ui-avatar-md',
            resource_path('views/scenes/projects/show.blade.php') => 'ui-avatar-md',
        ] as $viewPath => $avatarSize) {
            $source = File::get($viewPath);

            $this->assertStringContainsString($avatarSize, $source, $viewPath);
            $this->assertDoesNotMatchRegularExpression('/<x-avatar\b[^>]*rounded-(?:md|full|lg|xl)/', $source, $viewPath);
        }

        $reports = File::get(resource_path('views/scenes/gallery/reports.blade.php'));
        $recipeForm = File::get(resource_path('views/components/scenes/recipes/_form.blade.php'));

        $this->assertStringContainsString('class="ui-check"', $reports);
        $this->assertStringNotContainsString('ui-check rounded-md', $reports);
        $this->assertStringContainsString('x-signal.ui.checkbox', $recipeForm);
        $this->assertStringNotContainsString('rounded-md', $recipeForm);
    }

    public function test_shared_mobile_form_feedback_exposes_focus_and_loading_hooks(): void
    {
        $errors = File::get(resource_path('views/components/forms/errors.blade.php'));
        $providerErrors = File::get(resource_path('views/components/scenes/providers/validation-errors.blade.php'));
        $appStyles = File::get(resource_path('css/app.css'));

        $this->assertStringContainsString('data-form-error', $errors);
        $this->assertStringContainsString('data-form-error-summary', $providerErrors);
        $this->assertStringContainsString('[data-modal-content][aria-busy="true"]', $appStyles);
        $this->assertStringContainsString('scroll-margin-block: 6rem', $appStyles);
    }

    public function test_long_workspace_surfaces_use_compact_local_navigation_and_border_only_notice_states(): void
    {
        foreach ([
            'commands/index.blade.php' => ['#command-filters', '#command-insights', '#command-history'],
            'notifications/index.blade.php' => ['#notifications-insights', '#notification-list', '#notification-filters'],
            'feedback/index.blade.php' => ['#feedback-list'],
            'scenes/users/index.blade.php' => ['#account-two-factor', '#account-data'],
            'scenes/organizations/index.blade.php' => ['#organization-security-policy', '#organization-delete'],
            'system-health/index.blade.php' => ['#system-health-insights', '#system-health-checks', '#system-health-help'],
        ] as $view => $anchors) {
            $source = File::get(resource_path('views/'.$view));

            $this->assertStringContainsString('x-signal.ui.local-nav', $source, $view);
            foreach ($anchors as $anchor) {
                $this->assertStringContainsString('href="'.$anchor.'"', $source, $view);
            }
        }

        $account = File::get(resource_path('views/scenes/users/index.blade.php'));
        $this->assertStringContainsString('data-modal-trigger="{{ $profileDialogId }}"', $account);

        $feedback = File::get(resource_path('views/feedback/index.blade.php'));
        $this->assertStringContainsString('data-modal-trigger="feedback-compose"', $feedback);

        $notifications = File::get(resource_path('views/notifications/index.blade.php'));
        $this->assertStringContainsString('ui-notification', $notifications);
        $this->assertStringContainsString('data-notification-status', $notifications);
        $this->assertStringNotContainsString('border-l-red-400', $notifications);
        $this->assertStringNotContainsString('border-l-green-500', $notifications);
        $this->assertStringNotContainsString('border-l-blue-500', $notifications);
        $this->assertStringNotContainsString('bg-red-50', $notifications);
        $this->assertStringNotContainsString('bg-green-50', $notifications);
        $this->assertStringNotContainsString('bg-blue-50', $notifications);

        $errorPage = File::get(resource_path('views/errors/500.blade.php'));
        $this->assertStringContainsString('x-layouts.core', $errorPage);
        $this->assertStringContainsString('ui-panel', $errorPage);
        $this->assertStringContainsString('ui-alert-danger', $errorPage);
        $this->assertStringNotContainsString('#0f172a', $errorPage);
        $this->assertStringNotContainsString('#2563eb', $errorPage);
    }

    public function test_workspace_deletion_uses_a_border_led_signal_danger_panel(): void
    {
        $source = File::get(resource_path('views/scenes/organizations/index.blade.php'));

        $this->assertStringContainsString('ui-panel--danger', $source);
        $this->assertStringContainsString('focus-visible:ring-2 focus-visible:ring-focus', $source);
        $this->assertStringContainsString('var(--ui-danger)', $source);

        foreach (['bg-red-50', 'border-red-200', 'text-red-900', 'text-red-800', 'text-red-700', 'ring-red-500'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $source);
        }
    }

    public function test_account_security_danger_surfaces_use_signal_tokens(): void
    {
        $source = File::get(resource_path('views/scenes/users/index.blade.php'));

        $this->assertSame(2, substr_count($source, 'ui-panel--danger'));
        $this->assertStringContainsString('text-danger', $source);

        foreach (['border-red-300', 'text-red-700', 'text-red-900', 'text-red-800', 'bg-red-100'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $source);
        }
    }

    public function test_deployment_timeline_status_markers_use_signal_tokens(): void
    {
        $source = File::get(resource_path('views/components/deployment-timeline.blade.php'));

        foreach (['ui-timeline', 'ui-timeline-item', 'x-ui.badge', "'completed' => 'success'", "'active' => 'accent'", "'failed' => 'danger'", "'canceled' => 'warning'", "'pending' => 'neutral'"] as $primitive) {
            $this->assertStringContainsString($primitive, $source);
        }

        foreach (['rounded-full', 'bg-green-100', 'text-green-700', 'bg-blue-100', 'text-blue-700', 'bg-red-100', 'text-red-700', 'bg-amber-100', 'text-amber-800'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $source);
        }
    }

    public function test_gallery_report_reasons_use_shared_signal_badges(): void
    {
        $source = File::get(resource_path('views/scenes/gallery/reports.blade.php'));

        $this->assertStringContainsString(':tone="$reportReasonTone"', $source);

        foreach (['bg-red-100', 'text-red-700', 'bg-orange-100', 'text-orange-700', 'bg-yellow-100', 'text-yellow-800', 'bg-purple-100', 'text-purple-700', 'bg-blue-100', 'text-blue-700'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $source);
        }
    }

    public function test_shared_danger_indicators_use_signal_tokens(): void
    {
        $deleteDialog = File::get(resource_path('views/components/signal/overlays/delete-confirmation.blade.php'));
        $applicationLayout = File::get(resource_path('views/components/signal/layouts/topbar.blade.php'));

        $this->assertStringContainsString('var(--ui-danger)', $deleteDialog);
        $this->assertStringContainsString('ui-status-dot', $applicationLayout);
        $this->assertStringContainsString('--ui-status-dot: var(--ui-danger)', $applicationLayout);

        foreach (['bg-red-100', 'text-red-600'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $deleteDialog);
        }

        $this->assertStringNotContainsString('bg-red-500', $applicationLayout);
    }

    public function test_deployment_preflight_checks_use_signal_status_tokens(): void
    {
        $source = File::get(resource_path('views/livewire/build-deployment-status.blade.php'));

        foreach (['text-success', 'text-warning', 'text-danger'] as $token) {
            $this->assertStringContainsString($token, $source);
        }

        foreach (['text-green-600', 'text-amber-600', 'text-red-600'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $source);
        }
    }

    public function test_project_readiness_and_rotation_states_use_signal_tokens(): void
    {
        $source = File::get(resource_path('views/scenes/projects/show.blade.php'));

        foreach (['text-success', 'text-danger'] as $token) {
            $this->assertStringContainsString($token, $source);
        }

        foreach (['text-green-600', 'text-red-600'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $source);
        }
    }

    public function test_provider_monitoring_disclosure_uses_signal_focus(): void
    {
        $source = File::get(resource_path('views/components/scenes/providers/_form.blade.php'));

        $this->assertStringContainsString('focus-visible:ring-2 focus-visible:ring-focus', $source);
        $this->assertStringNotContainsString('focus-visible:ring-blue-500', $source);
    }

    public function test_contextual_evidence_states_use_signal_tokens(): void
    {
        $reportHistory = File::get(resource_path('views/scenes/gallery/my-reports.blade.php'));
        $serverLogs = File::get(resource_path('views/livewire/scenes/servers/show.blade.php'));

        $this->assertStringContainsString('ui-card--unread', $reportHistory);
        $this->assertStringContainsString('text-danger', $serverLogs);

        foreach (['border-blue-400', 'ring-blue-200'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $reportHistory);
        }

        $this->assertStringNotContainsString('text-red-300', $serverLogs);
    }

    public function test_shared_auth_and_stat_accents_use_signal_primary(): void
    {
        $authLayout = File::get(resource_path('views/components/layouts/auth.blade.php'));
        $statsPanel = File::get(resource_path('views/components/panel/stats.blade.php'));

        $this->assertStringContainsString('text-[var(--ui-primary)]', $authLayout);
        $this->assertStringContainsString('text-[var(--ui-primary)]', $statsPanel);
        $this->assertStringNotContainsString('text-blue-400', $authLayout);
        $this->assertStringNotContainsString('text-blue-400', $statsPanel);
    }

    public function test_billing_and_gallery_status_copy_use_signal_tokens(): void
    {
        $billing = File::get(resource_path('views/scenes/billing/index.blade.php'));
        $gallery = File::get(resource_path('views/scenes/gallery/show.blade.php'));

        $this->assertStringContainsString('text-warning', $billing);
        $this->assertStringContainsString('text-success', $gallery);
        $this->assertStringNotContainsString('text-amber-700', $billing);
        $this->assertStringNotContainsString('text-green-700', $gallery);
    }

    public function test_remaining_legacy_signal_aliases_are_removed_from_shared_surfaces(): void
    {
        foreach ([
            resource_path('views/load-balancers/index.blade.php'),
            resource_path('views/components/forms/section.blade.php'),
            resource_path('views/components/ui/insights.blade.php'),
            app_path('Core/Views/marketing/home.blade.php'),
            resource_path('views/livewire/build-deployment-status.blade.php'),
            resource_path('views/backups/_mobile-backup-card.blade.php'),
            resource_path('views/notifications/index.blade.php'),
            resource_path('views/vendor/pagination/simple-tailwind.blade.php'),
            resource_path('views/components/scenes/backups/destination-edit-dialog.blade.php'),
            resource_path('views/components/scenes/notifications/save-filter-dialog.blade.php'),
            resource_path('views/scenes/projects/configuration-dialog.blade.php'),
            resource_path('views/scenes/repositories/github-app.blade.php'),
            resource_path('views/databases/index.blade.php'),
            resource_path('views/feedback/index.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            foreach (['text-primary', 'text-secondary', 'focus-visible:ring-primary', 'focus-visible:ring-blue-500'] as $legacyClass) {
                $this->assertStringNotContainsString($legacyClass, $source, $viewPath);
            }
        }
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
            ->assertSee('data-troubleshooting-cards', false)
            ->assertSee('data-troubleshooting-card', false)
            ->assertDontSee('<table', false)
            ->assertSee(route('platform-status.show'))
            ->assertSee(route('api-docs'));
    }

    public function test_public_documentation_and_api_reference_offer_compact_local_navigation(): void
    {
        $this->get(route('docs'))
            ->assertSuccessful()
            ->assertSee('id="guide-contents"', false)
            ->assertSee('On this page')
            ->assertSee('6 guide sections')
            ->assertSee('href="#troubleshooting"', false);

        $this->get(route('api-docs'))
            ->assertSuccessful()
            ->assertSee('id="api-contents"', false)
            ->assertSee('9 endpoints')
            ->assertSee('href="#api-operation-deploy"', false)
            ->assertSee('id="api-operation-deploy"', false)
            ->assertSee('id="api-path-deploy"', false);

        $documentation = File::get(resource_path('views/docs.blade.php'));
        $apiDocumentation = File::get(resource_path('views/api-docs.blade.php'));

        $this->assertStringContainsString('ui-eyebrow', $documentation);
        $this->assertStringContainsString('ui-card ui-card--muted', $documentation);
        $this->assertStringContainsString('ui-eyebrow', $apiDocumentation);
        $this->assertStringContainsString('library-code', $apiDocumentation);
        $this->assertStringContainsString('ui-card ui-card--interactive', $apiDocumentation);

        foreach ([$documentation, $apiDocumentation] as $source) {
            $this->assertStringNotContainsString('text-secondary', $source);
            $this->assertStringNotContainsString('bg-secondary', $source);
            $this->assertStringNotContainsString('border-primary', $source);
            $this->assertStringNotContainsString('text-primary', $source);
            $this->assertStringNotContainsString('text-ternary', $source);
            $this->assertStringNotContainsString('button--', $source);
            $this->assertStringNotContainsString('input secondary', $source);
        }

        $this->assertStringContainsString('x-signal.ui.badge', $apiDocumentation);
    }

    public function test_auth_pages_have_page_specific_browser_titles_and_valid_description_structure(): void
    {
        $this->get(route('login'))
            ->assertSuccessful()
            ->assertSee('<title>Sign in to your account · '.config('app.name').'</title>', false)
            ->assertSee('Sign in to manage your websites and servers.')
            ->assertSee('<div class="mt-2 leading-6 text-muted">', false)
            ->assertSee('data-auth-brand', false)
            ->assertSee('class="ui-label"', false)
            ->assertSee('class="ui-input"', false);

        $this->get(route('register'))
            ->assertSuccessful()
            ->assertSee('<title>Sign up for an account · '.config('app.name').'</title>', false)
            ->assertSee('Sign up for an account to easily manage your work life.');
    }

    public function test_public_navigation_and_calls_to_action_are_functional_and_truthful(): void
    {
        $guestHtml = $this->get('/')
            ->assertSuccessful()
            ->assertSee('<title>One workspace for your software operations · '.config('app.name').'</title>', false)
            ->assertSee('name="robots" content="index, follow"', false)
            ->assertSee('rel="canonical" href="'.url('/').'"', false)
            ->assertSee('property="og:type" content="website"', false)
            ->assertSee('property="og:site_name" content="'.config('app.name').'"', false)
            ->assertSee('name="twitter:card" content="summary"', false)
            ->assertSee('name="theme-color" content="#f4f7fb" data-theme-color', false)
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content" tabindex="-1"', false)
            ->assertSee('aria-label="Product navigation"', false)
            ->assertSee('aria-label="Mobile product navigation"', false)
            ->assertSee('One workspace for the work behind your software.')
            ->assertSee('The Buildpusher apps')
            ->assertSee('Projects carry across apps')
            ->assertSee('Independent app plans')
            ->assertSee('Each app keeps its own operational database')
            ->assertSee(route('platform.login'))
            ->assertSee(route('platform.register'))
            ->assertSee(route('privacy'))
            ->assertSee(route('terms'))
            ->getContent();

        foreach (config('marketing.products') as $slug => $product) {
            $this->assertStringContainsString('href="'.route('core.marketing.product', $slug).'"', $guestHtml);
            $this->get(route('core.marketing.product', $slug))
                ->assertSuccessful()
                ->assertSee($product['name'])
                ->assertSee('Separate app plan')
                ->assertSee('What you can do')
                ->assertSee('Open '.$product['name']);
        }

        $homepage = File::get(app_path('Core/Views/marketing/home.blade.php'));
        $productPage = File::get(app_path('Core/Views/marketing/product.blade.php'));
        $coreLayout = File::get(resource_path('views/components/signal/layouts/core.blade.php'));
        $styles = File::get(resource_path('css/app.css'));
        $this->assertStringContainsString('<x-signal.layouts.core', $homepage);
        $this->assertStringContainsString('<x-signal.blocks.product-card', $homepage);
        $this->assertStringContainsString('<x-signal.ui.button', $homepage);
        $this->assertStringContainsString('<x-signal.ui.card', $productPage);
        $this->assertStringContainsString('<x-signal.blocks.product-feature', $productPage);
        $this->assertStringContainsString(':livewire="false"', $homepage);
        $this->assertStringContainsString('@if ($livewire)', $coreLayout);
        $this->assertStringContainsString('[x-cloak]', $styles);
        $this->assertStringContainsString('#main-content .border:is(', $styles);
        $this->assertStringContainsString('background-color: var(--ui-surface)', $styles);
        $this->assertStringContainsString('ui-eyebrow', $homepage);
        $this->assertStringContainsString('bg-emphasis', $homepage);
        $this->assertStringNotContainsString('text-primary', $homepage);
        $this->assertStringNotContainsString('text-secondary', $homepage);
        $this->assertStringNotContainsString('text-ternary', $homepage);
        $this->assertDoesNotMatchRegularExpression('/(?:^|[\s\'"])bg-primary(?:[\s\'"]|$)/', $homepage);
        $this->assertStringNotContainsString('bg-secondary', $homepage);
        $this->assertStringNotContainsString('border-primary', $homepage);
    }

    public function test_public_legal_pages_use_signal_typography_and_links(): void
    {
        foreach ([
            'legal/terms.blade.php' => [
                'Terms of Service',
                'Acceptable use',
                'route(\'privacy\')',
            ],
            'legal/privacy.blade.php' => [
                'Privacy Policy',
                'Information we process',
                'route(\'terms\')',
            ],
        ] as $view => $content) {
            $source = File::get(resource_path('views/'.$view));

            $this->assertStringContainsString('ui-link', $source, $view);
            $this->assertStringContainsString('text-ink', $source, $view);
            $this->assertStringContainsString('text-muted', $source, $view);
            $this->assertStringContainsString('border-line', $source, $view);
            $this->assertStringNotContainsString('text-primary', $source, $view);
            $this->assertStringNotContainsString('text-secondary', $source, $view);
            $this->assertStringNotContainsString('text-ternary', $source, $view);
            $this->assertStringNotContainsString('border-primary', $source, $view);

            foreach ($content as $fragment) {
                $this->assertStringContainsString($fragment, $source, $view);
            }
        }

        $this->get(route('privacy'))
            ->assertSuccessful()
            ->assertSee('Privacy Policy')
            ->assertSee('ui-link', false)
            ->assertSee('text-ink', false)
            ->assertSee('text-muted', false);
        $this->get(route('terms'))
            ->assertSuccessful()
            ->assertSee('Terms of Service')
            ->assertSee('ui-link', false)
            ->assertSee('text-ink', false)
            ->assertSee('text-muted', false);
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
        $viewFiles = collect([
            ...File::allFiles(resource_path('views')),
            ...File::allFiles(app_path('Modules/Deployer/Views')),
        ]);
        $views = $viewFiles
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

    public function test_view_sources_use_signal_weight_and_responsive_form_radius_utilities(): void
    {
        $skipLinkViews = 0;
        $viewFiles = collect([
            ...File::allFiles(resource_path('views')),
            ...File::allFiles(app_path('Modules/Deployer/Views')),
        ]);

        foreach ($viewFiles as $file) {
            $source = File::get($file->getPathname());

            $this->assertStringNotContainsString('font-black', $source, $file->getRelativePathname());
            $this->assertStringNotContainsString('focus:not-sr-only', $source, $file->getRelativePathname());
            $this->assertStringNotContainsString('shadow-xs', $source, $file->getRelativePathname());
            $this->assertStringNotContainsString('shadow-sm', $source, $file->getRelativePathname());
            $this->assertStringNotContainsString('input secondary', $source, $file->getRelativePathname());

            if (str_contains($source, 'class="ui-skip-link"')) {
                $skipLinkViews++;
            }

            foreach (explode("\n", $source) as $lineNumber => $line) {
                if (! str_contains($line, 'ui-input')) {
                    continue;
                }

                $this->assertStringNotContainsString(
                    'rounded-lg',
                    $line,
                    $file->getRelativePathname().':'.($lineNumber + 1),
                );
            }
        }

        $this->assertGreaterThanOrEqual(11, $skipLinkViews);
    }

    public function test_signal_is_the_canonical_theme_entrypoint(): void
    {
        $stylesheet = File::get(resource_path('css/app.css'));
        $signalComponents = File::get(resource_path('css/signal/components.css'));
        $applicationComponents = File::get(resource_path('css/components/ui.css'));
        $observability = File::get(resource_path('views/observability/index.blade.php'));
        $server = File::get(resource_path('views/livewire/scenes/servers/show.blade.php'));

        $this->assertStringNotContainsString('@import "./theme.css"', $stylesheet);
        $this->assertStringNotContainsString('@import "./components/button.css"', $stylesheet);
        $this->assertStringNotContainsString('@import "./components/input.css"', $stylesheet);
        $this->assertStringNotContainsString('@import "./signal/compat.css"', $stylesheet);
        $this->assertFalse(File::exists(resource_path('css/theme.css')));
        $this->assertFalse(File::exists(resource_path('css/components/button.css')));
        $this->assertFalse(File::exists(resource_path('css/components/input.css')));
        $this->assertFalse(File::exists(resource_path('css/signal/compat.css')));
        $this->assertStringNotContainsString('bg-surface-ternary', $observability);
        $this->assertStringNotContainsString('bg-surface-ternary', $server);
        $this->assertStringContainsString('@import "./signal/theme.css"', $stylesheet);
        $this->assertStringContainsString('@import "./signal/components.css"', $stylesheet);
        $this->assertStringContainsString('@import "./components/ui.css"', $stylesheet);
        $this->assertStringContainsString('.ui-btn-primary', $signalComponents);
        $this->assertStringContainsString('.ui-dialog', $signalComponents);
        $this->assertStringContainsString('.ui-table', $signalComponents);
        $this->assertStringNotContainsString('@apply', $signalComponents);
        $this->assertStringContainsString('.ui-card--interactive', $applicationComponents);
        $this->assertStringContainsString('.ui-dashboard-trend', $applicationComponents);
        $this->assertStringContainsString('bg-primary/', $observability);
        $this->assertStringContainsString('bg-primary/', $server);
    }

    public function test_retained_output_surfaces_use_signal_console_tokens(): void
    {
        foreach ([
            resource_path('views/scenes/websites/show.blade.php'),
            resource_path('views/livewire/build-deployment-status.blade.php'),
            resource_path('views/components/scenes/servers/command-output-content.blade.php'),
            resource_path('views/livewire/scenes/servers/command.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('bg-slate-950', $source, $viewPath);
            $this->assertStringNotContainsString('text-slate-100', $source, $viewPath);
            $this->assertStringContainsString('ui-console', $source, $viewPath);
        }
    }

    public function test_livewire_server_command_uses_signal_dialog_composition(): void
    {
        $command = File::get(resource_path('views/livewire/scenes/servers/command.blade.php'));
        $coreLayout = File::get(resource_path('views/components/signal/layouts/core.blade.php'));
        $dialogShell = File::get(resource_path('views/components/signal/overlays/dialog-shell.blade.php'));

        foreach (['<x-signal.overlays.dialog-shell', 'class="ui-command-dialog"', 'data-modal-header', 'data-modal-body', 'data-modal-footer', 'data-livewire-dialog'] as $token) {
            $this->assertStringContainsString($token, $command, $token);
        }
        $this->assertStringContainsString('data-modal-panel', $dialogShell);

        foreach (['fixed inset-0 bg-emphasis/70', 'fixed inset-0 z-10 overflow-y-auto', 'shadow-xl'] as $legacyToken) {
            $this->assertStringNotContainsString($legacyToken, $command, $legacyToken);
        }

        $this->assertStringContainsString('dialog[data-livewire-dialog]', $coreLayout);
        $this->assertStringContainsString('dialog.showModal()', $coreLayout);
        $this->assertStringContainsString("dialog.addEventListener('cancel'", $coreLayout);
    }

    public function test_dashboard_status_sections_do_not_use_feedback_alert_as_a_layout_container(): void
    {
        $dashboard = File::get(resource_path('views/dashboard.blade.php'));
        $attention = File::get(resource_path('views/dashboard/_attention.blade.php'));

        foreach ([$dashboard, $attention] as $source) {
            $this->assertStringNotContainsString('ui-alert ui-panel', $source);
            $this->assertStringNotContainsString('ui-panel ui-alert', $source);
            $this->assertStringContainsString('ui-panel--', $source);
        }
    }

    public function test_authenticated_layout_has_live_accessible_shell_navigation(): void
    {
        $user = User::factory()->create(['name' => 'Ada Lovelace', 'email' => 'ada@example.test']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertSuccessful()
            ->assertSee('class="min-h-full"', false)
            ->assertSee('data-mobile-shell', false)
            ->assertSee('data-topbar-shell', false)
            ->assertSee('aria-label="Products"', false)
            ->assertSee('id="signal-product-navigation"', false)
            ->assertSee('id="signal-profile-navigation"', false)
            ->assertSee('id="signal-mobile-product-navigation"', false)
            ->assertSee('id="signal-mobile-profile-navigation"', false)
            ->assertSee('class="sticky top-0 z-40 border-b border-line bg-surface/95 shadow-soft backdrop-blur', false)
            ->assertSee('data-mobile-main', false)
            ->assertSee('data-mobile-content', false)
            ->assertSee('data-mobile-header', false)
            ->assertSee('data-mobile-quick-navigation', false)
            ->assertDontSee('data-mobile-footer', false)
            ->assertDontSee('id="app-mobile-nav"', false)
            ->assertDontSee('aria-controls="app-mobile-nav"', false)
            ->assertSee('aria-label="Deployer sections"', false)
            ->assertSee('aria-label="Open application navigation"', false)
            ->assertSee('aria-haspopup="dialog"', false)
            ->assertSee('aria-controls="signal-command-palette"', false)
            ->assertSee('id="signal-command-palette"', false)
            ->assertSee('data-signal-command-search-url=', false)
            ->assertSee('aria-labelledby="signal-command-title"', false)
            ->assertSee('aria-label="Quick navigation"', false)
            ->assertSee('x-cloak', false)
            ->assertDontSee('x-trap.inert.noscroll="menu"', false)
            ->assertSee('data-mobile-keyboard-open', false)
            ->assertSee('href="#main-content"', false)
            ->assertSee('id="main-content" tabindex="-1"', false)
            ->assertSee('data-signal-command-palette', false)
            ->assertSee('data-signal-command-input', false)
            ->assertSee('data-signal-command-results', false)
            ->assertSee('overflow-y-auto', false)
            ->assertSee('data-mobile-drawer', false)
            ->assertSee('app-mobile-sidebar-panel', false)
            ->assertDontSee('ui-popover-mobile', false)
            ->assertSee('ada@example.test')
            ->assertSee('action="'.route('logout').'" method="post"', false)
            ->assertSee('New app')
            ->assertSee('data-modal-trigger="application-create-dialog"', false)
            ->assertSee('href="'.route('dashboard', ['dialog' => 'create-application']).'"', false)
            ->assertSee(route('activity.index'), false)
            ->assertDontSee('href="#"', false)
            ->assertDontSee('Copyright 2020');

        foreach ([
            resource_path('views/components/layouts/app.blade.php'),
            resource_path('views/components/layouts/core.blade.php'),
            resource_path('views/components/layouts/sidebar.blade.php'),
            resource_path('views/components/layouts/mobile-navigation.blade.php'),
            resource_path('views/components/layouts/navigation-content.blade.php'),
            resource_path('views/components/layouts/partials/navigation-link.blade.php'),
        ] as $shellPath) {
            $shell = File::get($shellPath);

            $this->assertStringNotContainsString('button primary', $shell, $shellPath);
            $this->assertStringNotContainsString('button secondary', $shell, $shellPath);
            $this->assertStringNotContainsString('button tertiary', $shell, $shellPath);
            $this->assertStringNotContainsString('bg-secondary', $shell, $shellPath);
            $this->assertStringNotContainsString('border-primary', $shell, $shellPath);
            $this->assertStringNotContainsString('text-secondary', $shell, $shellPath);
            $this->assertStringNotContainsString('text-ternary', $shell, $shellPath);
        }

        $appShell = File::get(resource_path('views/components/layouts/app.blade.php'));
        $coreLayout = File::get(resource_path('views/components/signal/layouts/core.blade.php'));
        $this->assertStringContainsString('ui-skip-link', $appShell);
        $this->assertStringContainsString('<x-signal.layouts.topbar', $appShell);
        $this->assertStringNotContainsString('ui-bottom-nav', $appShell);
        $this->assertStringNotContainsString('ui-bottom-nav-link', $appShell);
        $this->assertStringContainsString('max-w-screen-2xl', $appShell);
        $this->assertStringNotContainsString('app-topbar', $appShell);
        $this->assertStringNotContainsString('app-footer', $appShell);
        $signalComponents = File::get(resource_path('css/signal/components.css'));
        $this->assertStringContainsString('ui-topbar-menu', File::get(resource_path('views/components/signal/layouts/topbar.blade.php')));
        $this->assertStringNotContainsString('app-mobile-nav-link', $signalComponents);
        $this->assertStringContainsString('ui-btn ui-btn-primary', $coreLayout);
        $this->assertStringContainsString('ui-btn ui-btn-secondary', $coreLayout);
        $this->assertStringNotContainsString('button--primary', $coreLayout);
        $this->assertStringNotContainsString('button--secondary', $coreLayout);

        $javascript = File::get(resource_path('js/app.js'));
        $publicJavascript = File::get(resource_path('js/alpine.js'));
        $this->assertStringNotContainsString("from 'alpinejs'", $javascript);
        $this->assertStringContainsString("import Alpine from 'alpinejs'", $publicJavascript);
        $this->assertStringContainsString('@if (! $livewire)', $coreLayout);
        $this->assertStringContainsString("@vite('resources/js/alpine.js')", $coreLayout);
        $this->assertStringContainsString("@vite('resources/js/signal-theme-init.js')", $coreLayout);
        $this->assertStringContainsString("@vite('resources/js/signal-theme.js')", $coreLayout);

        $signalThemeInit = File::get(resource_path('js/signal-theme-init.js'));
        $this->assertStringContainsString("const defaults = { preset: 'modern'", $signalThemeInit);
        $this->assertStringContainsString('root.dataset.preset = read(\'preset\')', $signalThemeInit);
    }

    public function test_server_safety_and_operation_surfaces_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/scenes/servers/import.blade.php'),
            resource_path('views/scenes/servers/import-review.blade.php'),
            resource_path('views/livewire/scenes/servers/command.blade.php'),
            resource_path('views/livewire/scenes/servers/show.blade.php'),
            resource_path('views/components/scenes/servers/edit-dialog.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-slate-', $source, $viewPath);
            $this->assertStringNotContainsString('text-slate-', $source, $viewPath);
            $this->assertStringNotContainsString('button--danger', $source, $viewPath);
            $this->assertStringNotContainsString('input secondary', $source, $viewPath);
        }

        $serverShow = File::get(resource_path('views/livewire/scenes/servers/show.blade.php'));
        $serverEditDialog = File::get(resource_path('views/components/scenes/servers/edit-dialog.blade.php'));
        $this->assertStringContainsString('ui-console', $serverShow);
        $this->assertStringContainsString('ui-btn ui-btn-danger', $serverShow);
        $this->assertStringContainsString('ui-link text-xs', $serverShow);
        $this->assertStringContainsString('x-signal.ui.input-field', $serverEditDialog);
        $this->assertStringContainsString('x-signal.ui.card', $serverEditDialog);
        $this->assertStringContainsString('x-signal.ui.button', $serverEditDialog);
        $this->assertStringContainsString('ui-input', File::get(resource_path('views/livewire/scenes/servers/command.blade.php')));
        $serverImport = File::get(resource_path('views/scenes/servers/import.blade.php'));
        $serverImportReview = File::get(resource_path('views/scenes/servers/import-review.blade.php'));
        $this->assertStringContainsString('x-signal.ui.input-field', $serverImport);
        $this->assertStringContainsString('x-signal.ui.select-field', $serverImport);
        $this->assertStringContainsString('x-signal.ui.textarea-field', $serverImport);
        $this->assertStringContainsString(':restore="false"', $serverImport);
        $this->assertStringContainsString('x-signal.ui.checkbox', $serverImportReview);
        $this->assertStringContainsString('x-signal.ui.input-field', $serverImportReview);
        $this->assertStringContainsString('x-signal.ui.card', $serverImportReview);
    }

    public function test_website_import_and_provisioning_surfaces_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/scenes/websites/import.blade.php'),
            resource_path('views/livewire/website-provisioning-log.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-slate-', $source, $viewPath);
            $this->assertStringNotContainsString('bg-slate-', $source, $viewPath);
        }

        $this->assertStringContainsString('ui-input', File::get(resource_path('views/scenes/websites/import.blade.php')));
        $this->assertStringContainsString('ui-console', File::get(resource_path('views/livewire/website-provisioning-log.blade.php')));
    }

    public function test_deployment_and_provisioning_evidence_uses_semantic_signal_statuses(): void
    {
        foreach ([
            resource_path('views/livewire/repository-deployment-timeline.blade.php'),
            resource_path('views/livewire/website-provisioning-log.blade.php'),
            resource_path('views/livewire/setup.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            foreach (['text-primary', 'text-secondary', 'text-ternary', 'bg-primary', 'bg-secondary', 'border-primary', 'bg-green-', 'bg-red-', 'bg-amber-', 'bg-blue-', 'text-green-', 'input secondary'] as $legacyClass) {
                $this->assertStringNotContainsString($legacyClass, $source, $viewPath);
            }
        }

        $setup = File::get(resource_path('views/livewire/setup.blade.php'));
        $this->assertStringContainsString('x-signal.ui.badge', $setup);
        $this->assertStringContainsString('bg-success-soft', $setup);
        $this->assertStringContainsString('bg-surface-muted', $setup);
        $this->assertStringContainsString('Setup Information', $setup);
    }

    public function test_billing_and_pricing_surfaces_use_shared_signal_plan_controls(): void
    {
        foreach ([
            resource_path('views/scenes/billing/index.blade.php'),
            resource_path('views/scenes/pricing.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            foreach (['text-primary', 'text-secondary', 'text-ternary', 'bg-primary', 'bg-secondary', 'border-primary', 'ring-primary', 'button--', 'input secondary'] as $legacyClass) {
                $this->assertStringNotContainsString($legacyClass, $source, $viewPath);
            }
        }

        $billing = File::get(resource_path('views/scenes/billing/index.blade.php'));
        $pricing = File::get(resource_path('views/scenes/pricing.blade.php'));

        $this->assertStringContainsString('x-signal.ui.button', $billing);
        $this->assertStringContainsString('ui-btn-primary', $pricing);
        $this->assertStringContainsString('data-pricing-plan', $pricing);
    }

    public function test_high_availability_inventory_and_dialogs_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/load-balancers/index.blade.php'),
            resource_path('views/components/scenes/load-balancers/create-dialog.blade.php'),
            resource_path('views/components/scenes/load-balancers/node-dialog.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('input secondary', $source, $viewPath);
        }

        $this->assertStringContainsString('ui-input', File::get(resource_path('views/components/scenes/load-balancers/create-dialog.blade.php')));
        $this->assertStringContainsString('ui-card', File::get(resource_path('views/load-balancers/index.blade.php')));
    }

    public function test_workspace_search_render_paths_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/search/index.blade.php'),
            resource_path('views/search/_workspace-results.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('input secondary', $source, $viewPath);
        }

        $this->assertStringContainsString('ui-filter-chip', File::get(resource_path('views/search/index.blade.php')));
        $this->assertStringContainsString('data-palette-item', File::get(resource_path('views/search/_workspace-results.blade.php')));
    }

    public function test_deployment_history_and_comparison_fragments_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/components/scenes/builds/deployment-history-content.blade.php'),
            resource_path('views/components/scenes/builds/comparison-content.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('divide-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('text-red-600', $source, $viewPath);
            $this->assertStringNotContainsString('text-green-600', $source, $viewPath);
        }

        $history = File::get(resource_path('views/components/scenes/builds/deployment-history-content.blade.php'));
        $comparison = File::get(resource_path('views/components/scenes/builds/comparison-content.blade.php'));

        $this->assertStringContainsString('aria-label="{{ __(\'Deployment timeline\') }}"', $history);
        $this->assertStringContainsString('ui-card', $history);
        $this->assertStringContainsString('divide-y divide-line', $comparison);
        $this->assertStringContainsString('data-build-comparison-field', $comparison);
    }

    public function test_repository_status_accents_use_signal_semantic_tokens(): void
    {
        $source = File::get(resource_path('views/scenes/repositories/show.blade.php'));

        foreach (['text-green-600', 'text-green-700', 'text-amber-600', 'text-amber-700', 'text-red-600'] as $legacyClass) {
            $this->assertStringNotContainsString($legacyClass, $source);
        }

        $this->assertStringContainsString('var(--ui-success)', $source);
        $this->assertStringContainsString('var(--ui-warning)', $source);
        $this->assertStringContainsString('var(--ui-danger)', $source);
    }

    public function test_shared_pagination_uses_signal_controls(): void
    {
        foreach ([
            resource_path('views/vendor/pagination/simple-tailwind.blade.php'),
            resource_path('views/vendor/pagination/tailwind.blade.php'),
        ] as $paginationPath) {
            $pagination = File::get($paginationPath);

            $this->assertStringContainsString('ui-btn ui-btn-secondary ui-btn-sm', $pagination, $paginationPath);
            $this->assertStringContainsString('aria-disabled="true"', $pagination, $paginationPath);
            $this->assertStringNotContainsString('text-secondary', $pagination, $paginationPath);
            $this->assertStringNotContainsString('bg-primary', $pagination, $paginationPath);
            $this->assertStringNotContainsString('border-primary', $pagination, $paginationPath);
            $this->assertStringNotContainsString('focus:ring-3', $pagination, $paginationPath);
            $this->assertStringNotContainsString('ring-gray-300', $pagination, $paginationPath);
            $this->assertStringNotContainsString('text-gray-', $pagination, $paginationPath);
            $this->assertStringNotContainsString('bg-white', $pagination, $paginationPath);
        }
    }

    public function test_application_inventory_and_dialogs_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/scenes/projects/index.blade.php'),
            resource_path('views/components/scenes/projects/_create-form.blade.php'),
            resource_path('views/components/scenes/projects/create-dialog.blade.php'),
            resource_path('views/components/scenes/projects/preview-settings-dialog.blade.php'),
            resource_path('views/components/scenes/projects/promotion-dialog.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-primary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('input secondary', $source, $viewPath);
        }

        $createForm = File::get(resource_path('views/components/scenes/projects/_create-form.blade.php'));
        $this->assertStringContainsString('ui-choice', $createForm);
        $this->assertStringContainsString('ui-input', $createForm);
        $this->assertStringContainsString('ui-check', $createForm);
        $this->assertStringContainsString('ui-badge ui-badge-soft', $createForm);
    }

    public function test_provider_forms_use_text_choices_and_signal_selection_states(): void
    {
        $providerForm = File::get(resource_path('views/components/scenes/providers/_form.blade.php'));

        $this->assertStringContainsString('role="radiogroup"', $providerForm);
        $this->assertStringContainsString('<x-signal.ui.choice', $providerForm);
        $this->assertStringContainsString('type="radio"', $providerForm);
        $this->assertStringContainsString('focus-visible:ring-2 focus-visible:ring-focus', $providerForm);
        $this->assertStringContainsString('.ui-choice:has(input:checked)', File::get(resource_path('css/signal/components.css')));
        $this->assertStringContainsString('<x-signal.ui.input-field', $providerForm);
        $this->assertStringContainsString('<x-signal.ui.textarea-field', $providerForm);
        $this->assertStringContainsString('<x-signal.ui.select-field', $providerForm);
        $this->assertStringContainsString('ui-check', File::get(resource_path('views/components/signal/ui/choice.blade.php')));
        $this->assertStringNotContainsString('border-ternary', $providerForm);
        $this->assertStringNotContainsString('bg-tertiary', $providerForm);
        $this->assertStringNotContainsString('ring-ternary', $providerForm);
        $this->assertStringNotContainsString('text-primary', $providerForm);
        $this->assertStringNotContainsString('text-secondary', $providerForm);
        $this->assertStringNotContainsString('input secondary', $providerForm);
    }

    public function test_recipe_inventory_detail_and_dialogs_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/scenes/recipes/index.blade.php'),
            resource_path('views/scenes/recipes/show.blade.php'),
            resource_path('views/scenes/recipes/edit.blade.php'),
            resource_path('views/components/scenes/recipes/_form.blade.php'),
            resource_path('views/components/scenes/recipes/create-dialog.blade.php'),
            resource_path('views/components/scenes/recipes/edit-dialog.blade.php'),
            resource_path('views/components/scenes/recipes/edit-dialog-content.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-primary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('input secondary', $source, $viewPath);
        }

        $form = File::get(resource_path('views/components/scenes/recipes/_form.blade.php'));
        $this->assertStringContainsString('x-signal.ui.input-field', $form);
        $this->assertStringContainsString('x-signal.ui.textarea-field', $form);
        $this->assertStringContainsString('x-signal.ui.checkbox', $form);
        $this->assertStringContainsString('x-signal.ui.select-field', $form);
        $this->assertStringContainsString('x-signal.ui.card', $form);
    }

    public function test_gallery_inventory_comparison_and_feedback_surfaces_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/scenes/gallery/index.blade.php'),
            resource_path('views/scenes/gallery/show.blade.php'),
            resource_path('views/scenes/gallery/compare.blade.php'),
            resource_path('views/scenes/gallery/my-reports.blade.php'),
            resource_path('views/scenes/gallery/reports.blade.php'),
            resource_path('views/scenes/gallery/partials/script-modal-content.blade.php'),
            resource_path('views/components/scenes/gallery/inspect-dialog.blade.php'),
            resource_path('views/components/scenes/gallery/publish-dialog.blade.php'),
            resource_path('views/components/scenes/gallery/report-dialog.blade.php'),
            resource_path('views/components/scenes/gallery/report-resolution-dialog.blade.php'),
            resource_path('views/components/scenes/gallery/report-status-content.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-primary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('divide-primary', $source, $viewPath);
            $this->assertStringNotContainsString('input secondary', $source, $viewPath);
        }

        $reportDialog = File::get(resource_path('views/components/scenes/gallery/report-dialog.blade.php'));
        $this->assertStringContainsString('ui-label', $reportDialog);
        $this->assertStringContainsString('ui-input', $reportDialog);

        $scriptPreview = File::get(resource_path('views/scenes/gallery/partials/script-modal-content.blade.php'));
        $this->assertStringContainsString('ui-console', $scriptPreview);
    }

    public function test_shared_controls_emit_signal_only_rendering_hooks(): void
    {
        foreach ([
            resource_path('views/components/signal/ui/button.blade.php'),
            resource_path('views/components/signal/overlays/modal.blade.php'),
            resource_path('views/components/signal/ui/filter-panel.blade.php'),
            resource_path('views/components/signal/overlays/delete-confirmation.blade.php'),
            resource_path('views/components/signal/ui/insights.blade.php'),
            resource_path('views/components/signal/ui/empty-state.blade.php'),
            resource_path('views/components/lists/empty.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('button--', $source, $viewPath);
            $this->assertStringNotContainsString('text-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertDoesNotMatchRegularExpression('/(?:^|[\s\'\"])bg-primary(?:[\s\'\"]|$)/', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
        }

        $button = File::get(resource_path('views/components/signal/ui/button.blade.php'));
        $this->assertStringContainsString("'ui-btn', 'ui-btn-'.\$signalVariant", $button);

        $emptyState = File::get(resource_path('views/components/signal/ui/empty-state.blade.php'));
        $this->assertStringContainsString('<x-signal.ui.card', $emptyState);
        $this->assertStringContainsString('bg-primary-soft', $emptyState);
        $this->assertStringContainsString('text-[var(--ui-primary)]', $emptyState);
        $this->assertStringContainsString('rounded-card', $emptyState);
        $this->assertStringNotContainsString('rounded-2xl', $emptyState);

        $uiStyles = File::get(resource_path('css/components/ui.css'));
        $this->assertStringContainsString('.ui-page-header__actions > .ui-btn', $uiStyles);
        $this->assertStringContainsString('[data-dashboard-hero] .ui-page-header__actions > nav > .ui-btn', $uiStyles);
    }

    public function test_account_and_workspace_preference_surfaces_use_signal_form_primitives(): void
    {
        foreach ([
            resource_path('views/components/scenes/organizations/invite-dialog.blade.php'),
            resource_path('views/components/scenes/organizations/member-role-dialog.blade.php'),
            resource_path('views/components/scenes/organizations/notification-preferences-dialog.blade.php'),
            resource_path('views/components/scenes/dashboard/preferences-dialog.blade.php'),
            resource_path('views/components/auth/social-providers.blade.php'),
            resource_path('views/scenes/users/index.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-primary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('input secondary', $source, $viewPath);
        }

        $invite = File::get(resource_path('views/components/scenes/organizations/invite-dialog.blade.php'));
        $preferences = File::get(resource_path('views/components/scenes/organizations/notification-preferences-dialog.blade.php'));
        $this->assertStringContainsString('ui-label', $invite);
        $this->assertStringContainsString('ui-input', $invite);
        $this->assertStringContainsString('class="ui-check"', $preferences);
    }

    public function test_public_status_and_access_request_pages_use_signal_primitives(): void
    {
        foreach ([
            resource_path('views/status/platform.blade.php'),
            resource_path('views/status/show.blade.php'),
            resource_path('views/access-request.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            $this->assertStringNotContainsString('text-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('bg-secondary', $source, $viewPath);
            $this->assertStringNotContainsString('border-primary', $source, $viewPath);
            $this->assertStringNotContainsString('text-ternary', $source, $viewPath);
            $this->assertStringNotContainsString('input secondary', $source, $viewPath);
        }

        $this->assertStringContainsString('ui-input', File::get(resource_path('views/status/show.blade.php')));
        $this->assertStringContainsString('ui-eyebrow', File::get(resource_path('views/access-request.blade.php')));
    }

    public function test_platform_admin_surfaces_use_signal_controls_without_legacy_palette_classes(): void
    {
        foreach ([
            resource_path('views/admin/access-requests.blade.php'),
            resource_path('views/admin/analytics.blade.php'),
            resource_path('views/admin/github-app-setup.blade.php'),
            resource_path('views/components/scenes/admin/access-request-review-dialog.blade.php'),
        ] as $viewPath) {
            $source = File::get($viewPath);

            foreach (['text-primary', 'text-secondary', 'text-ternary', 'bg-primary', 'bg-secondary', 'bg-ternary', 'border-primary', 'button--', 'input secondary'] as $legacyClass) {
                $this->assertStringNotContainsString($legacyClass, $source, $viewPath);
            }
        }

        $analytics = File::get(resource_path('views/admin/analytics.blade.php'));
        $this->assertStringContainsString('ui-chart-bar', $analytics);
        $this->assertStringContainsString('ui-progress', $analytics);

        $setup = File::get(resource_path('views/admin/github-app-setup.blade.php'));
        $this->assertStringContainsString('ui-input', $setup);
        $this->assertStringContainsString('ui-alert--warning', $setup);
    }

    public function test_navigation_merges_related_destinations_without_removing_their_routes(): void
    {
        $user = User::factory()->create();
        $navigation = app(WorkspaceNavigation::class)->for($user);
        $items = collect($navigation['groups'])
            ->flatMap(fn (array $group): array => $group['items'])
            ->merge($navigation['profile']);

        $this->assertSame('projects.index', $items->firstWhere('label', 'Applications')['route']);
        $this->assertSame(
            ['projects.*', 'environments.*', 'builds.*', 'repositories.*'],
            $items->firstWhere('label', 'Applications')['active'],
        );
        $this->assertSame('recipes.index', $items->firstWhere('label', 'Template library')['route']);
        $this->assertSame(['recipes.*', 'gallery.*'], $items->firstWhere('label', 'Template library')['active']);
        $this->assertSame('billing.index', $items->firstWhere('label', 'Billing and usage')['route']);
        $this->assertSame(['billing.*', 'costs.*'], $items->firstWhere('label', 'Billing and usage')['active']);
        $this->assertSame('account.index', $items->firstWhere('label', 'Account and security')['route']);
        $this->assertFalse($items->contains(fn (array $item): bool => in_array($item['label'], [
            'Deployments',
            'Repositories',
            'Recipes',
            'Gallery',
            'Billing',
            'Costs and usage',
            'Settings',
        ], true)));
    }

    public function test_mobile_navigation_retains_the_original_direct_destinations(): void
    {
        $user = User::factory()->create();
        $navigation = app(WorkspaceNavigation::class)->for($user);
        $items = collect($navigation['mobile']['groups'])->flatten(1);

        $this->assertSame('builds.index', $items->firstWhere('label', 'Deployments')['route']);
        $this->assertSame('repositories.index', $items->firstWhere('label', 'Repositories')['route']);
        $this->assertSame('recipes.index', $items->firstWhere('label', 'Recipes')['route']);
        $this->assertSame('gallery.index', $items->firstWhere('label', 'Gallery')['route']);
        $this->assertSame('costs.index', $items->firstWhere('label', 'Costs')['route']);
        $this->assertSame('billing.index', $items->firstWhere('label', 'Billing')['route']);
        $this->assertSame('account.index', $items->firstWhere('label', 'Settings')['route']);
        $this->assertFalse($items->contains(fn (array $item): bool => in_array($item['label'], [
            'Template library',
            'Billing and usage',
            'Account and security',
            'Domains and TLS',
            'Automation and API',
        ], true)));
    }

    public function test_merged_sections_keep_related_surfaces_reachable(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('projects.index'))
            ->assertSuccessful()
            ->assertSee(route('builds.index'), false)
            ->assertSee(route('repositories.index'), false);

        $this->actingAs($user)->get(route('gallery.index'))
            ->assertSuccessful()
            ->assertSee(route('recipes.index'), false);

        $this->actingAs($user)->get(route('billing.index'))
            ->assertSuccessful()
            ->assertSee(route('costs.index'), false);

        $this->actingAs($user)->get(route('costs.index'))
            ->assertSuccessful()
            ->assertSee(route('billing.index'), false);
    }

    public function test_activity_is_discoverable_and_marked_current_in_primary_navigation(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get(route('activity.index'))
            ->assertSuccessful()
            ->assertSee('href="'.route('activity.index').'"', false)
            ->assertSee('aria-current="page"', false);

        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $currentActivityLinks = $xpath->query('//*[@id="signal-product-navigation"]//a[@href="'.route('activity.index').'" and @aria-current="page"]');

        $this->assertCount(1, $currentActivityLinks);
    }

    public function test_every_primary_destination_marks_its_active_link_as_current(): void
    {
        $user = User::factory()->create();
        config(['lessbuild.diagnostics.systemd_timers' => false]);

        foreach ([
            'signal-product-navigation' => [
                'dashboard' => 'dashboard',
                'system-health.index' => 'system-health.index',
                'activity.index' => 'activity.index',
                'commands.index' => 'commands.index',
                'websites.index' => 'websites.index',
                'servers.index' => 'servers.index',
                'builds.index' => 'projects.index',
                'repositories.index' => 'projects.index',
                'notifications.index' => 'notifications.index',
                'providers.index' => 'providers.index',
                'recipes.index' => 'recipes.index',
                'gallery.index' => 'recipes.index',
            ],
            'signal-profile-navigation' => [
                'account.index' => 'account.index',
                'costs.index' => 'billing.index',
                'billing.index' => 'billing.index',
            ],
            'signal-mobile-product-navigation' => [
                'dashboard' => 'dashboard',
                'system-health.index' => 'system-health.index',
                'activity.index' => 'activity.index',
                'commands.index' => 'commands.index',
                'websites.index' => 'websites.index',
                'servers.index' => 'servers.index',
                'builds.index' => 'builds.index',
                'repositories.index' => 'repositories.index',
                'notifications.index' => 'notifications.index',
                'providers.index' => 'providers.index',
                'recipes.index' => 'recipes.index',
                'gallery.index' => 'gallery.index',
            ],
            'signal-mobile-profile-navigation' => [
                'account.index' => 'account.index',
                'costs.index' => 'costs.index',
                'billing.index' => 'billing.index',
            ],
        ] as $navigation => $routes) {
            foreach ($routes as $routeName => $navigationRouteName) {
                $url = route($routeName);
                $navigationUrl = route($navigationRouteName);
                $html = $this->actingAs($user)->get($url)
                    ->assertSuccessful()
                    ->getContent();

                $dom = new \DOMDocument;
                @$dom->loadHTML($html);
                $xpath = new \DOMXPath($dom);
                $current = $xpath->query('//*[@id="'.$navigation.'"]//a[@aria-current="page"]');
                $this->assertCount(1, $current, "The {$navigation} menu must mark the {$navigationRouteName} destination as current while viewing {$routeName}.");
                $this->assertSame($navigationUrl, $current->item(0)->getAttribute('href'));
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
                '/<(?:button|x-ui\.button|x-signal\.ui\.button)\b(?=[^>]*\btype="button")(?=[^>]*\bdata-modal-trigger="delete-[^"]+")[^>]*>/s',
                $source,
                basename($view).' must use the shared modal trigger component without submitting an enclosing form.',
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

                $hasExplicitType = preg_match(
                    '/\btype\s*=\s*["\'](?:button|submit|reset)["\']/i',
                    $button,
                ) === 1;
                $hasAllowlistedDynamicType = str_contains(
                    $button,
                    'type="{{ in_array($type, [\'button\', \'submit\', \'reset\'], true) ? $type : \'button\' }}"',
                );

                $this->assertTrue(
                    $hasExplicitType || $hasAllowlistedDynamicType,
                    $file->getRelativePathname().":{$line} must declare an explicit or allow-listed button type.",
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
