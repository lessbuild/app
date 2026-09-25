@php($sections = [
    [__('Using Buildpusher'), __('Provide accurate account information, protect your credentials and recovery codes, and use Buildpusher only for systems you are authorized to manage. You are responsible for activity performed through your account and connected provider accounts.')],
    [__('Acceptable use'), __('Do not use the service to break the law, compromise systems, distribute malware, evade provider limits, interfere with other users, or deploy content that violates another person’s rights. We may restrict access needed to protect the service or others.')],
    [__('Your infrastructure and content'), __('You retain ownership of your code and data. Infrastructure generally runs in provider accounts you connect. Provider charges, provider availability, domain configuration, licensing, backups, and the legality of deployed content remain your responsibility.')],
    [__('Product plans and billing'), __('Deployer, Monitor, and Analytics have separate plans, limits, and subscription states for each workspace. Workspace billing managers can review each app plan independently. Applicable prices, renewal terms, usage limits, and cancellation options are shown in the relevant product or checkout flow; changing one app plan does not change the other apps’ plans.')],
    [__('Operational safety'), __('Deployment, command, restore, scaling, monitoring, and deletion operations can change live systems or affect production data. Review targets and maintain independent backups. Buildpusher supplies guardrails and logs but cannot guarantee that every third-party service, script, or application will behave as expected.')],
    [__('Availability and account requests'), __('The service may change, experience interruption, or restrict unsafe activity. You may stop using Buildpusher at any time. Self-service account deletion is not currently available while Buildpusher coordinates retention across its products; contact us to request account or personal-information actions. Deleting a Buildpusher account does not automatically remove resources that remain in external provider accounts.')],
    [__('Warranty and liability'), __('To the extent permitted by applicable law, Buildpusher is provided without warranties of uninterrupted or error-free operation. Buildpusher is not responsible for indirect or consequential loss, lost profits, or provider-side outages. Rights that cannot legally be excluded remain unaffected.')],
])

<x-signal.layouts.core
    :title="__('Terms of Service')"
    :description="__('Terms governing use of the Buildpusher product suite.')"
    :canonical="route('core.terms')"
    :indexable="true"
    :livewire="false"
>
    <a href="#main-content" class="ui-skip-link">{{ __('Skip to main content') }}</a>
    <x-signal.blocks.public-navigation />

    <main id="main-content" tabindex="-1" class="mx-auto max-w-4xl px-5 py-12 sm:px-8 sm:py-16">
        <x-signal.ui.page-header
            :eyebrow="__('Buildpusher legal')"
            :title="__('Terms of Service')"
            :description="__('Terms governing use of the Buildpusher product suite.')"
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
                    {{ __('Questions about these terms can be sent to') }}
                    <a class="ui-link" href="mailto:{{ config('legal.contact_email') }}">{{ config('legal.contact_email') }}</a>.
                </p>
            </x-signal.ui.card>
        </div>

        <footer class="mt-8 flex flex-wrap gap-3 border-t border-line pt-6">
            <x-signal.ui.button :href="route('core.privacy')" variant="secondary">{{ __('Privacy Policy') }}</x-signal.ui.button>
            <x-signal.ui.button :href="route('core.status')" variant="quiet">{{ __('Service status') }}</x-signal.ui.button>
        </footer>
    </main>
</x-signal.layouts.core>
