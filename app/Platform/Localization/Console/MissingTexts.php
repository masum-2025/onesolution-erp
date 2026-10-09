<?php

namespace App\Platform\Localization\Console;

use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\TranslationCatalog;
use App\Platform\Localization\TranslationOverlay;
use App\Platform\Localization\TranslationScope;
use Illuminate\Console\Command;

/**
 * How much of each language is translated (its file plus the platform's
 * texts), and with --list which keys are still missing.
 */
class MissingTexts extends Command
{
    protected $signature = 'i18n:missing {locale? : One language; all when left out} {--list : Print the missing keys}';

    protected $description = 'Show untranslated texts per language';

    public function handle(LanguageRegistry $languages, TranslationCatalog $catalog, TranslationOverlay $overlay): int
    {
        $codes = $this->argument('locale') === null ? array_keys($languages->all()) : [(string) $this->argument('locale')];
        $rows = [];

        foreach ($codes as $code) {
            if ($languages->get($code) === null) {
                $this->error("Unknown language: {$code}");

                return self::FAILURE;
            }

            foreach (Channel::cases() as $channel) {
                $keys = $catalog->texts($channel);
                $have = ($languages->isFile($code) ? $catalog->texts($channel, $code) : [])
                    + $overlay->texts([TranslationScope::platform()], $channel, $code);
                $missing = array_keys(array_diff_key($keys, $have));
                $total = count($keys);
                $rows[] = [$code, $channel->value, $total, count($missing), $total === 0 ? '100%' : (int) floor(($total - count($missing)) * 100 / $total).'%'];

                if ($this->option('list')) {
                    foreach ($missing as $key) {
                        $this->line("{$code}\t{$channel->value}\t{$key}");
                    }
                }
            }
        }

        $this->table(['Language', 'Channel', 'Keys', 'Missing', 'Done'], $rows);

        return self::SUCCESS;
    }
}
