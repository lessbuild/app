<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Analytics;

use App\Actions\Analytics\AcceptEventBatch;
use App\Contracts\Analytics\CountryLookup;
use App\Models\Account;
use App\Models\AnalyticsSite;
use App\Models\User;
use App\Services\Analytics\EventNormalizer;
use App\Support\Analytics\CollectionRequest;
use App\Support\Analytics\UserAgent;
use App\Support\IpRanges;
use Carbon\CarbonImmutable;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class StoreServerEventsController
{
    /**
     * Accept pageviews and custom events sent from a server (API token with analytics:write). Each event carries the
     * visitor's IP address and User-Agent, which are used for the daily visitor hash, location and device, and then
     * discarded. Bots and ignored addresses are dropped; the rest is processed like the tracker's events.
     *
     * @param  Request  $request
     * @param  User  $user
     * @param  AnalyticsSite  $site
     * @param  AcceptEventBatch  $accept
     * @param  CountryLookup  $countries
     * @param  EventNormalizer  $normalizer
     * @return JsonResponse
     */
    public function __invoke(Request $request, #[CurrentUser] User $user, AnalyticsSite $site, AcceptEventBatch $accept, CountryLookup $countries, EventNormalizer $normalizer): JsonResponse
    {
        $account = $request->attributes->get('account');
        abort_unless($account instanceof Account && $site->project->account_id === $account->id && $user->can('update', $site), 404);
        abort_unless($site->isCollectionAvailable(), 409, 'Collection is paused for this site.');
        $data = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.id' => ['nullable', 'uuid'],
            'events.*.type' => ['required', 'in:pageview,event'],
            'events.*.path' => ['required', 'string', 'max:2048'],
            'events.*.name' => ['required_if:events.*.type,event', 'nullable', 'string', 'max:80'],
            'events.*.properties' => ['nullable', 'array', 'max:24'],
            'events.*.ip' => ['nullable', 'ip'],
            'events.*.user_agent' => ['nullable', 'string', 'max:512'],
            'events.*.referrer' => ['nullable', 'string', 'max:2048'],
            'events.*.utm_source' => ['nullable', 'string', 'max:100'],
            'events.*.utm_medium' => ['nullable', 'string', 'max:100'],
            'events.*.utm_campaign' => ['nullable', 'string', 'max:150'],
            'events.*.utm_term' => ['nullable', 'string', 'max:150'],
            'events.*.utm_content' => ['nullable', 'string', 'max:150'],
            'events.*.visitor' => ['nullable', 'string', 'max:64'],
        ]);
        $now = CarbonImmutable::now();
        $events = [];
        $skipped = 0;
        foreach ($data['events'] as $event) {
            $ip = $event['ip'] ?? null;
            $agent = (string) ($event['user_agent'] ?? '');
            if (CollectionRequest::isBot($agent) || ($ip !== null && IpRanges::contains($site->excluded_ips ?? [], $ip))) {
                $skipped++;

                continue;
            }
            $device = UserAgent::parse($agent);
            $input = [
                ...$event,
                'id' => $event['id'] ?? (string) Str::uuid(),
                'referrer_host' => isset($event['referrer']) ? parse_url((string) $event['referrer'], PHP_URL_HOST) : null,
                'properties' => $event['type'] === 'event' ? ['name' => $event['name'], ...($event['properties'] ?? [])] : null,
                'device' => $agent === '' ? null : $device['device'], 'browser' => $agent === '' ? null : $device['browser'], 'browser_version' => $device['browser_version'],
                'os' => $agent === '' ? null : $device['os'], 'os_version' => $device['os_version'],
            ];
            $normalized = $normalizer->normalize($site, $input, $now, $ip, $agent, $countries->location($ip));
            if ($normalized === null) {
                $skipped++;
            } else {
                $events[] = $normalized;
            }
        }
        $result = $events === [] ? ['batch_id' => null, 'accepted' => 0] : $accept->handle($site, $events);

        return response()->json(['data' => [...$result, 'skipped' => $skipped]], 202);
    }
}
