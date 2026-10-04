<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Filament\Resources\Feedback\Pages\ManageFeedback;
use App\Models\Account;
use App\Models\Feedback;
use App\Models\User;
use App\Notifications\NewFeedback;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

final class FeedbackTest extends TestCase
{
    use AdminHelpers;
    use RefreshDatabase;

    public function test_admins_read_and_resolve_feedback_and_nobody_else_can(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['name' => 'Grace']);
        $feedback = new Feedback;
        $feedback->forceFill(['user_id' => $user->id, 'kind' => 'problem', 'message' => 'The deploy log is cut off'])->save();

        $this->actingAs($user)->get('/admin/feedback')->assertNotFound();

        $this->as($admin)->get('/admin/feedback')->assertOk()->assertSee('The deploy log is cut off')->assertSee('Grace')->assertSee('Open');
        Livewire::test(ManageFeedback::class)->assertCanSeeTableRecords([$feedback])->callAction(TestAction::make('resolve')->table($feedback));
        $this->assertSame($admin->id, $feedback->refresh()->resolved_by);
        Livewire::test(ManageFeedback::class)->assertCanNotSeeTableRecords([$feedback]);
        Livewire::test(ManageFeedback::class, ['activeTab' => 'resolved'])->assertCanSeeTableRecords([$feedback])
            ->assertActionHasLabel(TestAction::make('resolve')->table($feedback), 'Open again')->callAction(TestAction::make('resolve')->table($feedback));
        $this->assertNull($feedback->refresh()->resolved_at);
    }
}
