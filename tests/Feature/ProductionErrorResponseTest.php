<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ProductionErrorResponseTest extends TestCase
{
    public function test_html_500_has_the_same_safe_incident_reference_as_the_structured_log(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        Route::get('/testing/unexpected-html-error', function (): never {
            throw new RuntimeException('private-token-value');
        });

        $response = $this->get('/testing/unexpected-html-error')
            ->assertInternalServerError()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertSee('Something went wrong')
            ->assertSee('Try again')
            ->assertSee('Go to dashboard')
            ->assertDontSee('private-token-value')
            ->assertDontSee('RuntimeException');

        preg_match('/Reference:<\/strong>\s*([0-9a-f-]{36})/i', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $incidentId = $matches[1];
        $response->assertHeader('x-incident-id', $incidentId);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $incidentId);

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'Unhandled application exception.'
                && $context['incident_id'] === $incidentId
                && $context['exception'] instanceof RuntimeException
                && $context['exception']->getMessage() === 'private-token-value',
        )->once();
    }

    public function test_json_500_exposes_only_a_safe_machine_readable_reference(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        Route::get('/testing/unexpected-json-error', function (): never {
            throw new RuntimeException('private-json-token');
        });

        $response = $this->getJson('/testing/unexpected-json-error')
            ->assertInternalServerError()
            ->assertHeader('cache-control', 'no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertJsonStructure(['message', 'reference'])
            ->assertJsonPath('message', 'An unexpected server error occurred.')
            ->assertJsonMissing(['exception' => RuntimeException::class]);

        $content = $response->getContent();
        $this->assertStringNotContainsString('private-json-token', $content);
        $this->assertStringNotContainsString('trace', $content);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/i', $response->json('reference'));
        $response->assertHeader('x-incident-id', $response->json('reference'));

        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'Unhandled application exception.'
                && $context['incident_id'] === $response->json('reference'),
        )->once();
    }

    public function test_expected_http_errors_keep_their_normal_status_page(): void
    {
        config(['app.debug' => false]);

        $this->get('/testing/route-that-does-not-exist')
            ->assertNotFound()
            ->assertDontSee('Something went wrong')
            ->assertDontSee('Reference:');
    }

    public function test_explicit_http_500_is_correlated_even_though_laravel_normally_ignores_it(): void
    {
        config(['app.debug' => false]);
        Log::spy();
        Route::get('/testing/explicit-http-500', function (): never {
            abort(500, 'private-explicit-error');
        });

        $response = $this->get('/testing/explicit-http-500')
            ->assertInternalServerError()
            ->assertSee('Reference:')
            ->assertDontSee('private-explicit-error');

        preg_match('/Reference:<\/strong>\s*([0-9a-f-]{36})/i', $response->getContent(), $matches);
        $this->assertArrayHasKey(1, $matches);
        $response->assertHeader('x-incident-id', $matches[1]);
        Log::shouldHaveReceived('error')->withArgs(
            fn (string $message, array $context): bool => $message === 'Unhandled application exception.'
                && $context['incident_id'] === $matches[1]
                && $context['exception']->getMessage() === 'private-explicit-error',
        )->once();
    }
}
