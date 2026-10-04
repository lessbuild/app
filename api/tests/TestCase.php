<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

abstract class TestCase extends BaseTestCase
{
    /**
     * Add the app API's assertions to test responses, and render pages without the built admin theme.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // The admin panel's theme is built by CI (vite build), not before tests: render pages without its manifest.
        $this->withoutVite();

        // The app's API answers a write with `{ "redirect": "/path" }` where a page used to redirect. Compare paths, so
        // tests can pass route() URLs (absolute) or the API's own paths (under /api/app) as before.
        TestResponse::macro('assertJsonRedirect', function (string $url): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            $this->assertSuccessful();
            $path = fn (string $address): string => preg_replace('#^/api/app(?=/)#', '', (parse_url($address, PHP_URL_PATH) ?: '/')).(($query = parse_url($address, PHP_URL_QUERY)) ? '?'.$query : '');
            Assert::assertSame($path($url), $path((string) $this->json('redirect')), 'The API sends the app somewhere else.');

            return $this;
        });

        // Text the page would show: look for it in the JSON's string values, as decoded (no escaped slashes or quotes).
        TestResponse::macro('assertJsonHasText', function (string ...$texts): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            $values = TestCase::jsonStrings($this->json());
            foreach ($texts as $text) {
                Assert::assertTrue(collect($values)->contains(fn (string $value): bool => str_contains($value, $text)), "The JSON has no text containing [{$text}].");
            }

            return $this;
        });
        TestResponse::macro('assertJsonLacksText', function (string ...$texts): TestResponse {
            /** @var TestResponse<\Symfony\Component\HttpFoundation\Response> $this */
            $values = TestCase::jsonStrings($this->json());
            foreach ($texts as $text) {
                Assert::assertFalse(collect($values)->contains(fn (string $value): bool => str_contains($value, $text)), "The JSON has text containing [{$text}].");
            }

            return $this;
        });
    }

    /**
     * Collect every string (and number, as text) in decoded JSON, at any depth.
     *
     * @param  mixed  $json
     * @return list<string>
     */
    public static function jsonStrings(mixed $json): array
    {
        if (is_array($json)) {
            return array_merge([], ...array_map(self::jsonStrings(...), array_values($json)));
        }

        return is_string($json) || is_int($json) || is_float($json) ? [(string) $json] : [];
    }
}
