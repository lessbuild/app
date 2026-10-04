<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

abstract class TestCase extends BaseTestCase
{
    /**
     * Add the app API's assertions to test responses.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The app's API answers a write with `{ "redirect": "/path" }` where a page used to redirect. Compare paths, so
        // tests can pass route() URLs (absolute) or the API's own paths (under /api/app) as before.
        TestResponse::macro('assertJsonRedirect', function (string $url): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            $this->assertSuccessful();
            $path = fn (string $address): string => preg_replace('#^/api/app(?=/)#', '', (parse_url($address, PHP_URL_PATH) ?: '/')).(($query = parse_url($address, PHP_URL_QUERY)) ? '?'.$query : '');
            Assert::assertSame($path($url), $path((string) $this->json('redirect')), 'The API sends the app somewhere else.');

            return $this;
        });
    }
}
