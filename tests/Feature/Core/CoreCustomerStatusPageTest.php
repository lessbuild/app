<?php

namespace Tests\Feature\Core;

use App\Core\Contracts\CustomerStatusPageProvider;
use App\Core\Data\Status\CustomerStatusPage;
use App\Core\Services\CustomerStatusPageProviderRegistry;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class CoreCustomerStatusPageTest extends TestCase
{
    public function test_core_renders_the_published_monitor_status_report_from_its_module_provider(): void
    {
        $page = new CustomerStatusPage(
            product: 'monitor',
            slug: 'acme-status',
            name: 'Acme service status',
            workspaceName: 'Acme',
            description: 'Current service health and incident history.',
            overall: 'operational',
            overallLabel: 'All systems operational',
            components: new Collection([
                [
                    'name' => 'Public API',
                    'type' => 'HTTP monitor',
                    'state' => 'operational',
                    'stateLabel' => 'Operational',
                    'checkedAt' => null,
                    'history' => null,
                ],
            ]),
            incidents: collect(),
            recentIncidents: collect(),
        );

        app(CustomerStatusPageProviderRegistry::class)->register('monitor', new class($page) implements CustomerStatusPageProvider
        {
            public function __construct(private readonly CustomerStatusPage $page) {}

            public function findPublished(string $slug): ?CustomerStatusPage
            {
                return $slug === $this->page->slug ? $this->page : null;
            }

            public function subscribe(string $slug, string $email): bool
            {
                return false;
            }
        });

        $this->get(route('core.status-pages.show', ['product' => 'monitor', 'slug' => 'acme-status']))
            ->assertOk()
            ->assertSee('Acme service status')
            ->assertSee('All systems operational')
            ->assertSee('Public API')
            ->assertSee('Powered by Buildpusher Monitor');
    }

    public function test_missing_or_unpublished_product_status_pages_return_not_found(): void
    {
        app(CustomerStatusPageProviderRegistry::class)->register('monitor', new class implements CustomerStatusPageProvider
        {
            public function findPublished(string $slug): ?CustomerStatusPage
            {
                return null;
            }

            public function subscribe(string $slug, string $email): bool
            {
                return false;
            }
        });

        $this->get(route('core.status-pages.show', ['product' => 'monitor', 'slug' => 'private-draft']))
            ->assertNotFound();

        $this->get(route('core.status-pages.show', ['product' => 'deployer', 'slug' => 'unregistered-product']))
            ->assertNotFound();
    }

    public function test_core_status_page_subscription_validates_email_and_delegates_to_the_product_owner(): void
    {
        $provider = new class implements CustomerStatusPageProvider
        {
            /** @var list<array{slug: string, email: string}> */
            public array $subscriptions = [];

            public function findPublished(string $slug): ?CustomerStatusPage
            {
                return null;
            }

            public function subscribe(string $slug, string $email): bool
            {
                $this->subscriptions[] = ['slug' => $slug, 'email' => $email];

                return true;
            }
        };
        app(CustomerStatusPageProviderRegistry::class)->register('deployer', $provider);
        $url = route('core.status-pages.subscribe', ['product' => 'deployer', 'slug' => 'acme-status']);

        $this->from(route('core.status-pages.show', ['product' => 'deployer', 'slug' => 'acme-status']))
            ->post($url, ['email' => 'not-an-email'])
            ->assertSessionHasErrors('email');
        $this->assertSame([], $provider->subscriptions);

        $this->from(route('core.status-pages.show', ['product' => 'deployer', 'slug' => 'acme-status']))
            ->post($url, ['email' => 'Ops@Example.com'])
            ->assertRedirect(route('core.status-pages.show', ['product' => 'deployer', 'slug' => 'acme-status']))
            ->assertSessionHas('status_subscription');

        $this->assertSame([
            ['slug' => 'acme-status', 'email' => 'ops@example.com'],
        ], $provider->subscriptions);
    }
}
