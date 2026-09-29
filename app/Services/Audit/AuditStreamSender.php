<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AlertDestinationType;
use App\Models\AuditEntry;
use App\Models\AuditStream;
use App\Services\Monitoring\PublicWebhookTarget;
use App\Services\Storage\S3Client;
use App\Services\Storage\S3Location;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Sends one audit entry to a stream: a line to Slack, signed JSON to a webhook (public HTTPS only, at the checked
 * address), or a JSON object in S3 under audit/{account}/{date}/{entry}.json.
 */
final class AuditStreamSender
{
    /**
     * Create a new AuditStreamSender instance.
     *
     * @param  PublicWebhookTarget  $targets  Checks and resolves Slack and webhook addresses.
     * @param  S3Client  $s3  Writes S3 objects.
     */
    public function __construct(private readonly PublicWebhookTarget $targets, private readonly S3Client $s3) {}

    /**
     * Send the entry; throws when the stream didn't accept it.
     *
     * @param  AuditStream  $stream
     * @param  AuditEntry  $entry
     * @return void
     */
    public function send(AuditStream $stream, AuditEntry $entry): void
    {
        $record = $this->record($entry);
        $json = json_encode($record, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($stream->type === 's3') {
            $destination = $stream->destination ?? throw new RuntimeException('The backup destination was removed.');
            $prefix = trim((string) $destination->path_prefix, '/');
            $key = ($prefix !== '' ? $prefix.'/' : '').'audit/'.$entry->account_id.'/'.$entry->created_at->utc()->format('Y/m/d').'/'.$entry->id.'.json';
            $response = $this->s3->request(new S3Location($destination->endpoint, $destination->region, $destination->bucket, $destination->access_key, $destination->secret_key), 'PUT', $key, $json);
            $this->s3->assertSuccessful('audit stream write', $response);

            return;
        }

        $slack = $stream->type === 'slack';
        $url = (string) $stream->endpoint_url;
        $target = $this->targets->resolve($url, $slack ? AlertDestinationType::Slack : AlertDestinationType::Webhook);
        if ($target['error'] !== null || $target['host'] === null || $target['address'] === null) {
            throw new RuntimeException('The address isn’t a public HTTPS address ('.($target['error'] ?? 'invalid').').');
        }
        $body = $slack
            ? json_encode(['text' => "*{$record['actor']}* {$record['description']}".($record['ip_address'] !== null ? " · {$record['ip_address']}" : '')], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $json;
        $headers = ['User-Agent' => config('app.name').'-Audit/1.0'];
        if (! $slack) {
            $timestamp = (string) now('UTC')->timestamp;
            $headers['X-BuildPusher-Timestamp'] = $timestamp;
            $headers['X-BuildPusher-Signature'] = 'v1='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $stream->signing_secret);
        }
        $address = str_contains($target['address'], ':') ? '['.$target['address'].']' : $target['address'];
        $response = Http::withHeaders($headers)->withBody($body, 'application/json')->connectTimeout(3)->timeout(10)->withoutRedirecting()
            ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$target['host']}:443:{$address}"]]])->post($url);
        if (! $response->successful()) {
            throw new RuntimeException("The stream answered HTTP {$response->status()}.");
        }
    }

    /**
     * Get the entry as it's sent: who, what, where from and when.
     *
     * @param  AuditEntry  $entry
     * @return array{id: string, account_id: string|null, project_id: string|null, action: string, description: string, actor: string, actor_email: string|null, ip_address: string|null, user_agent: string|null, context: array<string, mixed>|null, at: string}
     */
    public function record(AuditEntry $entry): array
    {
        return [
            'id' => $entry->id, 'account_id' => $entry->account_id, 'project_id' => $entry->project_id,
            'action' => $entry->action->value, 'description' => $entry->action->describe($entry->context ?? []),
            'actor' => $entry->actor_name ?? 'System', 'actor_email' => $entry->actor_email,
            'ip_address' => $entry->ip_address, 'user_agent' => $entry->user_agent, 'context' => $entry->context,
            'at' => $entry->created_at->utc()->toIso8601String(),
        ];
    }
}
