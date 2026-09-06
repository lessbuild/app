<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class BoundAccountRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_bound_notifications_reject_foreign_recipient_and_same_id_different_morph_type(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $this->actingAs($owner);

        foreach ([[$other->id, $other->getMorphClass()], [$owner->id, 'another-model']] as [$id, $type]) {
            $notification = DatabaseNotification::query()->create([
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'notifiable_type' => $type,
                'notifiable_id' => $id,
                'data' => ['title' => 'Private notification'],
            ]);

            $this->post(route('notifications.read', $notification))->assertNotFound();
            $this->post(route('notifications.unread', $notification))->assertNotFound();
            $this->delete(route('notifications.destroy', $notification))->assertNotFound();
            $this->assertNull($notification->fresh()->read_at);
        }

        $this->post(route('notifications.read', (string) Str::uuid()))->assertNotFound();
    }

    public function test_bound_tokens_reject_foreign_owner_and_same_id_different_morph_type(): void
    {
        config(['billing.enforce_entitlements' => false]);
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $foreign = $other->createToken('Foreign')->accessToken;
        $wrongType = $owner->createToken('Other model')->accessToken;
        $wrongType->forceFill(['tokenable_type' => 'another-model'])->save();
        $this->actingAs($owner);

        foreach ([$foreign, $wrongType] as $token) {
            $this->post(route('automation.tokens.rotate', $token))->assertNotFound();
            $this->delete(route('automation.tokens.destroy', $token))->assertNotFound();
            $this->assertNotNull($token->fresh());
        }
        $this->assertDatabaseCount('personal_access_tokens', 2);
    }
}
