<?php

namespace App\Platform\Security\Console;

use App\Platform\Security\Backups\BackupCipher;
use App\Platform\Security\Backups\BackupException;
use App\Platform\Security\Backups\BackupService;
use Illuminate\Console\Command;

class BackupRun extends Command
{
    protected $signature = 'backup:run
        {--no-prune : Keep old sets this time}
        {--generate-key : Print a new backup key and stop}';

    protected $description = 'Write an encrypted backup set (database + private files) and remove old sets';

    public function handle(BackupService $backups): int
    {
        if ($this->option('generate-key')) {
            $this->line(BackupCipher::generateKey());
            $this->comment('Put it in BACKUP_KEY_V<n> and keep a copy offline (a password manager or a safe). Without it the backups cannot be read.');

            return self::SUCCESS;
        }

        try {
            $manifest = $backups->run();
            $removed = $this->option('no-prune') ? [] : $backups->prune();
        } catch (BackupException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup set {$manifest['id']} written (key {$manifest['key_version']}): "
            .count($manifest['tables']).' tables, '.($manifest['files']['count'] ?? 0).' files.');

        if ($removed !== []) {
            $this->line('Removed old sets: '.implode(', ', $removed));
        }

        return self::SUCCESS;
    }
}
