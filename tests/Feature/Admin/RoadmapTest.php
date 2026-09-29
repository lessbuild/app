<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Account;
use App\Models\FeatureRequest;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\FeatureRequestShipped;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class RoadmapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Check the loop from feedback to the roadmap: a suggestion is written up (its sender voting), people vote on the
     * public page, and shipping it tells the voters.
     *
     * @return void
     */
    public function test_feedback_becomes_a_roadmap_request_that_people_vote_on_and_hear_about(): void
    {
        Notification::fake();
        $admin = $this->admin();
        $sender = $this->person();
        $voter = $this->person();

        $this->actingAs($sender)->post('/feedback', ['feedback_kind' => 'idea', 'feedback_message' => 'Please add Bitbucket pipelines', 'feedback_page' => route('roadmap')])->assertRedirect();
        $feedback = Feedback::query()->sole();

        $this->as($admin)->get('/admin/feedback')->assertOk()->assertSee('Add to roadmap');
        $this->as($admin)->post("/admin/feedback/{$feedback->id}/roadmap", ['title' => 'Bitbucket support', 'description' => 'Deploy from Bitbucket repositories.', 'status' => 'planned'])->assertRedirect('/admin/feedback');
        $request = FeatureRequest::query()->sole();
        $this->assertSame(['Bitbucket support', 'planned', 1], [$request->title, $request->status, $request->votes_count]);
        $this->assertSame($request->id, $feedback->refresh()->feature_request_id);
        $this->assertNotNull($feedback->resolved_at);

        // Guests see the roadmap; the private feedback never appears on it.
        auth()->logout();
        $this->get('/roadmap')->assertOk()->assertSee('Bitbucket support')->assertSee('Planned')->assertSee('Sign in to vote or suggest')->assertDontSee('Please add Bitbucket pipelines');

        $this->actingAs($voter)->post("/roadmap/{$request->id}/vote")->assertRedirect(route('roadmap').'#request-'.$request->id);
        $this->assertSame(2, $request->refresh()->votes_count);
        $this->actingAs($voter)->get('/roadmap')->assertOk()->assertSee('aria-pressed="true"', false)->assertSee('Suggest a feature');
        $this->actingAs($voter)->post("/roadmap/{$request->id}/vote");
        $this->assertSame(1, $request->refresh()->votes_count);
        $this->actingAs($voter)->post("/roadmap/{$request->id}/vote");

        // Other feedback can be linked to the same request, counting its sender once.
        $second = new Feedback;
        $second->forceFill(['user_id' => $voter->id, 'kind' => 'idea', 'message' => 'Bitbucket please'])->save();
        $this->as($admin)->post("/admin/feedback/{$second->id}/roadmap", ['feature_request_id' => $request->id])->assertRedirect();
        $this->assertSame(2, $request->refresh()->votes_count);

        $this->as($admin)->put("/admin/roadmap/{$request->id}", ['title' => 'Bitbucket support', 'description' => null, 'status' => 'shipped'])->assertRedirect('/admin/roadmap');
        $this->assertNotNull($request->refresh()->shipped_at);
        Notification::assertSentTo([$sender, $voter], FeatureRequestShipped::class);
        Notification::assertNotSentTo($admin, FeatureRequestShipped::class);
        $this->actingAs($voter)->post("/roadmap/{$request->id}/vote")->assertStatus(422);
        $this->get('/roadmap')->assertOk()->assertSee('Recently shipped')->assertSee('Bitbucket support');
    }

    /**
     * Check that only platform admins manage the roadmap, and that requests can be added and declined directly.
     *
     * @return void
     */
    public function test_only_admins_manage_the_roadmap(): void
    {
        $person = $this->person();
        $this->actingAs($person)->get('/admin/roadmap')->assertNotFound();
        $this->actingAs($person)->post('/admin/roadmap', ['title' => 'Mine', 'status' => 'planned'])->assertNotFound();

        $admin = $this->admin();
        $this->as($admin)->post('/admin/roadmap', ['title' => 'Dark launch flags', 'status' => 'bogus'])->assertSessionHasErrors('status');
        $this->as($admin)->post('/admin/roadmap', ['title' => 'Dark launch flags', 'status' => 'declined'])->assertRedirect('/admin/roadmap');
        $this->as($admin)->get('/admin/roadmap')->assertOk()->assertSee('Dark launch flags')->assertSee('Not planned');
        $this->get('/roadmap')->assertOk()->assertDontSee('Dark launch flags');
    }

    /**
     * Make someone with an account.
     *
     * @return User
     */
    private function person(): User
    {
        $user = User::factory()->create();
        Account::factory()->withMember($user)->create();

        return $user->refresh();
    }

    /**
     * Make a platform admin with two-factor authentication on.
     *
     * @return User
     */
    private function admin(): User
    {
        $user = $this->person();
        $user->forceFill(['is_platform_admin' => true, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();

        return $user->refresh();
    }

    /**
     * Act as the admin with a recently confirmed password.
     *
     * @param  User  $admin
     * @return static
     */
    private function as(User $admin): static
    {
        return $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->getTimestamp()]);
    }
}
