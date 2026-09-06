<?php

namespace Tests\Feature;

use App\Services\DigitalOcean;
use Exception;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DigitalOceanResponseContractTest extends TestCase
{
    #[DataProvider('responses')]
    public function test_response_contracts_return_decoded_arrays(string $method, array $arguments, array $payload, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.digitalocean.com/*' => Http::response($payload)]);

        $this->assertSame($expected, (new DigitalOcean('test-token'))->{$method}(...$arguments));
        Http::assertSentCount(1);
    }

    #[DataProvider('malformedResponses')]
    public function test_malformed_success_payloads_raise_a_provider_error(string $method, array $arguments): void
    {
        Http::preventStrayRequests();
        Http::fake(['api.digitalocean.com/*' => Http::response('private-provider-payload', 200)]);
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DigitalOcean returned an incomplete API response.');

        (new DigitalOcean('test-token'))->{$method}(...$arguments);
    }

    public function test_droplet_name_validation_uses_the_standard_exception_without_http_extension(): void
    {
        Http::preventStrayRequests();
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Missing the 'name' parameter.");

        (new DigitalOcean('test-token'))->createDroplet([]);
    }

    public static function malformedResponses(): array
    {
        return array_map(fn (array $response): array => array_slice($response, 0, 2), self::responses());
    }

    public static function responses(): array
    {
        return [
            ['getRegions', [], ['regions' => [['slug' => 'lon1']]], [['slug' => 'lon1']]],
            ['getSizes', [], ['sizes' => []], []],
            ['getImages', ['distribution'], ['images' => [['id' => 1]]], [['id' => 1]]],
            ['getDroplets', [], ['droplets' => [['id' => 2]]], [['id' => 2]]],
            ['getDroplet', ['3'], ['droplet' => ['id' => 3]], ['id' => 3]],
            ['createDroplet', [['name' => 'test']], ['droplet' => ['id' => 4]], ['droplet' => ['id' => 4]]],
        ];
    }
}
