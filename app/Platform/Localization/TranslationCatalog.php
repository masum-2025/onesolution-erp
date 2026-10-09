<?php

namespace App\Platform\Localization;

use App\Platform\Localization\Enums\Channel;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;

/**
 * Every text key the code has, read from the translation files:
 *
 *   ui      resources/js/locales/{locale}/{ns}.json and
 *           Modules/{Module}/resources/js/locales/{locale}/{ns}.json -> "ns.a.b"
 *   server  lang/{locale}/{group}.php -> "group.a.b", a module's lang folder
 *           (its translation namespace) -> "hrm::group.a.b", and Laravel's own
 *           messages (validation, auth, …) where the app has no file of its own.
 *
 * English is the source: a key exists when it has an English text. Only
 * known keys can be overridden. Plural forms of a key with "_other" (any of
 * zero, one, two, few, many, other) count as known, since languages differ.
 *
 * Used by the editor and when saving, never on the hot path; cached per
 * deploy (file modification times).
 */
class TranslationCatalog
{
    public const SOURCE_LOCALE = 'en';

    public const PLURAL_FORMS = ['zero', 'one', 'two', 'few', 'many', 'other'];

    /** @var array<string, array<string, string>> */
    private array $memo = [];

    /**
     * key => text for a channel and file language (English by default).
     *
     * @return array<string, string>
     */
    public function texts(Channel $channel, string $locale = self::SOURCE_LOCALE): array
    {
        $memo = "{$channel->value}:{$locale}";
        if (isset($this->memo[$memo])) {
            return $this->memo[$memo];
        }

        $files = $channel === Channel::Ui ? $this->uiFiles($locale) : $this->serverFiles($locale);
        $signature = md5(implode('|', array_map(fn (string $file) => $file.'@'.(int) @filemtime($file), array_keys($files))));

        return $this->memo[$memo] = Cache::rememberForever("i18n:catalog:{$memo}:{$signature}", fn () => $this->read($channel, $files));
    }

    /**
     * The files of a channel and language: file => key prefix ("ns." in the
     * browser, "group." or "module::group." on the server).
     *
     * @return array<string, string>
     */
    public function files(Channel $channel, string $locale = self::SOURCE_LOCALE): array
    {
        $files = $channel === Channel::Ui ? $this->uiFiles($locale) : $this->serverFiles($locale);

        return $channel === Channel::Ui ? array_map(fn (string $namespace) => "{$namespace}.", $files) : $files;
    }

    /** The English text of a key, or of the plural form it belongs to. */
    public function source(Channel $channel, string $key): ?string
    {
        $texts = $this->texts($channel);
        if (isset($texts[$key])) {
            return $texts[$key];
        }

        $base = $this->pluralBase($key);

        return $base === null ? null : ($texts["{$base}_other"] ?? null);
    }

    public function has(Channel $channel, string $key): bool
    {
        return $this->source($channel, $key) !== null;
    }

    /** "items_few" -> "items" when the key is a plural form; else null. */
    public function pluralBase(string $key): ?string
    {
        $suffix = strrchr($key, '_');

        return $suffix !== false && in_array(substr($suffix, 1), self::PLURAL_FORMS, true) ? substr($key, 0, -strlen($suffix)) : null;
    }

    /** The browser namespace of a key ("rules" for "rules.drawer.title"). */
    public static function namespaceOf(string $key): string
    {
        return strtok($key, '.') ?: $key;
    }

    /**
     * @return array<string, string> file => namespace
     */
    private function uiFiles(string $locale): array
    {
        $files = [];
        foreach ([resource_path("js/locales/{$locale}/*.json"), base_path("Modules/*/resources/js/locales/{$locale}/*.json")] as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                $files[$file] = basename($file, '.json');
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @return array<string, string> file => key prefix ("group." or "module::group.")
     */
    private function serverFiles(string $locale): array
    {
        $files = [];
        foreach (glob(lang_path("{$locale}/*.php")) ?: [] as $file) {
            $files[$file] = basename($file, '.php').'.';
        }

        // Laravel's own messages, where the app has no file of the same group.
        $own = array_values($files);
        foreach (glob(base_path("vendor/laravel/framework/src/Illuminate/Translation/lang/{$locale}/*.php")) ?: [] as $file) {
            $prefix = basename($file, '.php').'.';
            if (! in_array($prefix, $own, true)) {
                $files[$file] = $prefix;
            }
        }

        foreach (app('translator')->getLoader()->namespaces() as $namespace => $path) {
            foreach (glob("{$path}/{$locale}/*.php") ?: [] as $file) {
                $files[$file] = "{$namespace}::".basename($file, '.php').'.';
            }
        }
        ksort($files);

        return $files;
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function read(Channel $channel, array $files): array
    {
        $texts = [];
        foreach ($files as $file => $prefix) {
            $data = $channel === Channel::Ui ? json_decode((string) file_get_contents($file), true) : require $file;
            if (! is_array($data)) {
                continue;
            }
            $prefix = $channel === Channel::Ui ? "{$prefix}." : $prefix;
            foreach (Arr::dot($data) as $key => $text) {
                if (is_string($text)) {
                    $texts[$prefix.$key] = $text;
                }
            }
        }
        ksort($texts);

        return $texts;
    }
}
