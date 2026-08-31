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
        config(['lessbuild.registration.enabled' => true]);
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

    public function test_registration_closes_after_the_bootstrap_owner_is_created(): void
    {
        $owner = User::factory()->create();

        $this->get(route('register'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('registration');

        $this->post(route('register'), [
            'name' => 'Unexpected User',
            'email' => 'unexpected@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('login'))
            ->assertSessionHasErrors('registration');

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->get(route('login'))
            ->assertSuccessful()
            ->assertDontSee(route('register'));
        $this->get('/')
            ->assertSuccessful()
            ->assertDontSee(route('register'));
    }

    public function test_operator_can_explicitly_enable_additional_registration(): void
    {
        config(['lessbuild.registration.enabled' => true]);
        User::factory()->create();

        $this->get(route('register'))->assertSuccessful();
        $this->post(route('register'), [
            'name' => 'Second Operator',
            'email' => 'second@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('dashboard'));

        $this->assertDatabaseCount('users', 2);
        $this->assertAuthenticatedAs(User::query()->where('email', 'second@example.com')->firstOrFail());
    }

    public function test_operator_can_disable_even_first_user_registration(): void
    {
        config(['lessbuild.registration.allow_first_user' => false]);

        $this->get(route('register'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('registration');
        $this->post(route('register'), [
            'name' => 'Blocked Owner',
            'email' => 'blocked@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertRedirect(route('login'));

        $this->assertDatabaseCount('users', 0);
    }
}
