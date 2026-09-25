<x-monitor::ui.document :title="html_entity_decode(trim($__env->yieldContent('title', 'Welcome')), ENT_QUOTES, 'UTF-8').' · '.config('app.name')" csrf>
    <div class="flex min-h-screen flex-col">
        <header class="flex items-center justify-between px-6 py-6 sm:px-10">
            <x-monitor::ui.brand />
            <x-signal.ui.icon-button type="button" label="Toggle theme" data-theme-toggle><x-monitor::icon name="moon" class="h-5 w-5 dark:hidden" /><x-monitor::icon name="sun" class="hidden h-5 w-5 dark:block" /></x-signal.ui.icon-button>
        </header>
        <main id="main-content" tabindex="-1" class="mx-auto grid w-full max-w-5xl flex-1 items-center gap-12 px-6 py-10 lg:grid-cols-2 lg:gap-20">
            <section class="hidden space-y-6 lg:block">
                <x-signal.ui.badge tone="accent">Built for your whole stack</x-signal.ui.badge>
                <h1 class="text-5xl font-bold leading-tight tracking-tight">A clearer view.<br><span class="text-primary">A calmer team.</span></h1>
                <p class="max-w-sm text-base leading-7 text-muted">Bring your application telemetry into one workspace. Follow requests, investigate issues, and work together.</p>
                <div class="flex items-center gap-3 text-sm text-muted"><x-monitor::icon name="shield" class="h-5 w-5 text-primary" />Private workspaces. Access you control.</div>
            </section>
            <x-signal.ui.panel as="section" class="mx-auto w-full max-w-md p-6 sm:p-8">
                <h2 class="text-2xl font-bold tracking-tight">@yield('heading')</h2>
                <p class="mt-2 text-sm leading-6 text-muted">@yield('description')</p>
                <div class="mt-6 space-y-5"><x-monitor::ui.feedback />@yield('content')</div>
            </x-signal.ui.panel>
        </main>
        <footer class="px-6 py-6 text-center text-xs text-subtle">{{ config('app.name') }} · Application observability, together.</footer>
    </div>
</x-monitor::ui.document>
