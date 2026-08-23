<?php

/**
 * Model integrity check.
 *
 * Boots the framework, then for every model class:
 *   1. asserts the class loads,
 *   2. asserts its table exists,
 *   3. asserts every column referenced in $casts exists,
 *   4. instantiates every relation method and asserts the related table and
 *      the foreign/owner keys exist.
 *
 * Run with:  php scripts/verify-models.php
 */
require __DIR__.'/../vendor/autoload.php';

$app = require_once __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

$errors = [];
$checked = ['models' => 0, 'relations' => 0, 'casts' => 0];

/** Collect model classes from app/Models and app/Modules/ * /Models. */
$files = array_merge(
    glob(__DIR__.'/../app/Models/*.php') ?: [],
    glob(__DIR__.'/../app/Modules/*/Models/*.php') ?: []
);

$classes = [];
foreach ($files as $file) {
    $source = file_get_contents($file);
    if (! preg_match('/namespace\s+([^;]+);/', $source, $ns)) {
        continue;
    }
    $class = trim($ns[1]).'\\'.basename($file, '.php');
    if (class_exists($class)) {
        $classes[] = $class;
    } else {
        $errors[] = "LOAD  $class — class not found after autoload";
    }
}

sort($classes);

foreach ($classes as $class) {
    $checked['models']++;

    try {
        $model = new $class;
    } catch (Throwable $e) {
        $errors[] = "INIT  $class — {$e->getMessage()}";
        continue;
    }

    $table = $model->getTable();

    if (! Schema::hasTable($table)) {
        $errors[] = "TABLE $class — table `$table` does not exist";
        continue;
    }

    $columns = Schema::getColumnListing($table);

    // 3. Cast columns must exist.
    foreach (array_keys($model->getCasts()) as $castColumn) {
        $checked['casts']++;
        if ($castColumn === $model->getKeyName()) {
            continue;
        }
        if (! in_array($castColumn, $columns, true)) {
            $errors[] = "CAST  $class — cast column `$table`.`$castColumn` missing";
        }
    }

    // 4. Relations.
    $reflection = new ReflectionClass($class);

    foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->class !== $class
            || $method->getNumberOfParameters() > 0
            || $method->isStatic()) {
            continue;
        }

        // Skip methods pulled in from traits (e.g. spatie's dormant teams()
        // relation) — only audit relations declared in this model's own file.
        if ($method->getFileName() !== $reflection->getFileName()) {
            continue;
        }

        $returnType = $method->getReturnType();
        if (! $returnType instanceof ReflectionNamedType) {
            continue;
        }

        $typeName = $returnType->getName();
        if (! class_exists($typeName) || ! is_subclass_of($typeName, Relation::class)) {
            continue;
        }

        $checked['relations']++;
        $name = $method->getName();

        try {
            $relation = $model->{$name}();
        } catch (Throwable $e) {
            $errors[] = "REL   $class::$name() — {$e->getMessage()}";
            continue;
        }

        if ($relation instanceof MorphTo) {
            continue; // Target is dynamic.
        }

        $relatedTable = $relation->getRelated()->getTable();

        if (! Schema::hasTable($relatedTable)) {
            $errors[] = "REL   $class::$name() — related table `$relatedTable` missing";
            continue;
        }

        $relatedColumns = Schema::getColumnListing($relatedTable);

        if ($relation instanceof BelongsToMany) {
            $pivot = $relation->getTable();

            if (! Schema::hasTable($pivot)) {
                $errors[] = "REL   $class::$name() — pivot table `$pivot` missing";
                continue;
            }

            $pivotColumns = Schema::getColumnListing($pivot);

            foreach ([
                $relation->getForeignPivotKeyName(),
                $relation->getRelatedPivotKeyName(),
            ] as $pivotKey) {
                $bare = last(explode('.', $pivotKey));
                if (! in_array($bare, $pivotColumns, true)) {
                    $errors[] = "REL   $class::$name() — pivot `$pivot`.`$bare` missing";
                }
            }

            continue;
        }

        if ($relation instanceof HasOneOrMany) {
            $foreign = last(explode('.', $relation->getForeignKeyName()));
            if (! in_array($foreign, $relatedColumns, true)) {
                $errors[] = "REL   $class::$name() — `$relatedTable`.`$foreign` missing";
            }

            $local = last(explode('.', $relation->getLocalKeyName()));
            if (! in_array($local, $columns, true)) {
                $errors[] = "REL   $class::$name() — local key `$table`.`$local` missing";
            }

            continue;
        }

        // BelongsTo / MorphOne-style owner keys.
        if (method_exists($relation, 'getForeignKeyName')) {
            $foreign = last(explode('.', $relation->getForeignKeyName()));
            if (! in_array($foreign, $columns, true)) {
                $errors[] = "REL   $class::$name() — FK `$table`.`$foreign` missing";
            }
        }

        if (method_exists($relation, 'getOwnerKeyName')) {
            $owner = last(explode('.', $relation->getOwnerKeyName()));
            if (! in_array($owner, $relatedColumns, true)) {
                $errors[] = "REL   $class::$name() — owner key `$relatedTable`.`$owner` missing";
            }
        }
    }
}

echo str_repeat('=', 72).PHP_EOL;
printf(
    "Checked %d models, %d relations, %d casts%s",
    $checked['models'], $checked['relations'], $checked['casts'], PHP_EOL
);
echo str_repeat('=', 72).PHP_EOL;

if (empty($errors)) {
    echo "OK — no problems found.".PHP_EOL;
    exit(0);
}

echo count($errors).' PROBLEM(S):'.PHP_EOL.PHP_EOL;
foreach ($errors as $error) {
    echo '  '.$error.PHP_EOL;
}
exit(1);
