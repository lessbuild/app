<?php

namespace App\Core\Http\Controllers;

use App\Core\Services\PlatformProductRouteLinks;
use App\Core\Services\ProductApiDocumentationRegistry;
use Illuminate\View\View;

final class CoreHelpController
{
    public function index(PlatformProductRouteLinks $links, ProductApiDocumentationRegistry $references): View
    {
        $documents = [
            'deployer' => collect([
                ['label' => __('Deployer guides'), 'description' => __('Projects, infrastructure, deployments, automation, and recovery.'), 'href' => route('core.help.deployer')],
                ['label' => __('Deployer API reference'), 'description' => __('API endpoints and request examples.'), 'href' => route('core.help.deployer.api')],
                ['label' => __('OpenAPI specification'), 'description' => __('Download the machine-readable Deployer API specification.'), 'href' => $links->to('deployer', 'openapi')],
            ])->filter(fn (array $item): bool => filled($item['href'] ?? null))->values(),
            'monitor' => collect([
                ['label' => __('Monitor API reference'), 'description' => __('Ingestion, checks, incidents, and alerting API guidance.'), 'href' => $references->reference('monitor') === null ? null : route('core.help.monitor.api')],
            ])->filter(fn (array $item): bool => filled($item['href'] ?? null))->values(),
            'analytics' => collect([
                ['label' => __('Analytics tracker and API reference'), 'description' => __('Browser tracking, event collection, and the versioned OpenAPI contract.'), 'href' => $references->reference('analytics') === null ? null : route('core.help.analytics.api')],
            ])->filter(fn (array $item): bool => filled($item['href'] ?? null))->values(),
        ];

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

    public function deployerApi(PlatformProductRouteLinks $links): View
    {
        return view('core::help.deployer-api', [
            'apiBaseUrl' => rtrim((string) (config('platform.products.deployer.url') ?: 'https://deployer.buildpusher.com'), '/'),
            'openApiUrl' => $links->to('deployer', 'openapi'),
        ]);
    }

    public function monitorApi(ProductApiDocumentationRegistry $references): View
    {
        $reference = $references->reference('monitor');
        abort_if($reference === null, 404);

        $curlExample = implode("\n", [
            "curl --request POST '{$reference->ingestUrl}' \\",
            "  --header 'Authorization: Bearer YOUR_ENVIRONMENT_TOKEN' \\",
            "  --header 'Content-Type: application/json' \\",
            "  --data '{\"batch_id\":\"connection-test-1\",\"events\":[{\"id\":\"connection-test-1\",\"type\":\"log\",\"name\":\"Connection test\",\"service\":\"my-service\",\"severity\":\"info\"}]}'",
        ]);

        return view('core::help.monitor-api', [
            'reference' => $reference,
            'curlExample' => $curlExample,
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
