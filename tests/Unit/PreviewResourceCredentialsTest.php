<?php

namespace Tests\Unit;

use App\Models\EnvironmentResource;
use App\Services\PreviewResourceCredentials;
use Tests\TestCase;

class PreviewResourceCredentialsTest extends TestCase
{
    public function test_new_preview_valkey_resources_receive_a_random_password(): void
    {
        $variables = app(PreviewResourceCredentials::class)->valkey(null);

        $this->assertSame(48, strlen($variables['REDIS_PASSWORD']));
    }

    public function test_existing_preview_valkey_resources_preserve_passwordless_or_existing_credentials(): void
    {
        $credentials = app(PreviewResourceCredentials::class);

        $legacy = new EnvironmentResource(['is_preview_owned' => false]);
        $legacy->configuration = ['variables' => ['VALKEY_PORT' => '16380']];
        $this->assertSame([], $credentials->valkey($legacy));

        $existing = new EnvironmentResource(['is_preview_owned' => true]);
        $existing->configuration = [
            'variables' => ['REDIS_PASSWORD' => 'existing-preview-password'],
        ];
        $this->assertSame(
            ['REDIS_PASSWORD' => 'existing-preview-password'],
            $credentials->valkey($existing),
        );
    }
}
