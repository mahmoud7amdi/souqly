<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Arabic and English translation files must hold the same keys.
 *
 * Decision #3 is that every screen and every report reads naturally in Arabic.
 * The failure mode that decision runs into is not a missing translation — it is
 * a *silently* missing one. Laravel's `__()` returns the key itself when it
 * cannot resolve it, so a key present in `en` and absent from `ar` renders the
 * literal string `lang_v1.stock_accounting_method` in the middle of an Arabic
 * settings page. Nothing throws, no log line appears, and the only place it
 * shows up is on the screen of somebody using the language we built this for.
 *
 * {@see ScreensRenderTest} catches that for any screen the route walk reaches,
 * because its untranslated-key regex scans the rendered body. But it only sees
 * the locale the test runs in and only keys that some screen actually prints.
 * A key used solely in a mail template, a validation message, a queued job, or
 * a screen written next month is invisible to it. This test compares the files
 * directly, so parity holds for every key regardless of who reads it.
 *
 * Two structural checks come with it:
 *
 * - **Nesting is compared, not just the top level.** `perm` and `perm_group`
 *   are sub-arrays rather than flat `perm_<name>` keys, because a permission
 *   name contains dots (`user.view`) and `__()` splits on dots, so a flat key
 *   could never be found. A sub-array only half-translated is exactly as broken
 *   as a missing top-level key, and a naive `array_keys()` diff would not see
 *   it — hence the recursive walk.
 * - **Duplicate keys are rejected.** PHP does not error on a repeated array
 *   key; it keeps the last one and discards the rest in silence. So a second
 *   `'confirm_password' => …` appended 1100 lines below the first is not a
 *   syntax error, not a lint warning, and not visible to any key-set
 *   comparison — both files would still agree. It is only findable by reading
 *   the source text, which is what {@see duplicateKeys()} does.
 */
class LangParityTest extends TestCase
{
    /** Locales that must agree, and the file they must agree about. */
    private const LOCALES = ['ar', 'en'];

    #[Test]
    public function every_translation_file_holds_the_same_keys_in_both_languages(): void
    {
        foreach ($this->translationFiles() as $file) {
            $keys = [];

            foreach (self::LOCALES as $locale) {
                $path = lang_path($locale.'/'.$file);

                $this->assertFileExists($path, "`{$locale}/{$file}` is missing entirely.");

                $keys[$locale] = $this->flatten(require $path);
                sort($keys[$locale]);
            }

            [$first, $second] = self::LOCALES;

            $this->assertSame(
                [],
                array_values(array_diff($keys[$first], $keys[$second])),
                "Keys in `{$first}/{$file}` with no counterpart in `{$second}/{$file}`."
            );

            $this->assertSame(
                [],
                array_values(array_diff($keys[$second], $keys[$first])),
                "Keys in `{$second}/{$file}` with no counterpart in `{$first}/{$file}`."
            );
        }
    }

    #[Test]
    public function no_translation_file_defines_the_same_key_twice(): void
    {
        foreach ($this->translationFiles() as $file) {
            foreach (self::LOCALES as $locale) {
                $path = lang_path($locale.'/'.$file);

                $this->assertSame(
                    [],
                    $this->duplicateKeys($path),
                    "`{$locale}/{$file}` defines a key more than once. PHP keeps the "
                    ."last one silently, so the earlier definition is dead text."
                );
            }
        }
    }

    #[Test]
    public function no_translation_value_is_left_as_its_own_key(): void
    {
        /*
         * A placeholder like `'gross_profit' => 'gross_profit'` passes the parity
         * check above — the key exists in both files — and then renders as raw
         * snake_case on screen, which reads as a bug to the user and is invisible
         * to a key-set comparison. Cheap to check here, so it is checked.
         *
         * Only multi-word keys count. A single word can legitimately equal its
         * own translation — `'and' => 'and'` is correct English, not an
         * oversight — while no natural phrase in any language is spelled
         * `stock_accounting_method`, so an underscore in a value that matches its
         * key is a placeholder with certainty rather than a guess.
         */
        foreach ($this->translationFiles() as $file) {
            foreach (self::LOCALES as $locale) {
                $offenders = $this->keysEqualToTheirValue(require lang_path($locale.'/'.$file));

                $this->assertSame(
                    [],
                    $offenders,
                    "`{$locale}/{$file}` leaves a value identical to its key, which "
                    .'renders as snake_case on screen.'
                );
            }
        }
    }

    /* ================================================================
     | Internals
     ================================================================ */

    /**
     * Every translation file present in *either* locale.
     *
     * Read from both directories and merged, so a file added to one language and
     * forgotten in the other is caught by the assertFileExists above rather than
     * quietly skipped — which is what listing only `en/` would do.
     *
     * @return array<int, string>
     */
    private function translationFiles(): array
    {
        $files = [];

        foreach (self::LOCALES as $locale) {
            foreach (glob(lang_path($locale.'/*.php')) ?: [] as $path) {
                $files[basename($path)] = true;
            }
        }

        $names = array_keys($files);
        sort($names);

        $this->assertNotEmpty($names, 'No translation files found to compare.');

        return $names;
    }

    /**
     * Flatten a nested translation array to dot-joined leaf paths.
     *
     * @param  array<string, mixed>  $translations
     * @return array<int, string>
     */
    private function flatten(array $translations, string $prefix = ''): array
    {
        $keys = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                // An empty sub-array is a leaf for comparison purposes: there is
                // nothing beneath it, and recursing would drop it silently.
                $keys = $value === []
                    ? array_merge($keys, [$path])
                    : array_merge($keys, $this->flatten($value, $path));

                continue;
            }

            $keys[] = $path;
        }

        return $keys;
    }

    /**
     * Keys defined more than once at the same indentation in the file's source.
     *
     * Textual rather than structural on purpose — `require` has already
     * collapsed the duplicates by the time we could inspect the array. Grouping
     * by indentation keeps `'name'` inside `perm` from colliding with a
     * top-level `'name'`, which are genuinely different keys.
     *
     * @return array<int, string>
     */
    private function duplicateKeys(string $path): array
    {
        $seen = [];
        $duplicates = [];

        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (! preg_match("/^(\s+)'([^']+)'\s*=>/", $line, $match)) {
                continue;
            }

            $slot = strlen($match[1]).':'.$match[2];

            if (isset($seen[$slot])) {
                $duplicates[] = $match[2];

                continue;
            }

            $seen[$slot] = true;
        }

        return array_values(array_unique($duplicates));
    }

    /**
     * @param  array<string, mixed>  $translations
     * @return array<int, string>
     */
    private function keysEqualToTheirValue(array $translations, string $prefix = ''): array
    {
        $offenders = [];

        foreach ($translations as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $offenders = array_merge($offenders, $this->keysEqualToTheirValue($value, $path));

                continue;
            }

            if (is_string($value) && $value === (string) $key && str_contains((string) $key, '_')) {
                $offenders[] = $path;
            }
        }

        return $offenders;
    }
}
