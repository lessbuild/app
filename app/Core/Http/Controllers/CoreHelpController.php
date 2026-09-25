<?php

namespace App\Core\Http\Controllers;

use App\Core\Services\PlatformProductRouteLinks;
use App\Core\Services\ProductApiDocumentationRegistry;
use Illuminate\View\View;

final class CoreHelpController
{
    public function index(ProductApiDocumentationRegistry $references): View
    {
        $documents = collect([
            'deployer' => collect([
                ['label' => __('Deployer guides'), 'description' => __('Projects, infrastructure, deployments, automation, and recovery.'), 'href' => route('core.help.deployer')],
                ['label' => __('Deployer API reference'), 'description' => __('API endpoints and request examples.'), 'href' => route('core.help.deployer.api')],
                ['label' => __('OpenAPI specification'), 'description' => __('Download the machine-readable Deployer API specification.'), 'href' => $references->reference('deployer')?->openApiUrl],
            ])->filter(fn (array $item): bool => filled($item['href'] ?? null))->values(),
            'monitor' => collect([
                ['label' => __('Monitor API reference'), 'description' => __('Ingestion, checks, incidents, and alerting API guidance.'), 'href' => $references->reference('monitor') === null ? null : route('core.help.monitor.api')],
            ])->filter(fn (array $item): bool => filled($item['href'] ?? null))->values(),
            'analytics' => collect([
                ['label' => __('Analytics tracker and API reference'), 'description' => __('Browser tracking, event collection, and the versioned OpenAPI contract.'), 'href' => $references->reference('analytics') === null ? null : route('core.help.analytics.api')],
            ])->filter(fn (array $item): bool => filled($item['href'] ?? null))->values(),
        ]);

        return view('core::help.index', [
            'documents' => $documents,
        ]);
    }

    public function deployerGuide(): View
    {
        return view('core::help.deployer-guide', [
            'apiUrl' => route('core.help.deployer.api'),
        ]);
    }

    public function deployerApi(PlatformProductRouteLinks $links, ProductApiDocumentationRegistry $references): View
    {
        $reference = $references->reference('deployer');
        abort_if($reference === null, 404);

        $methods = ['get', 'post', 'put', 'patch', 'delete', 'options', 'head', 'trace'];
        $apiOperations = collect($reference->document['paths'])
            ->flatMap(fn (array $pathOperations, string $path) => collect($pathOperations)
                ->filter(fn (mixed $operation, string $method): bool => in_array(strtolower($method), $methods, true)
                    && is_array($operation))
                ->map(function (array $operation, string $method) use ($path, $reference): array {
                    $security = array_key_exists('security', $operation)
                        ? $operation['security']
                        : ($reference->document['security'] ?? []);

                    return [
                        'method' => strtoupper($method),
                        'path' => '/api/v1'.$path,
                        'scope' => $operation['x-required-scope'] ?? ($security === [] ? __('Public') : __('Bearer token')),
                        'description' => $operation['summary'] ?? __('API operation'),
                    ];
                }))
            ->values();
        $apiBaseUrl = rtrim($reference->baseUrl, '/').'/api/v1';
        $configurationRequest = data_get(
            $reference->document,
            'components.requestBodies.ConfigurationInput.content.application/json.examples.runtime.value',
        );

        return view('core::help.deployer-api', [
            'apiBaseUrl' => $apiBaseUrl,
            'deployerOrigin' => rtrim($reference->baseUrl, '/'),
            'openApiUrl' => $reference->openApiUrl,
            'automationUrl' => $links->to('deployer', 'automation.index'),
            'repositoriesUrl' => $links->to('deployer', 'repositories.index'),
            'apiOperations' => $apiOperations,
            'apiVersion' => $reference->document['info']['version'] ?? $reference->document['openapi'],
            'openApiVersion' => $reference->document['openapi'],
            'configurationRequestExample' => is_array($configurationRequest)
                ? json_encode($configurationRequest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                : null,
            'configurationPreviewCommand' => str_replace(':apiBaseUrl', $apiBaseUrl, <<<'CURL'
curl --request POST ":apiBaseUrl/projects/$PROJECT_ID/configuration/plan" \
  --header "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  --header "Content-Type: application/json" \
  --data-binary @configuration.json
CURL),
            'configurationReviewCommand' => str_replace(':apiBaseUrl', $apiBaseUrl, <<<'CURL'
curl --request POST ":apiBaseUrl/projects/$PROJECT_ID/configuration/reviews" \
  --header "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  --header "Content-Type: application/json" \
  --data-binary @configuration.json
CURL),
            'configurationApplyCommand' => str_replace(':apiBaseUrl', $apiBaseUrl, <<<'CURL'
curl --request POST ":apiBaseUrl/projects/$PROJECT_ID/configuration/reviews/$REVIEW_ID/apply" \
  --header "Authorization: Bearer $BUILDPUSHER_TOKEN" \
  --header "Content-Type: application/json" \
  --data '{}'
CURL),
        ]);
    }

    public function monitorApi(PlatformProductRouteLinks $links, ProductApiDocumentationRegistry $references): View
    {
        $reference = $references->reference('monitor');
        abort_if($reference === null, 404);

        $curlExample = implode("\n", [
            "curl --request POST '{$reference->ingestUrl}' \\",
            "  --header 'Authorization: Bearer YOUR_ENVIRONMENT_TOKEN' \\",
            "  --header 'Content-Type: application/json' \\",
            "  --data '{\"batch_id\":\"connection-test-1\",\"events\":[{\"id\":\"connection-test-1\",\"type\":\"log\",\"name\":\"Connection test\",\"service\":\"my-service\",\"severity\":\"info\"}]}'",
        ]);
        $alertWebhookSignatureExample = implode("\n", [
            'X-Beacon-Delivery: DELIVERY_ID',
            'X-Beacon-Timestamp: UNIX_SECONDS',
            'X-Beacon-Signature: v1=HMAC_SHA256(key, timestamp + "." + raw_body)',
        ]);

        return view('core::help.monitor-api', [
            'reference' => $reference,
            'curlExample' => $curlExample,
            'alertWebhookSignatureExample' => $alertWebhookSignatureExample,
            'alertDestinationsUrl' => $links->to('monitor', 'monitor.alert-destinations.index'),
        ]);
    }

    public function analyticsApi(ProductApiDocumentationRegistry $references): View
    {
        $reference = $references->reference('analytics');
        abort_if($reference === null, 404);

        $trackerSnippet = '<script defer src="'.$reference->baseUrl.'/tracker/v1.js" data-site="SITE_PUBLIC_ID"></script>';
        $curlExample = implode("\n", [
            "curl --request POST '{$reference->baseUrl}/api/v1/collect/YOUR_SITE_PUBLIC_ID' \\",
            "  --header 'Content-Type: application/json' \\",
            "  --data '{\"events\":[{\"id\":\"3b241101-e2bb-4255-8caf-4136c566a962\",\"type\":\"event\",\"path\":\"/checkout\",\"properties\":{\"name\":\"checkout_started\"}}]}'",
        ]);

        return view('core::help.analytics-api', [
            'reference' => $reference,
            'trackerSnippet' => $trackerSnippet,
            'curlExample' => $curlExample,
        ]);
    }
}
