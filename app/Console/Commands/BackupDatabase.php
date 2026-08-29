<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

/**
 * Nightly database dump.
 *
 * Thin, like the controllers: everything that could be wrong — where the file
 * goes, how the credential is passed, what gets pruned — lives in
 * {@see BackupService}, which is what the tests can actually check without a
 * live `mysqldump` on the box.
 *
 * Deliberately not per-tenant. A dump is of the whole database; slicing it by
 * business would produce a file that cannot be restored on its own.
 */
class BackupDatabase extends Command
{
    protected $signature = 'souqly:backup
                            {--keep= : how many dumps to retain (default: config backup.keep)}';

    protected $description = 'Dump the database to storage/app/private/backups and prune old dumps';

    public function handle(BackupService $backups): int
    {
        $keep = $this->option('keep') !== null
            ? (int) $this->option('keep')
            : (int) config('backup.keep');

        try {
            $result = $backups->run($keep);
        } catch (\Throwable $e) {
            /*
             * Reported as well as printed. Nobody is watching at 02:40, so the
             * console line is not the record — the log is, and a backup that
             * failed silently is the reason people discover they have no backups
             * on the day they need one.
             */
            report($e);
            $this->components->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'Backed up to %s (%s).',
            basename($result['path']),
            $this->humanise($result['bytes'])
        ));

        foreach ($result['pruned'] as $path) {
            $this->line('  pruned '.basename($path));
        }

        /*
         * A zero-byte dump exits successfully as far as mysqldump is concerned in
         * some failure modes, so it is called out here: the file exists, which is
         * exactly what makes it dangerous.
         */
        if ($result['bytes'] === 0) {
            $this->components->warn('The dump is empty — check the credentials and the database name.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function humanise(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        return $bytes < 1048576
            ? number_format($bytes / 1024, 1).' KB'
            : number_format($bytes / 1048576, 1).' MB';
    }
}
