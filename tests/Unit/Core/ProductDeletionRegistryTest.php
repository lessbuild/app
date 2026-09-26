<?php

namespace Tests\Unit\Core;

use App\Core\Contracts\ProductDeletionProvider;
use App\Core\Data\Deletion\ProductDeletionAttempt;
use App\Core\Data\Deletion\ProductDeletionPreview;
use App\Core\Data\Deletion\ProductDeletionResult;
use App\Core\Data\Deletion\ProductDeletionTarget;
use App\Core\Services\Deletion\ProductDeletionRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;

final class ProductDeletionRegistryTest extends TestCase
{
    public function test_provider_is_retrievable_by_its_product_key(): void
    {
        $registry = new ProductDeletionRegistry;
        $provider = new RegistryDeletionProvider('analytics');

        $registry->register($provider);

        $this->assertSame($provider, $registry->get('analytics'));
        $this->assertNull($registry->get('unknown'));
    }

    public function test_duplicate_product_registration_is_rejected(): void
    {
        $registry = new ProductDeletionRegistry;
        $registry->register(new RegistryDeletionProvider('monitor'));

        $this->expectException(LogicException::class);
        $registry->register(new RegistryDeletionProvider('monitor'));
    }
}

final class RegistryDeletionProvider implements ProductDeletionProvider
{
    public function __construct(private readonly string $key) {}

    public function product(): string
    {
        return $this->key;
    }

    public function inspect(ProductDeletionTarget $target): ProductDeletionPreview
    {
        return new ProductDeletionPreview;
    }

    public function prepare(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        return new ProductDeletionResult('ready');
    }

    public function purge(ProductDeletionAttempt $attempt): ProductDeletionResult
    {
        return new ProductDeletionResult('completed');
    }
}
