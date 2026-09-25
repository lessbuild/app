<?php

namespace Tests\Unit\Core;

use App\Core\Contracts\ProductResourceRestorationProvider;
use App\Core\Data\Restoration\NativeRestorationReceipt;
use App\Core\Data\Restoration\NativeRestorationSnapshot;
use App\Core\Data\Restoration\NativeRestorationState;
use App\Core\Data\Restoration\ResourceRestorationAttempt;
use App\Core\Data\Restoration\ResourceRestorationTarget;
use App\Core\Models\PlatformUser;
use App\Core\Services\Restoration\ProductResourceRestorationRegistry;
use Closure;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductResourceRestorationRegistryTest extends TestCase
{
    public function test_provider_lookup_is_product_and_resource_type_specific(): void
    {
        $registry = new ProductResourceRestorationRegistry;
        $provider = $this->provider();
        $registry->register($provider);
        $registry->register($provider);
        $this->assertSame($provider, $registry->get('monitor', 'application'));
        $this->assertSame($provider, $registry->get('monitor', 'environment'));
        $this->assertNull($registry->get('deployer', 'application'));
        $this->assertNull($registry->get('monitor', 'site'));
    }

    public function test_invalid_provider_metadata_is_rejected(): void
    {
        $registry = new ProductResourceRestorationRegistry;
        $this->expectException(LogicException::class);
        $registry->register($this->provider([]));
    }

    public function test_receipt_hash_is_order_independent_but_binds_request_revision_and_truthful_state(): void
    {
        $application = new NativeRestorationState('application', '1', 'active');
        $environment = new NativeRestorationState('environment', '2', 'paused');
        $receipt = new NativeRestorationReceipt('request-one', 8, [$application, $environment]);
        $this->assertSame($receipt->hash(), (new NativeRestorationReceipt('request-one', 8, [$environment, $application]))->hash());
        $this->assertNotSame($receipt->hash(), (new NativeRestorationReceipt('request-one', 9, [$application, $environment]))->hash());
        $this->assertNotSame($receipt->hash(), (new NativeRestorationReceipt('request-two', 8, [$application, $environment]))->hash());
        $this->assertNotSame($receipt->hash(), (new NativeRestorationReceipt('request-one', 8, [$application, new NativeRestorationState('environment', '2', 'active')]))->hash());
    }

    private function provider(array $types = ['application', 'environment']): ProductResourceRestorationProvider
    {
        return new class($types) implements ProductResourceRestorationProvider
        {
            public function __construct(private readonly array $types) {}

            public function product(): string
            {
                return 'monitor';
            }

            public function resourceTypes(): array
            {
                return $this->types;
            }

            public function inspect(PlatformUser $actor, ResourceRestorationTarget $target, ?string $receiptRequestId = null): NativeRestorationSnapshot
            {
                throw new LogicException('Not used.');
            }

            public function apply(ResourceRestorationAttempt $attempt): NativeRestorationReceipt
            {
                throw new LogicException('Not used.');
            }

            public function withCurrentReceipt(ResourceRestorationAttempt $attempt, Closure $commit): void
            {
                throw new LogicException('Not used.');
            }
        };
    }
}
