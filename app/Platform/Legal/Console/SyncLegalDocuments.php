<?php

namespace App\Platform\Legal\Console;

use App\Platform\Legal\Services\LegalService;
use Illuminate\Console\Command;

/**
 * Publishes the platform's legal documents from
 * database/seeders/data/legal-documents.php where none exists yet (safe on
 * every deploy). Later versions are published from the house partner's
 * console; --force publishes the file's text as a new version anyway.
 */
class SyncLegalDocuments extends Command
{
    protected $signature = 'legal:sync {--force : Publish the file\'s text where it differs from the latest platform version}';

    protected $description = 'Publish the platform\'s default legal documents from the data file';

    public function handle(LegalService $legal): int
    {
        $published = $legal->syncPlatform(require database_path('seeders/data/legal-documents.php'), (bool) $this->option('force'));
        $this->info("{$published} platform documents published.");

        return self::SUCCESS;
    }
}
