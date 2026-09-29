<x-signal.layouts.app :title="__('Start from a template')">
    <x-signal.ui.page-header :eyebrow="$account->name" :title="__('Start from a template')" :description="__('Set up a project in one go: its services, a website on one of your servers, the repository that deploys to it, uptime monitoring and analytics.')" />

    <ul class="grid gap-4 sm:grid-cols-2" aria-label="{{ __('Templates') }}">
        @foreach ($templates as $key => $template)
            <li>
                <x-signal.ui.card as="a" tone="interactive" :href="route('projects.templates', ['template' => $key])" @class(['block h-full p-5', 'ring-2 ring-primary' => $chosen === $key]) :aria-current="$chosen === $key ? 'true' : null">
                    <p class="text-base font-extrabold text-ink">{{ __($template['name']) }}</p>
                    <p class="mt-1 text-sm text-muted">{{ __($template['description']) }}</p>
                </x-signal.ui.card>
            </li>
        @endforeach
    </ul>

    @if ($chosen)
        @php($template = $templates[$chosen])
        <x-signal.ui.settings-section id="template-form" :title="__('Set up :template', ['template' => __($template['name'])])" :description="__('The website is created on the server and set up over SSH; pushes to the branch deploy once it’s live.')">
            @if ($servers->isEmpty() || $gitProviders->isEmpty())
                <div class="grid gap-2 p-4 text-sm sm:p-6">
                    @if ($servers->isEmpty())<p>{{ __('You need an active app server first.') }} <a href="{{ route('dashboard') }}" class="font-bold text-primary underline">{{ __('Create one from a project’s Infrastructure') }}</a></p>@endif
                    @if ($gitProviders->isEmpty())<p>{{ __('Connect GitHub, GitLab or Bitbucket first.') }} <a href="{{ route('account.providers') }}" class="font-bold text-primary underline">{{ __('Providers') }}</a></p>@endif
                </div>
            @else
                <form method="POST" action="{{ route('projects.templates.store') }}" class="grid items-start gap-5 p-4 sm:grid-cols-2 sm:p-6">
                    @csrf
                    <input type="hidden" name="template" value="{{ $chosen }}">
                    <x-signal.ui.input-field name="name" :label="__('Project name')" maxlength="100" required />
                    <x-signal.ui.input-field name="domain" :label="__('Domain')" placeholder="app.example.com" maxlength="255" required :description="__('Point its DNS at the server; HTTPS is set up automatically.')" />
                    <x-signal.ui.select-field name="server_id" :label="__('Server')">
                        @foreach ($servers as $server)
                            <option value="{{ $server->id }}">{{ $server->label() }}</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.select-field name="provider_id" :label="__('Git provider')">
                        @foreach ($gitProviders as $provider)
                            <option value="{{ $provider->id }}">{{ $provider->name }} ({{ $provider->type->label() }})</option>
                        @endforeach
                    </x-signal.ui.select-field>
                    <x-signal.ui.input-field name="repository_url" :label="__('Repository')" placeholder="github.com/acme/app" maxlength="255" required />
                    <x-signal.ui.input-field name="branch" :label="__('Branch')" value="main" maxlength="255" required />
                    <div class="sm:col-span-2"><x-signal.ui.button type="submit" variant="primary">{{ __('Set up project') }}</x-signal.ui.button></div>
                </form>
            @endif
        </x-signal.ui.settings-section>
    @endif
</x-signal.layouts.app>
