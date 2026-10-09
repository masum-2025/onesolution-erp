<?php

namespace App\Platform\Localization;

use App\Platform\Localization\Enums\LanguageStatus;
use App\Platform\Localization\Models\Language;
use App\Platform\Support\LocaleResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Locale;

/**
 * Every language the app speaks: the file languages that ship with the code
 * (config tenancy.supported_locales, always there) and the languages the
 * platform added in the database. Read on every request, so it comes from
 * the cache and is kept once per request (scoped).
 */
class LanguageRegistry
{
    public const CACHE_KEY = 'i18n:languages';

    /** @var array<string, array<string, mixed>>|null */
    private ?array $languages = null;

    /**
     * Languages people may use and data labels may be written in (the file
     * languages and the published database ones).
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return app(self::class)->published();
    }

    /** @return list<string> */
    public function fileCodes(): array
    {
        return array_values((array) config('tenancy.supported_locales'));
    }

    /**
     * Every language, file languages first.
     *
     * @return array<string, array{code: string, name: string, english_name: string, direction: string, fallback: string|null, source: string, status: string}>
     */
    public function all(): array
    {
        $languages = [];
        foreach ($this->fileCodes() as $code) {
            $languages[$code] = [
                'code' => $code,
                'name' => self::displayName($code, $code),
                'english_name' => self::displayName($code, 'en'),
                'direction' => LocaleResolver::direction($code),
                'fallback' => null,
                'source' => 'file',
                'status' => LanguageStatus::Published->value,
            ];
        }

        // Only the database part is kept for the request; the file part follows the config.
        foreach ($this->languages ??= $this->stored() as $code => $language) {
            $languages[$code] ??= $language;
        }

        return $languages;
    }

    /** @return array{code: string, name: string, english_name: string, direction: string, fallback: string|null, source: string, status: string}|null */
    public function get(string $code): ?array
    {
        return $this->all()[$code] ?? null;
    }

    public function isFile(string $code): bool
    {
        return in_array($code, $this->fileCodes(), true);
    }

    /**
     * Languages people may use: the file languages and the published ones.
     *
     * @return list<string>
     */
    public function published(): array
    {
        return array_values(array_keys(array_filter($this->all(), fn (array $language) => $language['status'] === LanguageStatus::Published->value)));
    }

    public function supports(?string $code): bool
    {
        return is_string($code) && in_array($code, $this->published(), true);
    }

    /** After a language is added or changed, in every process. */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->languages = null;
    }

    /** The name of a language in another language ("বাংলা", "Bengali"). */
    public static function displayName(string $code, string $in): string
    {
        $name = class_exists(Locale::class) ? (string) Locale::getDisplayName($code, $in) : '';

        return $name === '' ? $code : mb_convert_case(mb_substr($name, 0, 1), MB_CASE_UPPER).mb_substr($name, 1);
    }

    /** @return array<string, array<string, mixed>> */
    private function stored(): array
    {
        try {
            return Cache::rememberForever(self::CACHE_KEY, fn () => Language::query()->orderBy('english_name')->get()
                ->mapWithKeys(fn (Language $language) => [$language->code => [
                    'code' => $language->code,
                    'name' => $language->name,
                    'english_name' => $language->english_name,
                    'direction' => $language->direction,
                    'fallback' => $language->fallback,
                    'source' => 'db',
                    'status' => $language->status->value,
                ]])->all());
        } catch (QueryException) {
            // Before the languages table exists (a fresh install while migrating).
            return [];
        }
    }
}
