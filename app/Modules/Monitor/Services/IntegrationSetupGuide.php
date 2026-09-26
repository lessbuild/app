<?php

namespace App\Modules\Monitor\Services;

use InvalidArgumentException;

final class IntegrationSetupGuide
{
    public const DEFAULT_STACK = 'any';

    /** @var array<string, string> */
    public const STACKS = [
        'any' => 'Any HTTP client',
        'laravel' => 'Laravel',
        'node' => 'Node.js',
        'python' => 'Python',
        'go' => 'Go',
        'java' => 'Java',
        'dotnet' => '.NET',
        'ruby' => 'Ruby',
        'php' => 'PHP',
    ];

    /**
     * @return array<string, string>
     */
    public function stacks(): array
    {
        return self::STACKS;
    }

    public function openTelemetryConfiguration(string $tracesEndpoint, string $logsEndpoint, string $metricsEndpoint): string
    {
        return implode("\n", [
            'OTEL_EXPORTER_OTLP_PROTOCOL=http/json',
            "OTEL_EXPORTER_OTLP_TRACES_ENDPOINT={$tracesEndpoint}",
            "OTEL_EXPORTER_OTLP_LOGS_ENDPOINT={$logsEndpoint}",
            "OTEL_EXPORTER_OTLP_METRICS_ENDPOINT={$metricsEndpoint}",
            'OTEL_EXPORTER_OTLP_HEADERS=Authorization=Bearer%20REPLACE_WITH_ENVIRONMENT_TOKEN',
            'OTEL_EXPORTER_OTLP_TIMEOUT=10000',
        ]);
    }

    /**
     * @return array{key: string, label: string, install: string, token: string, code: string, verification: string, receipt_endpoint: string}
     */
    public function for(string $stack, string $ingestEndpoint, string $receiptEndpoint): array
    {
        if (! array_key_exists($stack, self::STACKS)) {
            throw new InvalidArgumentException("Unsupported integration stack [{$stack}].");
        }

        $profile = match ($stack) {
            'laravel' => $this->laravelProfile(),
            'node' => $this->nodeProfile(),
            'python' => $this->pythonProfile(),
            'go' => $this->goProfile(),
            'java' => $this->javaProfile(),
            'dotnet' => $this->dotnetProfile(),
            'ruby' => $this->rubyProfile(),
            'php' => $this->phpProfile(),
            default => $this->httpProfile(),
        };

        return [
            'key' => $stack,
            'label' => self::STACKS[$stack],
            'install' => $profile['install'],
            'token' => $profile['token'],
            'code' => str_replace('INGEST_ENDPOINT', $ingestEndpoint, $profile['code']),
            'verification' => 'A queued delivery returns HTTP 202; a synchronously processed or replayed delivery returns HTTP 200. Use the receipt ID from the response with the same environment token to inspect processing.',
            'receipt_endpoint' => $receiptEndpoint,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function httpProfile(): array
    {
        return [
            'install' => 'No SDK required. Use cURL or any HTTP client that can send JSON.',
            'token' => 'Set BEACON_TOKEN in the process or secret manager that runs your collector.',
            'code' => <<<'BASH'
TEST_ID="connection-test-$(date +%s)"

curl --request POST 'INGEST_ENDPOINT' \
  --header "Authorization: Bearer ${BEACON_TOKEN}" \
  --header 'Content-Type: application/json' \
  --data @- <<JSON
{
    "batch_id": "${TEST_ID}",
    "events": [{
      "id": "${TEST_ID}",
      "type": "log",
      "name": "Connection test",
      "service": "my-service",
      "severity": "info"
    }]
}
JSON
BASH,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function laravelProfile(): array
    {
        return [
            'install' => 'No extra package is needed when using Laravel\'s HTTP client. Map BEACON_TOKEN to services.beacon.token in config/services.php.',
            'token' => 'Store BEACON_TOKEN in .env or your secret manager, then read it through config(\'services.beacon.token\').',
            'code' => <<<'PHP'
use Illuminate\Support\Facades\Http;

$testId = 'connection-test-'.now()->timestamp;

$response = Http::withToken(config('services.beacon.token'))
    ->acceptJson()
    ->post('INGEST_ENDPOINT', [
        'batch_id' => $testId,
        'events' => [[
            'id' => $testId,
            'type' => 'log',
            'name' => 'Connection test',
            'service' => config('app.name', 'laravel-app'),
            'severity' => 'info',
        ]],
    ]);

$response->throw();
PHP,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function nodeProfile(): array
    {
        return [
            'install' => 'Node.js 18+ includes fetch. No additional package is required.',
            'token' => 'Set BEACON_TOKEN in the Node.js process environment or deployment secret store.',
            'code' => <<<'JAVASCRIPT'
const testId = `connection-test-${Date.now()}`;

const response = await fetch('INGEST_ENDPOINT', {
    method: 'POST',
    headers: {
        Authorization: `Bearer ${process.env.BEACON_TOKEN}`,
        'Content-Type': 'application/json',
    },
    body: JSON.stringify({
        batch_id: testId,
        events: [{
            id: testId,
            type: 'log',
            name: 'Connection test',
            service: process.env.SERVICE_NAME ?? 'node-app',
            severity: 'info',
        }],
    }),
});

if (!response.ok) {
    throw new Error(`Telemetry was rejected (${response.status})`);
}

console.log(await response.json());
JAVASCRIPT,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function pythonProfile(): array
    {
        return [
            'install' => 'Install the small requests client with python -m pip install requests.',
            'token' => 'Set BEACON_TOKEN in the Python process environment or deployment secret store.',
            'code' => <<<'PYTHON'
import os
import time

import requests

timestamp = int(time.time())
response = requests.post(
    'INGEST_ENDPOINT',
    headers={
        'Authorization': f"Bearer {os.environ['BEACON_TOKEN']}",
        'Content-Type': 'application/json',
    },
    json={
        'batch_id': f'connection-test-{timestamp}',
        'events': [{
            'id': f'connection-test-{timestamp}',
            'type': 'log',
            'name': 'Connection test',
            'service': os.getenv('SERVICE_NAME', 'python-app'),
            'severity': 'info',
        }],
    },
    timeout=10,
)
response.raise_for_status()
print(response.json())
PYTHON,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function goProfile(): array
    {
        return [
            'install' => 'Go standard library only. Use net/http with a request timeout in your service.',
            'token' => 'Set BEACON_TOKEN in the Go process environment or deployment secret store.',
            'code' => <<<'GO'
package main

import (
    "bytes"
    "fmt"
    "net/http"
    "os"
)

func main() {
    payload := []byte(`{"batch_id":"connection-test-go","events":[{"id":"connection-test-go","type":"log","name":"Connection test","service":"go-app","severity":"info"}]}`)
    request, err := http.NewRequest(http.MethodPost, "INGEST_ENDPOINT", bytes.NewReader(payload))
    if err != nil {
        panic(err)
    }
    request.Header.Set("Authorization", "Bearer "+os.Getenv("BEACON_TOKEN"))
    request.Header.Set("Content-Type", "application/json")

    response, err := (&http.Client{}).Do(request)
    if err != nil {
        panic(err)
    }
    defer response.Body.Close()
    if response.StatusCode >= http.StatusBadRequest {
        panic(fmt.Sprintf("Telemetry was rejected: %s", response.Status))
    }
}
GO,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function javaProfile(): array
    {
        return [
            'install' => 'Java 17+ standard library only. Use HttpClient with a bounded request timeout.',
            'token' => 'Set BEACON_TOKEN in the Java process environment or deployment secret store.',
            'code' => <<<'JAVA'
import java.net.URI;
import java.net.http.HttpClient;
import java.net.http.HttpRequest;
import java.net.http.HttpResponse;

var client = HttpClient.newHttpClient();
var request = HttpRequest.newBuilder(URI.create("INGEST_ENDPOINT"))
    .header("Authorization", "Bearer " + System.getenv("BEACON_TOKEN"))
    .header("Content-Type", "application/json")
    .POST(HttpRequest.BodyPublishers.ofString("""
        {"batch_id":"connection-test-java","events":[{"id":"connection-test-java","type":"log","name":"Connection test","service":"java-app","severity":"info"}]}
        """))
    .build();

var response = client.send(request, HttpResponse.BodyHandlers.ofString());
if (response.statusCode() >= 400) {
    throw new IllegalStateException("Telemetry was rejected: " + response.statusCode());
}
System.out.println(response.body());
JAVA,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function dotnetProfile(): array
    {
        return [
            'install' => '.NET 6+ includes HttpClient and System.Net.Http.Json.',
            'token' => 'Set BEACON_TOKEN in the .NET process environment or deployment secret store.',
            'code' => <<<'CSHARP'
using System;
using System.Net.Http.Headers;
using System.Net.Http.Json;

using var client = new HttpClient();
var testId = $"connection-test-{DateTimeOffset.UtcNow.ToUnixTimeSeconds()}";
client.DefaultRequestHeaders.Authorization = new AuthenticationHeaderValue(
    "Bearer",
    Environment.GetEnvironmentVariable("BEACON_TOKEN"));

var response = await client.PostAsJsonAsync("INGEST_ENDPOINT", new
{
    batch_id = testId,
    events = new[]
    {
        new
        {
            id = testId,
            type = "log",
            name = "Connection test",
            service = "dotnet-app",
            severity = "info",
        },
    },
});

response.EnsureSuccessStatusCode();
Console.WriteLine(await response.Content.ReadAsStringAsync());
CSHARP,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function rubyProfile(): array
    {
        return [
            'install' => 'Ruby standard library only. Use Net::HTTP with an application timeout.',
            'token' => 'Set BEACON_TOKEN in the Ruby process environment or deployment secret store.',
            'code' => <<<'RUBY'
require 'uri'
require 'json'
require 'net/http'

test_id = "connection-test-#{Time.now.to_i}"
uri = URI('INGEST_ENDPOINT')
request = Net::HTTP::Post.new(uri)
request['Authorization'] = "Bearer #{ENV.fetch('BEACON_TOKEN')}"
request['Content-Type'] = 'application/json'
request.body = {
  batch_id: test_id,
  events: [{
    id: test_id,
    type: 'log',
    name: 'Connection test',
    service: ENV.fetch('SERVICE_NAME', 'ruby-app'),
    severity: 'info',
  }],
}.to_json

response = Net::HTTP.start(uri.hostname, uri.port, use_ssl: uri.scheme == 'https') do |http|
  http.open_timeout = 10
  http.read_timeout = 10
  http.request(request)
end
raise "Telemetry was rejected: #{response.code}" unless response.is_a?(Net::HTTPSuccess)
puts response.body
RUBY,
        ];
    }

    /**
     * @return array{install: string, token: string, code: string}
     */
    private function phpProfile(): array
    {
        return [
            'install' => 'PHP with the cURL extension enabled. The example is framework-independent.',
            'token' => 'Set BEACON_TOKEN in the PHP process environment or deployment secret store.',
            'code' => <<<'PHP'
<?php

$testId = 'connection-test-'.time();
$payload = [
    'batch_id' => $testId,
    'events' => [[
        'id' => $testId,
        'type' => 'log',
        'name' => 'Connection test',
        'service' => getenv('SERVICE_NAME') ?: 'php-app',
        'severity' => 'info',
    ]],
];

$handle = curl_init('INGEST_ENDPOINT');
curl_setopt_array($handle, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer '.getenv('BEACON_TOKEN'),
        'Content-Type: application/json',
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
]);

$body = curl_exec($handle);
$status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
curl_close($handle);

if ($status >= 400 || $body === false) {
    throw new RuntimeException('Telemetry was rejected.');
}
echo $body;
PHP,
        ];
    }
}
