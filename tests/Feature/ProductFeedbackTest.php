<?php

namespace Tests\Feature;

use App\Models\ProductFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProductFeedbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_submits_encrypted_private_feedback_and_sees_only_their_own(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $otherMember = User::factory()->create();
        $organization = $owner->currentOrganization;
        $organization->members()->attach($member, ['role' => 'developer']);
        $organization->members()->attach($otherMember, ['role' => 'viewer']);
        $member->update(['current_organization_id' => $organization->id]);
        $otherMember->update(['current_organization_id' => $organization->id]);

        $this->actingAs($member)->post(route('feedback.store'), [
            'category' => 'bug', 'severity' => 'high', 'title' => 'Deploy button stalls',
            'description' => 'private-feedback-details', 'reproduction_steps' => 'private-reproduction-steps',
            'page' => '/projects/12',
        ])->assertRedirect()->assertSessionHas('success');

        $feedback = ProductFeedback::query()->sole();
        $this->assertSame('private-feedback-details', $feedback->description);
        $stored = DB::table('product_feedback')->where('id', $feedback->id)->first();
        $this->assertStringNotContainsString('private-feedback-details', $stored->description);
        $this->assertStringNotContainsString('private-reproduction-steps', $stored->reproduction_steps);
        $this->actingAs($member)->get(route('feedback.index'))->assertOk()->assertSee('private-feedback-details');
        $this->actingAs($otherMember)->get(route('feedback.index'))->assertOk()->assertDontSee('Deploy button stalls');
        $this->actingAs($owner)->get(route('feedback.index'))->assertOk()->assertSee('private-feedback-details');
    }

    public function test_workspace_admin_can_review_feedback_but_outsiders_cannot(): void
    {
        $owner = User::factory()->create();
        $feedback = $owner->currentOrganization->productFeedback()->create([
            'user_id' => $owner->id, 'category' => 'idea', 'severity' => 'normal', 'status' => 'open',
            'title' => 'Compact timeline', 'description' => 'Show related releases.',
        ]);
        $outsider = User::factory()->create();

        $this->actingAs($outsider)->patch(route('feedback.update', $feedback), ['status' => 'resolved', 'review_response' => 'foreign'])->assertForbidden();
        $this->actingAs($owner)->patch(route('feedback.update', $feedback), ['status' => 'resolved', 'review_response' => 'Added to the release view.'])->assertRedirect();

        $feedback->refresh();
        $this->assertSame('resolved', $feedback->status);
        $this->assertSame('Added to the release view.', $feedback->review_response);
        $this->assertNotNull($feedback->resolved_at);
        $this->assertSame($owner->id, $feedback->reviewed_by);
    }

    public function test_feedback_rejects_external_context_and_secret_sized_payloads(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)->post(route('feedback.store'), [
            'category' => 'bug', 'severity' => 'normal', 'title' => 'Invalid context',
            'description' => str_repeat('x', 10001), 'page' => 'https://evil.example/path',
        ])->assertSessionHasErrors(['description', 'page']);

        $this->actingAs($owner)->post(route('feedback.store'), [
            'category' => 'bug', 'severity' => 'normal', 'title' => 'Query context',
            'description' => 'No query strings should be retained.', 'page' => '/projects/12?token=secret',
        ])->assertSessionHasErrors(['page']);

        $this->assertDatabaseCount('product_feedback', 0);
    }
}
