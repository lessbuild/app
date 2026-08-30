<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_register_with_the_fields_shown_by_the_form(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'ADA@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ]);

        $user = User::query()->where('email', 'ada@example.com')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Ada Lovelace', $user->name);
        $this->assertTrue(Hash::check('correct-horse-battery-staple', $user->password));
        $response->assertRedirect(route('dashboard'));
    }

    public function test_registration_validates_the_visible_fields(): void
    {
        User::factory()->create(['email' => 'existing@example.com']);

        $this->from(route('register'))->post(route('register'), [
            'name' => '',
            'email' => 'existing@example.com',
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertRedirect(route('register'))
            ->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertGuest();
    }
}
