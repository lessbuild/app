<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Enums\AlertDeliveryStatus;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Models\PushSubscription;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\AlertDispatcher;
use App\Services\Monitoring\WebPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

final class PushNotificationsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check a person turns on push for a device (only real push services are accepted), a Push destination sends
     * alerts there encrypted and signed so only that device can read them, and devices that unsubscribed are dropped.
     *
     * @return void
     */
    public function test_alerts_reach_devices_by_push(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $device = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->assertInstanceOf(OpenSSLAsymmetricKey::class, $device);
        $details = (array) openssl_pkey_get_details($device);
        $public = "\x04".str_pad((string) $details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad((string) $details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $auth = random_bytes(16);
        $keys = ['p256dh' => WebPush::encode($public), 'auth' => WebPush::encode($auth)];

        $this->actingAs($owner)->getJson('/api/app/settings/notifications')->assertOk()->assertJsonPath('pushKey', app(WebPush::class)->publicKey());
        $this->actingAs($owner)->postJson('/api/app/settings/push-devices', ['endpoint' => 'https://evil.example/push', 'keys' => $keys])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/api/app/settings/push-devices', ['endpoint' => 'https://fcm.googleapis.com/fcm/send/device-1', 'keys' => $keys, 'device' => 'Android · Chrome'])->assertCreated();
        $this->actingAs($owner)->getJson('/api/app/settings/notifications')->assertOk()->assertJsonPath('pushDevices.0.device', 'Android · Chrome');

        $this->actingAs($owner)->postJson("/api/app/projects/{$project->id}/monitoring/alerts", ['name' => 'My phone', 'type' => 'push', 'enabled' => '1', 'recipient_user_id' => $owner->id])->assertSuccessful();
        $destination = AlertDestination::query()->where('name', 'My phone')->sole();
        $this->assertSame($owner->name, $destination->targetLabel());

        Http::fake(['fcm.googleapis.com/*' => Http::sequence()->push('', 201)->push('', 410)]);
        $send = function () use ($destination): AlertDeliveryStatus {
            $delivery = app(AlertDispatcher::class)->queue($destination, ['event' => 'test', 'title' => 'Test alert', 'kind' => 'test']);
            app(AlertDeliveryRunner::class)->process($delivery->id, 0);

            return $delivery->refresh()->status;
        };
        $this->assertSame(AlertDeliveryStatus::Accepted, $send());
        Http::assertSent(function (Request $request) use ($device, $public, $auth): bool {
            $this->assertSame(['aes128gcm'], $request->header('Content-Encoding'));
            $this->assertStringStartsWith('vapid t=', $request->header('Authorization')[0] ?? '');
            // Decrypt as the device would (RFC 8291).
            $body = $request->body();
            [$salt, $server, $cipher] = [substr($body, 0, 16), substr($body, 21, ord($body[20])), substr($body, 21 + ord($body[20]))];
            $pem = "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode((string) hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200').$server), 64, "\n")."-----END PUBLIC KEY-----\n";
            $serverKey = openssl_pkey_get_public($pem);
            $this->assertNotFalse($serverKey);
            $ikm = hash_hkdf('sha256', (string) openssl_pkey_derive($serverKey, $device, 32), 32, "WebPush: info\0".$public.$server, $auth);
            $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt), OPENSSL_RAW_DATA, hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt), substr($cipher, -16));
            $message = json_decode(rtrim((string) $plain, "\x02"), true);
            $this->assertIsArray($message);
            $this->assertStringContainsString('Test alert', (string) $message['title']);

            return true;
        });

        $this->assertSame(AlertDeliveryStatus::Failed, $send(), 'The device unsubscribed (410), so there is nowhere left to send.');
        $this->assertSame(0, PushSubscription::query()->count());
    }
}
