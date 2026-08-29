<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Database dumps, by hand.
 *
 * No package. `spatie/laravel-backup` is the obvious answer and it is a good
 * one, but it arrives with a dependency tree and a filesystem/notification
 * abstraction for what is, here, one `mysqldump` invocation and a `glob`. The
 * one thing this genuinely has to get right it would not do differently.
 *
 * That thing is the password. It goes into a short-lived
 * `--defaults-extra-file`, never into argv, because argv is readable by any
 * other account on the box — `ps aux` on Linux, `Get-CimInstance Win32_Process`
 * on Windows — for as long as the dump runs. A nightly `--password=` is a
 * credential disclosed nightly. That is the whole reason this class writes a
 * temp file instead of taking the one-line route.
 */
class BackupService
{
    /** @var array<string, mixed> */
    private array $connection;

    private string $binary;

    private string $directory;

    /**
     * @param  array<string, mixed>|null  $connection  overrides the configured
     *                                    connection. The tests pass a fake one
     *                                    so they can assert the argument list
     *                                    and the retention arithmetic without a
     *                                    live server or a real dump.
     */
    public function __construct(
        ?array $connection = null,
        ?string $binary = null,
        ?string $directory = null,
    ) {
        $default = (string) config('database.default');

        $this->connection = $connection ?? (array) config("database.connections.{$default}");
        $this->binary = $binary ?? (string) config('backup.mysqldump');
        $this->directory = rtrim($directory ?? (string) config('backup.directory'), '\\/');
    }

    /* ====================================================================
     | Running
     ==================================================================== */

    /**
     * Dump the database, prune what has aged out, and report both.
     *
     * @param  int|null  $keep  how many dumps to retain, config default if null
     * @return array{path: string, bytes: int, pruned: list<string>}
     *
     * @throws \RuntimeException when the dump cannot be written or mysqldump fails
     */
    public function run(?int $keep = null, ?Carbon $at = null): array
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.$this->filename($at);
        $defaults = $this->writeDefaultsFile();
        $handle = fopen($path, 'wb');
        $succeeded = false;

        if ($handle === false) {
            @unlink($defaults);

            throw new \RuntimeException("Cannot open {$path} for writing.");
        }

        try {
            $process = new Process($this->dumpArguments($defaults));

            /*
             * No timeout. A large database legitimately takes minutes, and the
             * failure mode of a timeout here is a truncated .sql file that
             * looks like a backup and is not one — strictly worse than a slow
             * night.
             */
            $process->setTimeout(null);

            $errors = '';

            // Streamed, not captured: `getOutput()` would hold the whole dump
            // in memory, which is the one size guaranteed to be too big.
            $process->run(function (string $type, string $buffer) use ($handle, &$errors) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);

                    return;
                }

                $errors .= $buffer;
            });

            if (! $process->isSuccessful()) {
                throw new \RuntimeException(
                    trim($errors) ?: 'mysqldump exited with code '.$process->getExitCode()
                );
            }

            $succeeded = true;
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }

            // The credential file goes whether the dump worked or not.
            @unlink($defaults);

            /*
             * So does a dump that did not finish. The flag is set only on the
             * success path, which covers every way out of the block above and
             * not just a non-zero exit — a missing binary makes `Process` throw
             * before it ever runs, and an unlinked-on-failure-only cleanup would
             * leave an empty .sql file sitting in the backup directory looking
             * like the night's backup. `backups()` cannot tell the difference,
             * and `prune()` would happily retain it while deleting a real one.
             */
            if (! $succeeded) {
                @unlink($path);
            }
        }

        return [
            'path' => $path,
            'bytes' => (int) filesize($path),
            'pruned' => $this->prune($keep ?? (int) config('backup.keep')),
        ];
    }

    /* ====================================================================
     | The pieces, exposed because they are what the tests can check
     ==================================================================== */

    public function directory(): string
    {
        File::ensureDirectoryExists($this->directory);

        return $this->directory;
    }

    /**
     * `souqly-2026-08-26-023015.sql`.
     *
     * Database name first, so two databases dumped into one directory stay
     * tellable apart; timestamp second in a fixed-width descending-sortable
     * format, so plain alphabetical order is also chronological order — which
     * is what {@see backups()} relies on instead of trusting mtime.
     */
    public function filename(?Carbon $at = null): string
    {
        return sprintf('%s-%s.sql', $this->databaseName(), ($at ?? Carbon::now())->format('Y-m-d-His'));
    }

    /**
     * The argv for a dump.
     *
     * `--defaults-extra-file` must come first: mysqldump only honours it in
     * first position and ignores it silently anywhere else, which would send
     * the run off to look for credentials it does not have.
     *
     * @return list<string>
     */
    public function dumpArguments(string $defaultsFile): array
    {
        $arguments = [
            $this->binary,
            '--defaults-extra-file='.$defaultsFile,
            // A consistent snapshot without locking tables the POS is still
            // writing into — the shop does not close for the backup.
            '--single-transaction',
            '--quick',
            // Avoids requiring the PROCESS privilege, which a correctly
            // least-privileged application user will not have.
            '--no-tablespaces',
            '--routines',
            '--triggers',
            '--default-character-set='.($this->connection['charset'] ?? 'utf8mb4'),
        ];

        if (! empty($this->connection['unix_socket'])) {
            $arguments[] = '--socket='.$this->connection['unix_socket'];
        }

        $arguments[] = $this->databaseName();

        return $arguments;
    }

    /**
     * A 0600 file holding the credential, deleted the moment the dump ends.
     */
    public function writeDefaultsFile(): string
    {
        $path = $this->directory().DIRECTORY_SEPARATOR.'.my-'.bin2hex(random_bytes(8)).'.cnf';

        $lines = ['[client]'];

        foreach ([
            'host' => $this->connection['host'] ?? null,
            'port' => $this->connection['port'] ?? null,
            'user' => $this->connection['username'] ?? null,
            'password' => $this->connection['password'] ?? null,
        ] as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            // my.cnf takes double-quoted values with backslash escapes; a
            // password containing either character is legal and must survive.
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value);

            $lines[] = $key.'="'.$escaped.'"';
        }

        File::put($path, implode(PHP_EOL, $lines).PHP_EOL);

        // Best effort: chmod is a no-op on Windows, where what protects the
        // file is living outside the document root and being deleted seconds later.
        @chmod($path, 0600);

        return $path;
    }

    /**
     * Existing dumps, newest first.
     *
     * @return list<string>
     */
    public function backups(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.$this->databaseName().'-*.sql') ?: [];

        // Descending by name is descending by time — see filename().
        rsort($files, SORT_STRING);

        return array_values($files);
    }

    /**
     * Delete all but the newest `$keep` dumps.
     *
     * `$keep < 1` keeps everything and deletes nothing. The other reading —
     * "keep none, so delete all" — is the one that turns a mistyped option into
     * data loss, so it is not the one implemented.
     *
     * @return list<string> the paths removed
     */
    public function prune(int $keep): array
    {
        if ($keep < 1) {
            return [];
        }

        $stale = array_slice($this->backups(), $keep);

        foreach ($stale as $path) {
            File::delete($path);
        }

        return array_values($stale);
    }

    private function databaseName(): string
    {
        $name = (string) ($this->connection['database'] ?? 'database');

        // Straight into a filename and into argv, so it is filtered rather than
        // trusted, even coming from our own config.
        return preg_replace('/[^A-Za-z0-9_.-]/', '', $name) ?: 'database';
    }
}
