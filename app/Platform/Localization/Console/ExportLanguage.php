<?php

namespace App\Platform\Localization\Console;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\TranslationCatalog;
use App\Platform\Localization\TranslationOverlay;
use App\Platform\Localization\TranslationScope;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;

/**
 * Write a language's platform texts into translation files next to the
 * English ones, so a language made in the editor can ship with the code
 * (then add its code to tenancy.supported_locales). Partner and
 * organization wording is never exported: it belongs to them.
 */
class ExportLanguage extends Command
{
    protected $signature = 'i18n:export {locale} {--dry-run : Only list the files it would write}';

    protected $description = 'Export a language from the database into translation files';

    public function handle(LanguageRegistry $languages, TranslationCatalog $catalog, TranslationOverlay $overlay): int
    {
        $locale = (string) $this->argument('locale');
        if ($languages->get($locale) === null) {
            $this->error("Unknown language: {$locale}");

            return self::FAILURE;
        }

        $written = 0;
        foreach (Channel::cases() as $channel) {
            // The platform's own wording wins over what the file had.
            $texts = array_replace(
                $languages->isFile($locale) ? $catalog->texts($channel, $locale) : [],
                $overlay->texts([TranslationScope::platform()], $channel, $locale),
            );

            foreach ($catalog->files($channel) as $file => $prefix) {
                $own = [];
                foreach ($texts as $key => $text) {
                    if (str_starts_with($key, $prefix)) {
                        $own[substr($key, strlen($prefix))] = $text;
                    }
                }
                if ($own === []) {
                    continue;
                }

                $target = $this->targetFor($file, $locale);
                $this->line(($this->option('dry-run') ? 'would write ' : 'writing ').$target.' ('.count($own).' texts)');
                if (! $this->option('dry-run')) {
                    $this->write($target, Arr::undot($own), $channel);
                }
                $written++;
            }
        }

        $this->info("{$written} files.");

        return self::SUCCESS;
    }

    /**
     * The same file in the language's folder: …/en/x.json -> …/{locale}/x.json.
     * Laravel's own messages go to the app's lang folder, never into vendor.
     */
    private function targetFor(string $file, string $locale): string
    {
        if (str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', base_path('vendor')))) {
            return lang_path($locale.DIRECTORY_SEPARATOR.basename($file));
        }
        $dir = dirname($file);

        return dirname($dir).DIRECTORY_SEPARATOR.$locale.DIRECTORY_SEPARATOR.basename($file);
    }

    /** @param array<string, mixed> $data */
    private function write(string $target, array $data, Channel $channel): void
    {
        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        $content = $channel === Channel::Ui
            ? json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            : "<?php\n\nreturn ".var_export($data, true).";\n";

        file_put_contents($target, $content);
    }
}
