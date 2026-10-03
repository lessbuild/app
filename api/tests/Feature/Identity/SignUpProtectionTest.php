<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SignUpProtectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a sign-up form for the given address.
     *
     * @param  string  $email
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function form(string $email, array $extra = []): array
    {
        return ['name' => 'Nia New', 'email' => $email, 'password' => 'correct-horse-battery-9', 'password_confirmation' => 'correct-horse-battery-9', ...$extra];
    }

    /**
     * Check that a filled-in honeypot field stops the sign-up.
     *
     * @return void
     */
    public function test_the_hidden_field_stops_bots(): void
    {
        $this->get('/register')->assertOk()->assertSee('name="website"', false)->assertDontSee('cf-turnstile');
        $this->post('/register', $this->form('bot@example.test', ['website' => 'https://spam.test']))->assertSessionHasErrors('email');
        $this->assertDatabaseMissing(User::class, ['email' => 'bot@example.test']);
    }

    /**
     * Check that one address can make at most five accounts an hour.
     *
     * @return void
     */
    public function test_one_address_can_sign_up_five_times_an_hour(): void
    {
        foreach (range(1, 5) as $n) {
            $this->post('/register', $this->form("person{$n}@example.test"))->assertSessionHasNoErrors();
            $this->post('/logout');
        }
        $this->post('/register', $this->form('person6@example.test'))->assertSessionHasErrors('email');
        $this->assertDatabaseMissing(User::class, ['email' => 'person6@example.test']);

        $this->travel(61)->minutes();
        $this->post('/register', $this->form('person6@example.test'))->assertSessionHasNoErrors();
    }

    /**
     * Check that when Turnstile is set up, the widget shows, the CSP allows it and a sign-up needs a good token.
     *
     * @return void
     */
    public function test_turnstile_is_required_when_set_up(): void
    {
        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret-key']);
        Http::fake(['challenges.cloudflare.com/*' => fn (Request $request) => Http::response(['success' => $request['response'] === 'good-token'])]);

        $page = $this->get('/register')->assertOk()->assertSee('data-sitekey="site-key"', false);
        $this->assertStringContainsString('https://challenges.cloudflare.com', (string) $page->headers->get('Content-Security-Policy'));

        $this->post('/register', $this->form('a@example.test'))->assertSessionHasErrors('email');
        $this->post('/register', $this->form('a@example.test', ['cf-turnstile-response' => 'bad-token']))->assertSessionHasErrors('email');
        $this->post('/register', $this->form('a@example.test', ['cf-turnstile-response' => 'good-token']))->assertSessionHasNoErrors();
        $this->assertDatabaseHas(User::class, ['email' => 'a@example.test']);
        Http::assertSent(fn (Request $request) => $request['secret'] === 'secret-key');
    }
}
