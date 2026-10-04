<?php

declare(strict_types=1);

namespace Tests\Feature\Identity;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class LanguagesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * People pick their language in their profile; the app then uses it.
     */
    public function test_people_pick_their_language_in_the_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->getJson('/api/app/settings/profile')->assertOk()->assertJsonPath('locales.es', 'Español');

        $this->actingAs($user)->putJson('/api/app/auth/user/profile-information', ['name' => $user->name, 'email' => $user->email, 'locale' => 'es'])->assertOk();
        $this->assertSame('es', $user->refresh()->locale);
        $this->actingAs($user)->getJson('/api/app/auth/me')->assertJsonPath('locale', 'es');

        $this->actingAs($user)->putJson('/api/app/auth/user/profile-information', ['name' => $user->name, 'email' => $user->email, 'locale' => 'xx'])->assertJsonValidationErrors('locale');
        $this->actingAs($user)->putJson('/api/app/auth/user/profile-information', ['name' => $user->name, 'email' => $user->email, 'locale' => ''])->assertOk();
        $this->assertNull($user->refresh()->locale);
    }

    /**
     * Without a choice, the browser's language is used; a chosen one wins over the browser's.
     */
    public function test_without_a_choice_the_browser_language_is_used(): void
    {
        $this->postJson('/api/app/auth/login', [], ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8'])->assertJsonPath('errors.email.0', 'Le champ adresse e-mail est obligatoire.');
        $this->postJson('/api/app/auth/login', [], ['Accept-Language' => 'ja'])->assertJsonPath('errors.email.0', 'The email field is required.');

        $user = User::factory()->create(['locale' => 'de']);
        $this->actingAs($user)->getJson('/api/app/auth/me', ['Accept-Language' => 'fr'])->assertJsonPath('locale', 'de');
    }

    /**
     * Validation messages are translated.
     */
    public function test_validation_messages_are_translated(): void
    {
        $user = User::factory()->create(['locale' => 'pt']);

        $this->actingAs($user)->putJson('/api/app/auth/user/profile-information', ['name' => '', 'email' => $user->email])
            ->assertJsonPath('errors.name.0', 'O campo nome é obrigatório.');
    }

    /**
     * Every language translates every string, with the same placeholders and plural forms.
     */
    public function test_every_language_translates_every_string_with_the_same_placeholders(): void
    {
        /** @var array<string, string> $supported */
        $supported = config('app.supported_locales');
        $reference = null;

        foreach (array_keys($supported) as $locale) {
            if ($locale === 'en') {
                continue;
            }
            /** @var array<string, string> $strings */
            $strings = json_decode((string) file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            $reference ??= array_keys($strings);
            $this->assertEqualsCanonicalizing($reference, array_keys($strings), $locale.' has a different set of strings.');

            foreach ($strings as $english => $translated) {
                $this->assertNotSame('', trim($translated), $locale.': '.$english);
                $this->assertSame($this->placeholders($english), $this->placeholders($translated), $locale.': '.$english);
                $this->assertSame(substr_count($english, '|'), substr_count($translated, '|'), $locale.' plural forms: '.$english);
            }

            foreach (['validation', 'auth', 'pagination', 'passwords'] as $file) {
                $this->assertFileExists(lang_path($locale.'/'.$file.'.php'));
            }
        }
    }

    /**
     * Every string the API uses is translated.
     */
    public function test_every_interface_string_is_translated(): void
    {
        $this->assertSame(0, Artisan::call('lang:missing'), Artisan::output());
    }

    /**
     * The sorted, distinct placeholders in a string.
     *
     * @param  string  $text
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/:([A-Za-z_]+)/', $text, $matches);
        $names = array_values(array_unique($matches[1]));
        sort($names);

        return $names;
    }
}
