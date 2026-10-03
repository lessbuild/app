<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class ComponentGalleryTest extends TestCase
{
    public function test_gallery_renders_the_signal_primitives_outside_production(): void
    {
        $this->get('/_gallery')
            ->assertOk()
            ->assertSee('Signal component gallery')
            ->assertSee('ui-btn', false)
            ->assertSee('data-modal-trigger="gallery-modal"', false);
    }

    public function test_gallery_is_hidden_in_production(): void
    {
        $this->app->detectEnvironment(fn (): string => 'production');

        $this->get('/_gallery')->assertNotFound();
    }
}
