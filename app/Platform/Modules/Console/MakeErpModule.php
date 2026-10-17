<?php

namespace App\Platform\Modules\Console;

use App\Platform\Modules\Generator\ModuleDefinition;
use App\Platform\Modules\Generator\ModuleWriter;
use App\Platform\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;

/**
 * Makes a business module from a definition file (see
 * database/module-definitions/fleet.php): backend, screens, texts in English and
 * Bangla, and tests, following the platform's rules. Never overwrites: a
 * file that exists, even differing only in letter case (Windows), stops it
 * before anything is written. --dry-run lists what it would write.
 */
class MakeErpModule extends Command
{
    protected $signature = 'make:erp-module {definition : Path to the definition file} {--dry-run : List the files without writing them}';

    protected $description = 'Make a business module (backend, screens, texts, tests) from a definition file';

    public function handle(ModuleRegistry $registry): int
    {
        $path = $this->argument('definition');
        $path = is_file($path) ? $path : base_path($path);
        try {
            $definition = ModuleDefinition::fromFile($path, $this->icons(), $registry->keys());
            $writer = new ModuleWriter($definition);
            $files = $writer->files();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $clashes = array_values(array_filter(array_keys($files), fn (string $file) => $this->existsAnyCase(base_path($file))));
        if (is_dir(base_path("Modules/{$definition->studly()}")) || $this->existsAnyCase(base_path("Modules/{$definition->studly()}"))) {
            $clashes[] = "Modules/{$definition->studly()}";
        }
        if ($clashes !== []) {
            $this->error('These already exist (letter case ignored); nothing was written:');
            foreach (array_unique($clashes) as $clash) {
                $this->line("  {$clash}");
            }

            return self::FAILURE;
        }

        $this->info(($this->option('dry-run') ? 'Would write' : 'Writing').' '.count($files).' files for module "'.$definition->key().'":');
        foreach (array_keys($files) as $file) {
            $this->line("  {$file}");
        }
        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        foreach ($files as $file => $contents) {
            $full = base_path($file);
            if (! is_dir(dirname($full))) {
                mkdir(dirname($full), 0775, true);
            }
            file_put_contents($full, $contents);
        }
        $this->addStatus($definition->studly());
        file_put_contents(base_path('README.md'), $writer->readme(), FILE_APPEND);
        // The project's code style on what was written (imports in order, nothing unused).
        if (is_file(base_path('vendor/bin/pint'))) {
            Process::path(base_path())->run([PHP_BINARY, 'vendor/bin/pint', "Modules/{$definition->studly()}", "tests/Feature/{$definition->studly()}"]);
        }

        $this->newLine();
        $this->info('Done. Next:');
        $this->line('  composer dump-autoload');
        $this->line('  php artisan rules:sync && php artisan access:sync');
        $this->line('  php artisan migrate');
        $this->line("  php artisan test tests/Feature/{$definition->studly()}");
        $this->line('  php artisan security:endpoints --write && npm run build');

        return self::SUCCESS;
    }

    /** @return list<string> Icon names the menu knows (resources/js/lib/icons.js). */
    private function icons(): array
    {
        $source = (string) @file_get_contents(resource_path('js/lib/icons.js'));
        preg_match_all("/^\s+'?([a-z][a-z-]*)'?\s*:/m", $source, $matches);

        return array_values(array_unique($matches[1]));
    }

    /** Whether a path exists, letter case ignored (Windows treats Foo.php and foo.php as one file). */
    private function existsAnyCase(string $path): bool
    {
        if (file_exists($path)) {
            return true;
        }
        $parent = dirname($path);
        while (! is_dir($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        $relative = substr($path, strlen($parent) + 1);
        $current = $parent;
        foreach (explode(DIRECTORY_SEPARATOR, str_replace('/', DIRECTORY_SEPARATOR, $relative)) as $part) {
            $match = null;
            foreach (is_dir($current) ? (scandir($current) ?: []) : [] as $entry) {
                if (strcasecmp($entry, $part) === 0) {
                    $match = $entry;
                    break;
                }
            }
            if ($match === null) {
                return false;
            }
            $current .= DIRECTORY_SEPARATOR.$match;
        }

        return true;
    }

    /** Switched on in modules_statuses.json (nwidart's list of modules to load). */
    private function addStatus(string $studly): void
    {
        $file = base_path('modules_statuses.json');
        $statuses = json_decode((string) @file_get_contents($file), true) ?: [];
        $statuses[$studly] = true;
        file_put_contents($file, json_encode($statuses, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
