@php($sections = [
    [__('Information we process'), __('Buildpusher processes account details, workspace membership, sign-in and security records, infrastructure metadata, deployment history, requested logs, monitor check results, incident and alert records, telemetry you send, and aggregated site-usage and attribution data. We also process encrypted credentials needed to perform actions you authorize. Payment information is handled by the configured payment processor when billing is enabled; Buildpusher does not store full card numbers.')],
    [__('How information is used'), __('We use information to authenticate users, review access requests, manage infrastructure, execute deployments, monitor service health, deliver alerts, prevent abuse, provide support, and improve reliability. We do not sell personal information or use private application data for advertising.')],
    [__('Access requests'), __('When registration is invitation-only, Buildpusher retains the contact details, team information, plan interest, and deployment use case you choose to submit. These fields are encrypted at rest and visible only to platform administrators. Declined and accepted requests are removed after the configured retention period; pending requests remain available so we can respond.')],
    [__('Storage and sharing'), __('Sensitive values are encrypted at rest. Product operational records are maintained by the relevant app, while Buildpusher Core manages shared account, workspace, and project-link records. Information is shared only with infrastructure, source-control, email, monitoring, and payment providers needed to deliver features you choose, or when legally required. Connected providers may process requests under their own terms.')],
    [__('Retention and control'), __('Operational records are retained for bounded product and security purposes. You can export shared account data from Account settings and product data from the relevant app. Self-service account deletion is not currently available while Buildpusher coordinates retention across its products. Contact us to request access, correction, export, or deletion of personal information.')],
    [__('Your choices'), __('You can disconnect integrations, revoke browser sessions and tokens, and change notification settings available in the product. You can contact us with requests about your personal information.')],
])

<x-signal.layouts.core
    :title="__('Privacy Policy')"
    :description="__('How Buildpusher processes account, workspace, and product information.')"
    :canonical="route('core.privacy')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-4xl px-5 py-12 sm:px-8 sm:py-16">
        <x-signal.ui.page-header
            :eyebrow="__('Buildpusher legal')"
            :title="__('Privacy Policy')"
            :description="__('How Buildpusher processes account, workspace, and product information.')"
        >
            <x-slot:actions>
                <x-signal.ui.badge tone="neutral">{{ __('Effective :date', ['date' => config('legal.effective_date')]) }}</x-signal.ui.badge>
            </x-slot:actions>
        </x-signal.ui.page-header>

        <div class="mt-8 grid gap-4">
            @foreach ($sections as [$heading, $copy])
                <x-signal.ui.card as="section" class="p-5 sm:p-6">
                    <h2 class="text-lg font-extrabold text-ink">{{ $heading }}</h2>
                    <p class="mt-2 text-sm leading-7 text-muted">{{ $copy }}</p>
                </x-signal.ui.card>
            @endforeach

            <x-signal.ui.card as="section" class="p-5 sm:p-6">
                <h2 class="text-lg font-extrabold text-ink">{{ __('Contact') }}</h2>
                <p class="mt-2 text-sm leading-7 text-muted">
                    {{ __('Privacy questions and personal-information requests can be sent to') }}
                    <a class="ui-link" href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>.
                </p>
            </x-signal.ui.card>
        </div>

        <footer class="mt-8 flex flex-wrap gap-3 border-t border-line pt-6">
            <x-signal.ui.button :href="route('core.terms')" variant="secondary">{{ __('Terms of Service') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.status')" variant="quiet">{{ __('Service status') }}</x-signal.ui.button>
        </footer>
    </main>
</x-signal.layouts.core>
