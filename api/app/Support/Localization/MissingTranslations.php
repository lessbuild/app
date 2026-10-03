<?php

declare(strict_types=1);

namespace App\Support\Localization;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Finds interface strings that a supported language has no translation for, by reading every `__()`,
 * `trans_choice()` and `@lang()` call with a literal string in the app's code, views, config and routes.
 */
final class MissingTranslations
{
    /**
     * Where translated strings are written.
     *
     * @var list<string>
     */
    private const array DIRECTORIES = ['app', 'resources/views', 'config', 'routes'];

    /**
     * The untranslated strings of each supported language other than English, keyed by locale.
     *
     * @return array<string, list<string>>
     */
    public function find(): array
    {
        $strings = $this->strings();
        $missing = [];
        foreach (array_keys((array) config('app.supported_locales', [])) as $locale) {
            if ($locale === 'en') {
                continue;
            }
            $path = lang_path($locale.'.json');
            /** @var array<string, string> $translated */
            $translated = is_file($path) ? (array) json_decode((string) file_get_contents($path), true) : [];
            $missing[(string) $locale] = array_values(array_filter($strings, fn (string $string): bool => ! isset($translated[$string])));
        }

        return $missing;
    }

    /**
     * Every distinct literal string passed to a translation helper, sorted.
     *
     * @return list<string>
     */
    public function strings(): array
    {
        $strings = [];
        foreach (self::DIRECTORIES as $directory) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), FilesystemIterator::SKIP_DOTS));
            /** @var SplFileInfo $file */
            foreach ($files as $file) {
                if (! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                $code = (string) file_get_contents($file->getPathname());
                preg_match_all("/(?:__|trans_choice|@lang)\\(\\s*'((?:[^'\\\\]|\\\\.)*)'/", $code, $single);
                foreach ($single[1] as $string) {
                    $strings[str_replace(['\\\'', '\\\\'], ['\'', '\\'], $string)] = true;
                }
                preg_match_all('/(?:__|trans_choice|@lang)\(\s*"((?:[^"\\\\$]|\\\\.)*)"/', $code, $double);
                foreach ($double[1] as $string) {
                    $strings[stripcslashes($string)] = true;
                }
            }
        }
        unset($strings['']);
        $strings = array_map(strval(...), array_keys($strings));
        sort($strings);

        return $strings;
    }
}
