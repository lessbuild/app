<?php

namespace Tests\Unit;

use App\Models\Project;
use App\Services\PreviewStackCatalog;
use Tests\TestCase;

class PreviewStackCatalogTest extends TestCase
{
    public function test_laravel_templates_declare_the_representative_preview_stack(): void
    {
        $stack = app(PreviewStackCatalog::class)->for(new Project(['preset' => 'laravel']));

        $this->assertSame(['queue', 'scheduler'], array_column($stack->processes, 'name'));
        $this->assertSame(['database', 'cache'], array_column($stack->resources, 'name'));
        $this->assertSame(['postgresql', 'valkey'], array_column($stack->resources, 'type'));
        $this->assertSame('php artisan db:seed --force', $stack->initialization?->command);
    }

    public function test_runtime_templates_without_preview_declarations_remain_unchanged(): void
    {
        $stack = app(PreviewStackCatalog::class)->for(new Project(['preset' => 'nextjs']));

        $this->assertSame([], $stack->processes);
        $this->assertSame([], $stack->resources);
        $this->assertNull($stack->initialization);
    }
}
