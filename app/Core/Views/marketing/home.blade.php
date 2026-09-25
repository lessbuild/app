@php($connectedProducts = collect($products)->map(fn (array $product, string $slug): array => [
    'name' => $product['name'],
    'accent' => $product['accent'],
    'icon' => $product['icon'],
    'href' => route('core.marketing.product', $slug),
])->values()->all())
@php($connections = $connections ?? config('marketing.connections', []))
@php($workspaceCapabilities = $workspaceCapabilities ?? config('marketing.workspace_capabilities', []))
@php($suiteQuestions = [
    ['q' => __('Do I use one account across the apps?'), 'a' => __('Yes. Buildpusher shares sign-in and workspace membership across Deployer, Monitor, and Analytics.')],
    ['q' => __('What carries between products?'), 'a' => __('The workspace project directory and explicitly connected resource context carry across products. When Analytics resources and relevant product environments share a project, Analytics can show Deployer release and Monitor incident annotations. Each app keeps its operational records in its own product database.')],
    ['q' => __('Do the apps share product access or data?'), 'a' => __('No. Each app applies its own workspace access and product entitlements, and keeps its operational records in its own database. Shared sign-in and project connections provide context without merging product data.')],
    ['q' => __('Are subscriptions shared across apps?'), 'a' => __('No. Product access and plan status are managed separately for each app in a workspace. A change in one product does not grant access to another, and available billing options depend on the product.')],
    ['q' => __('Do I need to use every app?'), 'a' => __('No. Start with the product that fits your current work and connect other products to a project when you need their capabilities.')],
])

<x-signal.layouts.core
    :title="__('One workspace for your software operations')"
    :description="__('Bring Buildpusher Deployer, Monitor, and Analytics together with shared accounts and projects, connected workflows, and app-specific access and data.')"
    :canonical="route('core.entry')"
    :indexable="true"
    :livewire="false"
    :interactive-marketing="true"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="product-site" data-product-site data-product-page="overview">
        <section class="product-hero relative isolate overflow-hidden text-white">
            <div class="product-hero-grid pointer-events-none absolute inset-0 opacity-80" aria-hidden="true"></div>
            <div class="relative mx-auto grid max-w-screen-2xl items-center gap-10 px-5 py-14 sm:px-8 sm:py-20 lg:grid-cols-[.88fr_1.12fr] lg:gap-10 lg:py-24 xl:gap-12">
                <div>
                    <x-signal.ui.badge tone="neutral" class="product-hero-secondary">{{ __('One workspace. Three focused apps.') }}</x-signal.ui.badge>
                    <h1 class="mt-6 max-w-3xl text-4xl font-extrabold leading-[1.04] tracking-[-0.05em] sm:text-6xl">{{ __('Ship. Monitor. Understand.') }}</h1>
                    <p class="mt-5 max-w-2xl text-lg leading-8 text-white/75">{{ __('Deployments, monitoring, and analytics. Connected by project.') }}</p>

                    <div class="mt-8 flex flex-col gap-3 min-[440px]:flex-row">
                        <x-signal.ui.button href="#products" variant="secondary" size="lg" class="product-hero-primary justify-center">{{ __('Explore the apps') }}</x-signal.ui.button>
                        <x-signal.ui.button :href="auth('platform')->check() ? route('core.home') : route('platform.register')" variant="secondary" size="lg" class="product-hero-secondary justify-center">{{ auth('platform')->check() ? __('Open your workspace') : __('Create a workspace') }}</x-signal.ui.button>
                    </div>

                    <ul class="mt-7 flex flex-wrap gap-x-6 gap-y-3 text-sm font-semibold text-white/75">
                        @foreach ([__('One shared account'), __('Projects carry across apps'), __('App-specific access')] as $promise)
                            <li class="flex items-center gap-2"><x-signal.ui.icon name="check" class="size-4 text-emerald-300" />{{ $promise }}</li>
                        @endforeach
                    </ul>
                </div>

                <x-signal.blocks.product-suite-preview :project-name="__('Storefront')" />
            </div>
        </section>

        <section id="products" class="scroll-mt-20 bg-page py-16 sm:py-24" aria-labelledby="products-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-2xl">
                        <p class="ui-eyebrow">{{ __('The Buildpusher apps') }}</p>
                        <h2 id="products-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Choose the tool for the work in front of you.') }}</h2>
                    </div>
                    <p class="max-w-xl leading-7 text-muted">{{ __('Each app has focused capabilities, workspace access, and operational records. Your account and project directory stay connected across the platform.') }}</p>
                </div>

                <div class="mt-9 grid gap-4 lg:grid-cols-3">
                    @foreach ($products as $slug => $product)
                        <x-signal.blocks.product-card
                            :name="$product['name']"
                            :accent="$product['accent']"
                            :icon="$product['icon']"
                            :eyebrow="$product['eyebrow']"
                            :summary="$product['card_summary']"
                            :features="$product['card_features']"
                            :href="route('core.marketing.product', $slug)"
                        />
                    @endforeach
                </div>

                <x-signal.blocks.product-explorer :products="$products" />
            </div>
        </section>

        <section class="border-y border-line bg-surface-muted/60 py-16 sm:py-20" aria-labelledby="connected-work-heading">
            <div class="mx-auto grid max-w-screen-2xl gap-10 px-5 sm:px-8 lg:grid-cols-[.8fr_1.2fr] lg:items-center">
                <div>
                    <p class="ui-eyebrow">{{ __('Connected by project') }}</p>
                    <h2 id="connected-work-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Keep the context. Use the right app.') }}</h2>
                    <p class="mt-4 max-w-xl leading-7 text-muted">{{ __('A project is the shared link between your Deployer environments, Monitor applications, and Analytics sites. Connect the resources you use, then follow authorized release, service-health, and traffic context from the project dashboard.') }}</p>
                    <x-signal.ui.button :href="auth('platform')->check() ? route('core.home') : route('platform.register')" variant="secondary" class="mt-6">{{ auth('platform')->check() ? __('Manage your projects') : __('Create a workspace') }}</x-signal.ui.button>
                </div>

                <div class="grid gap-4">
                    <x-signal.blocks.product-connections
                        :apps="$connectedProducts"
                        :project-name="__('Storefront')"
                    />

                    <ol class="grid gap-3 sm:grid-cols-3">
                        @foreach ([
                            ['01', __('Create a project'), __('Give the application or service a shared home in your workspace.')],
                            ['02', __('Connect product resources'), __('Link a Deployer environment, Monitor application, or Analytics site where it belongs.')],
                            ['03', __('Follow the work'), __('Review connected product activity in project context, subject to current access.')],
                        ] as [$step, $title, $description])
                            <li>
                                <x-signal.ui.card class="h-full p-4">
                                    <x-signal.ui.badge tone="accent">{{ $step }}</x-signal.ui.badge>
                                    <h3 class="mt-4 text-sm font-extrabold text-ink">{{ $title }}</h3>
                                    <p class="mt-2 text-xs leading-5 text-muted">{{ $description }}</p>
                                </x-signal.ui.card>
                            </li>
                        @endforeach
                    </ol>
                </div>
            </div>
        </section>

        <section class="bg-page py-16 sm:py-24" aria-labelledby="workspace-capabilities-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="mx-auto max-w-3xl text-center">
                    <p class="ui-eyebrow">{{ __('Buildpusher Core') }}</p>
                    <h2 id="workspace-capabilities-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Manage the shared workspace around your apps.') }}</h2>
                    <p class="mt-4 text-base leading-7 text-muted">{{ __('Core keeps projects, team access, workspace operations, and support together while each product remains responsible for its own records and entitlements.') }}</p>
                </div>

                <div class="mt-8 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($workspaceCapabilities as $capability)
                        <x-signal.blocks.workspace-capability
                            :icon="$capability['icon']"
                            :title="$capability['title']"
                            :description="$capability['description']"
                        />
                    @endforeach
                </div>
            </div>
        </section>

        <section class="bg-page py-16 sm:py-20" aria-labelledby="connected-workflows-heading">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                    <div class="max-w-3xl">
                        <p class="ui-eyebrow">{{ __('Connected product workflows') }}</p>
                        <h2 id="connected-workflows-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Follow a change across the work around it.') }}</h2>
                    </div>
                    <p class="max-w-2xl text-sm leading-6 text-muted">{{ __('Connect the resources to one project to carry release, incident, and traffic context between apps. Each workflow checks current workspace access and product entitlements.') }}</p>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    @foreach ($connections as $connection)
                        <x-signal.blocks.product-connection :connection="$connection" :products="$products" />
                    @endforeach
                </div>

                <p class="mt-4 text-xs leading-5 text-muted">{{ __('Connections are explicit and project-scoped. Product records stay in their owning app database; only the authorized context needed for the connected workflow is shared.') }}</p>
            </div>
        </section>

        <section class="bg-page py-16 sm:py-24" aria-labelledby="platform-model-heading">
            <div class="mx-auto max-w-5xl px-5 sm:px-8">
                <div class="text-center">
                    <p class="ui-eyebrow">{{ __('A clear platform model') }}</p>
                    <h2 id="platform-model-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink sm:text-4xl">{{ __('Shared where it helps. Separate where it matters.') }}</h2>
                </div>
                <dl class="mt-9 grid gap-4 md:grid-cols-3">
                    <x-signal.ui.card class="p-5 sm:p-6">
                        <dt class="text-sm font-extrabold text-ink">{{ __('Account and sign-in') }}</dt>
                        <dd class="mt-2 text-sm leading-6 text-muted">{{ __('Use one Buildpusher account and move between the apps with shared authentication.') }}</dd>
                    </x-signal.ui.card>
                    <x-signal.ui.card class="p-5 sm:p-6">
                        <dt class="text-sm font-extrabold text-ink">{{ __('Team and projects') }}</dt>
                        <dd class="mt-2 text-sm leading-6 text-muted">{{ __('Manage team membership and the project directory in Core, then connect each project to the products you use.') }}</dd>
                    </x-signal.ui.card>
                    <x-signal.ui.card class="p-5 sm:p-6">
                        <dt class="text-sm font-extrabold text-ink">{{ __('Product access and app data') }}</dt>
                        <dd class="mt-2 text-sm leading-6 text-muted">{{ __('Each app keeps its own operational database and checks its own workspace access and product entitlements. Billing options remain product-specific.') }}</dd>
                    </x-signal.ui.card>
                </dl>
            </div>
        </section>

        <section class="border-t border-line bg-surface-muted/60 py-14 sm:py-20" aria-labelledby="suite-questions-heading">
            <div class="mx-auto grid max-w-screen-2xl gap-8 px-5 sm:px-8 lg:grid-cols-[.7fr_1.3fr]">
                <div>
                    <p class="ui-eyebrow">{{ __('How Buildpusher fits together') }}</p>
                    <h2 id="suite-questions-heading" class="mt-3 text-3xl font-extrabold tracking-tight text-ink">{{ __('One shared workspace. Clear product boundaries.') }}</h2>
                    <p class="mt-3 max-w-md text-sm leading-6 text-muted">{{ __('Keep account and project context connected while each product owns its access rules and specialist data.') }}</p>
                </div>
                <x-signal.blocks.faq-list :items="$suiteQuestions" aria-labelledby="suite-questions-heading" />
            </div>
        </section>

        <section class="border-t border-line bg-surface py-14 sm:py-20">
            <div class="mx-auto max-w-screen-2xl px-5 sm:px-8">
                <x-signal.blocks.cta
                    :eyebrow="__('Start with your projects')"
                    :title="__('Bring your team into one Buildpusher workspace.')"
                    :copy="__('Choose the apps that fit your workflow and connect them through shared projects.')"
                    :href="auth('platform')->check() ? route('core.home') : route('platform.register')"
                    :label="auth('platform')->check() ? __('Open your workspace') : __('Create a workspace')"
                />
            </div>
        </section>
    </main>

    <x-signal.site-footer
        :description="__('A connected workspace for deploying, monitoring, and understanding your software.')"
        :explore-links="[
            ['label' => __('Deployer'), 'href' => route('core.marketing.product', 'deployer')],
            ['label' => __('Monitor'), 'href' => route('core.marketing.product', 'monitor')],
            ['label' => __('Analytics'), 'href' => route('core.marketing.product', 'analytics')],
            ['label' => __('Help and API docs'), 'href' => route('core.help')],
            ['label' => __('Platform status'), 'href' => route('core.status')],
            ['label' => __('Privacy'), 'href' => route('core.privacy')],
            ['label' => __('Terms'), 'href' => route('core.terms')],
            ['label' => __('Sign in'), 'href' => route('platform.login')],
        ]"
        :closing-eyebrow="__('Your workspace, your tools')"
        :closing-copy="__('Sign in to manage team access, projects, product connections, and app-specific access.')"
        :action-href="auth('platform')->check() ? route('core.home') : route('platform.login')"
        :action-label="auth('platform')->check() ? __('Open your workspace') : __('Sign in to Buildpusher')"
    />
</x-signal.layouts.core>
