<?php

declare(strict_types=1);

namespace Tests\Feature\Monitoring;

use App\Enums\AlertDeliveryStatus;
use App\Enums\AlertDestinationType;
use App\Models\AlertDestination;
use App\Models\Project;
use App\Services\Monitoring\AlertDeliveryRunner;
use App\Services\Monitoring\AlertDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TwilioAlertsTest extends TestCase
{
    use MonitoringHelpers;
    use RefreshDatabase;

    /**
     * Check that text and phone destinations only appear once Twilio is set up, keep the number encrypted and
     * masked, and send through Twilio's API (a call reads the alert aloud), within the daily limit per number.
     *
     * @return void
     */
    public function test_alerts_can_be_texted_or_phoned_through_twilio(): void
    {
        $project = Project::factory()->withServices(['monitoring'])->create();
        $owner = $this->ownerOf($project);
        $alerts = "/projects/{$project->id}/monitoring/alerts";

        $this->actingAs($owner)->get($alerts)->assertOk()->assertDontSee('Text message (SMS)');
        $this->actingAs($owner)->post($alerts, ['name' => 'Phone', 'type' => 'sms', 'enabled' => '1', 'phone_number' => '+447700900123'])->assertSessionHasErrors('type');

        config(['services.twilio' => ['account_sid' => 'AC123', 'auth_token' => 'secret', 'from' => '+15005550006', 'daily_limit' => 2]]);
        $this->actingAs($owner)->get($alerts)->assertOk()->assertSee('Text message (SMS)')->assertSee('Phone call');
        $this->actingAs($owner)->post($alerts, ['name' => 'Bad', 'type' => 'sms', 'enabled' => '1', 'phone_number' => '07700 900123'])->assertSessionHasErrors('phone_number');
        $this->actingAs($owner)->post($alerts, ['name' => 'Amy SMS', 'type' => 'sms', 'enabled' => '1', 'phone_number' => '+447700900123'])->assertRedirect();
        $this->actingAs($owner)->post($alerts, ['name' => 'Amy call', 'type' => 'voice', 'enabled' => '1', 'phone_number' => '+447700900123'])->assertRedirect();
        $sms = AlertDestination::query()->where('name', 'Amy SMS')->sole();
        $call = AlertDestination::query()->where('name', 'Amy call')->sole();
        $this->assertSame([AlertDestinationType::Sms, 'tel:+447700900123', '+44 ••• 123'], [$sms->type, $sms->endpoint_url, $sms->targetLabel()]);

        Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
        $payload = ['event' => 'test', 'event_label' => 'Opened', 'title' => 'API <down> & slow', 'project' => 'Shop', 'environment' => 'Production'];
        $send = function (AlertDestination $destination) use ($payload): AlertDeliveryStatus {
            $delivery = app(AlertDispatcher::class)->queue($destination, $payload);
            app(AlertDeliveryRunner::class)->process($delivery->id, 0);

            return $delivery->refresh()->status;
        };

        $this->assertSame(AlertDeliveryStatus::Accepted, $send($sms));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.twilio.com/2010-04-01/Accounts/AC123/Messages.json'
            && $request['To'] === '+447700900123' && $request['From'] === '+15005550006'
            && str_contains((string) $request['Body'], 'Opened — API <down> & slow. Shop, Production.'));
        $this->assertSame(AlertDeliveryStatus::Accepted, $send($call));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/Calls.json') && str_contains((string) $request['Twiml'], 'API &lt;down&gt; &amp; slow'));

        // The third alert to the same number today is over the limit of two.
        $this->assertSame(AlertDeliveryStatus::Failed, $send($sms));
        Http::assertSentCount(2);
    }
}
