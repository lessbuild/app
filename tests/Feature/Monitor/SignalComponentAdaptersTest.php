<?php

namespace Tests\Feature\Monitor;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class SignalComponentAdaptersTest extends TestCase
{
    public function test_monitor_controls_keep_their_api_while_using_shared_signal_primitives(): void
    {
        $this->withViewErrors([
            'email' => 'Use a verified address.',
            'optional-field' => 'This standalone field should not display errors by default.',
        ]);

        $html = Blade::render(<<<'BLADE'
            <x-monitor::ui.button variant="outline" size="sm" href="/disabled" disabled aria-label="Unavailable">Unavailable</x-monitor::ui.button>
            <x-monitor::ui.input id="account-email" name="email" label="Email address" description="Used for account notices." type="email" value="previous@example.test" aria-describedby="external-hint" required />
            <x-monitor::ui.input name="password" label="Password" type="password" value="must-never-render" autocomplete="current-password" />
            <x-monitor::ui.field id="optional-field" label="Optional"><input id="optional-field" /></x-monitor::ui.field>
            <x-monitor::ui.select name="status" label="Status" value="paused" :options="['active' => 'Active', 'paused' => 'Paused']" />
            <x-monitor::ui.textarea name="secret_note" label="Secret note" value="must-also-stay-private" sensitive />
            <x-monitor::ui.choice id="member-confirm" name="confirm" label="Confirm membership" type="radio" required description="This applies to the selected workspace." />
            <x-monitor::ui.alert tone="primary" role="status">Monitor is configured.</x-monitor::ui.alert>
            <x-monitor::ui.badge tone="sky">Informational</x-monitor::ui.badge>
            <x-monitor::ui.card padding="p-0" :shadow="false">Shared card</x-monitor::ui.card>
            <x-monitor::ui.filter-panel action="/filters" method="GET"><x-monitor::ui.input name="filter" label="Filter" /></x-monitor::ui.filter-panel>
            <x-monitor::ui.panel as="section" padding="p-0" :shadow="false" aria-label="Shared panel">Panel content</x-monitor::ui.panel>
            <x-monitor::ui.table caption="Members" table-class="min-w-[720px]"><x-slot:head><tr><th scope="col">Name</th></tr></x-slot:head><tr><th scope="row">A member</th></tr></x-monitor::ui.table>
            <x-monitor::ui.stat-card label="Active checks" value="7" caption="Current workspace" icon="activity" change="Healthy" tone="green" />
            <x-monitor::ui.page-header eyebrow="Reliability" eyebrow-icon="activity" title="Queue health" description="Worker status."><x-slot:metadata class="metadata-chip"><span>Updated now</span></x-slot:metadata><x-slot:actions><span>Actions</span></x-slot:actions></x-monitor::ui.page-header>
            <x-monitor::ui.empty-state title="No members" description="Invite the first teammate." icon="users"><x-slot:action><a href="/invite">Invite teammate</a></x-slot:action></x-monitor::ui.empty-state>
            <x-monitor::icon name="check" class="icon-size" />
            BLADE,
        );

        $this->assertStringContainsString('ui-btn-outline', $html);
        $this->assertStringContainsString('ui-btn-sm', $html);
        $this->assertStringContainsString('aria-disabled="true"', $html);
        $this->assertStringNotContainsString('href="/disabled"', $html);
        $this->assertStringContainsString('value="previous@example.test"', $html);
        $this->assertStringContainsString('aria-invalid="true"', $html);
        $this->assertStringContainsString('external-hint account-email-help account-email-error', $html);
        $this->assertStringContainsString('id="account-email-error"', $html);
        $this->assertStringContainsString('Use a verified address.', $html);
        $this->assertStringNotContainsString('This standalone field should not display errors by default.', $html);
        $this->assertStringNotContainsString('must-never-render', $html);
        $this->assertStringNotContainsString('must-also-stay-private', $html);
        $this->assertMatchesRegularExpression('/<option value="paused" selected>Paused<\/option>/', $html);
        $this->assertStringContainsString('type="radio"', $html);
        $this->assertStringContainsString('data-ui-feedback="alert"', $html);
        $this->assertStringContainsString('ui-alert--info', $html);
        $this->assertStringContainsString('role="status"', $html);
        $this->assertStringContainsString('ui-badge-info', $html);
        $this->assertStringContainsString('Shared card', $html);
        $this->assertStringContainsString('<form', $html);
        $this->assertStringContainsString('action="/filters"', $html);
        $this->assertStringContainsString('method="GET"', $html);
        $this->assertStringContainsString('<section', $html);
        $this->assertStringContainsString('ui-table-wrap', $html);
        $this->assertStringContainsString('min-w-[720px]', $html);
        $this->assertStringContainsString('ui-stat', $html);
        $this->assertStringContainsString('metadata-chip', $html);
        $this->assertStringContainsString('Invite teammate', $html);
        $this->assertStringContainsString('class="icon-size"', $html);
        $this->assertStringContainsString('viewBox="0 0 24 24"', $html);
    }
}
