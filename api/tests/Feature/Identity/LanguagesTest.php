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

    public function test_people_pick_their_language_in_the_profile(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/profile')->assertOk()->assertSee('value="es"', false)->assertSee('Español');

        $this->actingAs($user)->put('/user/profile-information', ['name' => $user->name, 'email' => $user->email, 'locale' => 'es'])->assertSessionHasNoErrors();
        $this->assertSame('es', $user->refresh()->locale);

        $this->actingAs($user)->get('/settings/profile')->assertOk()->assertSee('lang="es"', false)->assertSee('Idioma');

        $this->actingAs($user)->put('/user/profile-information', ['name' => $user->name, 'email' => $user->email, 'locale' => 'xx'])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'locale');
        $this->actingAs($user)->put('/user/profile-information', ['name' => $user->name, 'email' => $user->email, 'locale' => ''])->assertSessionHasNoErrors();
        $this->assertNull($user->refresh()->locale);
    }

    public function test_without_a_choice_the_browser_language_is_used(): void
    {
        $this->get('/login', ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.8'])->assertOk()->assertSee('lang="fr"', false);
        $this->get('/login', ['Accept-Language' => 'ja'])->assertOk()->assertSee('lang="en"', false);

        $user = User::factory()->create(['locale' => 'de']);
        $this->actingAs($user)->get('/settings/profile', ['Accept-Language' => 'fr'])->assertSee('lang="de"', false);
    }

    public function test_validation_messages_are_translated(): void
    {
        $user = User::factory()->create(['locale' => 'pt']);

        $this->actingAs($user)->put('/user/profile-information', ['name' => '', 'email' => $user->email])
            ->assertSessionHasErrorsIn('updateProfileInformation', ['name' => 'O campo nome é obrigatório.']);
    }

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
