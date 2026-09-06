<?php

namespace Tests\Feature;

use App\Services\ApplicationConfigurationDocument;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApplicationConfigurationOpenApiTest extends TestCase
{
    public function test_configuration_routes_and_parameters_are_documented_for_automation(): void
    {
        $document = json_decode(file_get_contents(public_path('openapi.json')), true, 512, JSON_THROW_ON_ERROR);
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/projects/') && str_contains($route->uri(), '/configuration/'));
        $this->assertNotEmpty($routes);
        foreach ($routes as $route) {
            $path = '/'.substr($route->uri(), strlen('api/v1/'));
            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $operation = $document['paths'][$path][strtolower($method)] ?? null;
                $this->assertNotNull($operation, $method.' '.$path.' is missing from the API reference.');
                $this->assertSame('manage', $operation['x-required-scope']);
                $parameters = array_map(function ($parameter) use ($document) {
                    $name = basename($parameter['$ref']);

                    return $document['components']['parameters'][$name]['name'];
                }, $operation['parameters']);
                $this->assertSame($route->parameterNames(), $parameters);
            }
        }
    }

    public function test_published_configuration_examples_parse_and_removal_bindings_are_an_empty_object(): void
    {
        $document = json_decode(file_get_contents(public_path('openapi.json')), false, 512, JSON_THROW_ON_ERROR);
        $examples = $document->components->requestBodies->ConfigurationInput->content->{'application/json'}->examples;
        foreach ($examples as $example) {
            $configuration = app(ApplicationConfigurationDocument::class)->parse($example->value->document);
            $this->assertSame(2, $configuration['version']);
            $this->assertInstanceOf(\stdClass::class, $example->value->bindings);
        }
        $this->assertSame([], get_object_vars($examples->removalOnly->value->bindings));
        $this->assertSame([], app(ApplicationConfigurationDocument::class)->parse($examples->removalOnly->value->document)['environments']);
    }
}
