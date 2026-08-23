<?php

/**
 * Adds translation keys to lang/{ar,en}/lang_v1.php, keeping the two files in
 * parity. Idempotent — existing keys are never overwritten.
 *
 * Usage: php scripts/add-lang-keys.php '{"key":{"ar":"...","en":"..."}}'
 *        php scripts/add-lang-keys.php --file=path/to/keys.json [--section="Label"]
 */
$argv = $_SERVER['argv'];
$section = 'Added';
$json = null;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--file=')) {
        $json = file_get_contents(substr($arg, 7));
    } elseif (str_starts_with($arg, '--section=')) {
        $section = substr($arg, 10);
    } else {
        $json = $arg;
    }
}

if (empty($json)) {
    fwrite(STDERR, "No keys given.\n");
    exit(1);
}

$keys = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
$root = dirname(__DIR__);
$added = ['ar' => 0, 'en' => 0];

foreach (['ar', 'en'] as $locale) {
    $path = $root.'/lang/'.$locale.'/lang_v1.php';
    $source = file_get_contents($path);
    $block = '';

    foreach ($keys as $key => $translations) {
        if (str_contains($source, "'".$key."' =>")) {
            continue;
        }

        $value = str_replace("'", "\\'", (string) ($translations[$locale] ?? $translations['en'] ?? $key));
        $block .= "    '".$key."' => '".$value."',\n";
        $added[$locale]++;
    }

    if ($block === '') {
        continue;
    }

    $source = preg_replace(
        '/\];\s*$/',
        "\n    // ".$section."\n".$block."];\n",
        rtrim($source)
    );

    file_put_contents($path, $source);
}

// Fail loudly if the two files drifted — a missing Arabic string would show a
// raw key to the primary audience.
$ar = require $root.'/lang/ar/lang_v1.php';
$en = require $root.'/lang/en/lang_v1.php';

$missingAr = array_diff(array_keys($en), array_keys($ar));
$missingEn = array_diff(array_keys($ar), array_keys($en));

printf("added: ar=%d en=%d | total: ar=%d en=%d%s",
    $added['ar'], $added['en'], count($ar), count($en), PHP_EOL);

if (! empty($missingAr) || ! empty($missingEn)) {
    fwrite(STDERR, 'PARITY BROKEN — missing in ar: '.implode(', ', $missingAr)
        .' | missing in en: '.implode(', ', $missingEn).PHP_EOL);
    exit(1);
}

echo 'parity OK'.PHP_EOL;
