<?php

namespace App\Platform\Localization\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\Enums\LanguageStatus;
use App\Platform\Localization\Exceptions\LocalizationException;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\Models\Language;
use App\Platform\Localization\Models\TranslationOverride;
use App\Platform\Localization\OverrideStore;
use App\Platform\Localization\TranslationCatalog;
use App\Platform\Localization\TranslationOverlay;
use App\Platform\Localization\TranslationScope;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Support\LocaleResolver;
use Illuminate\Support\Facades\DB;

/**
 * Languages the platform adds without a deploy. A new language starts as a
 * draft (only its editors see it), is published once enough of it is
 * translated (rule i18n.publish_min_percent), and can be switched off later
 * with its texts kept. Only a draft can be removed.
 */
class LanguageService
{
    public const CODE_PATTERN = '/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})*$/';

    public function __construct(
        private LanguageRegistry $languages,
        private TranslationCatalog $catalog,
        private TranslationOverlay $overlay,
        private OverrideStore $store,
        private RuleResolver $rules,
        private RuleContextFactory $ruleContexts,
        private AuditLogger $audit,
    ) {}

    /**
     * @param  array{code: string, name?: string|null, english_name?: string|null, direction?: string|null, fallback?: string|null}  $data
     */
    public function create(array $data, User $actor): Language
    {
        $code = (string) $data['code'];
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw LocalizationException::badCode();
        }
        if ($this->languages->get($code) !== null) {
            throw LocalizationException::languageExists();
        }

        $fallback = $data['fallback'] ?? $this->languages->fileCodes()[0];
        if (! $this->languages->isFile($fallback)) {
            throw LocalizationException::unknownLanguage();
        }

        return DB::transaction(function () use ($code, $data, $fallback, $actor) {
            $language = new Language;
            $language->forceFill([
                'code' => $code,
                'name' => trim((string) ($data['name'] ?? '')) ?: LanguageRegistry::displayName($code, $code),
                'english_name' => trim((string) ($data['english_name'] ?? '')) ?: LanguageRegistry::displayName($code, 'en'),
                'direction' => $data['direction'] ?? LocaleResolver::direction($code),
                'fallback' => $fallback,
                'status' => LanguageStatus::Draft,
                'created_by' => $actor->getKey(),
            ])->save();

            $this->audit->record(action: 'i18n.language_added', target: $language, new: $this->auditValues($language), actor: $actor);
            DB::afterCommit(fn () => $this->languages->forget());

            return $language;
        });
    }

    /**
     * @param  array{name?: string, english_name?: string, direction?: string, fallback?: string, status?: string}  $data
     */
    public function update(Language $language, array $data, User $actor): Language
    {
        if (isset($data['fallback']) && ! $this->languages->isFile($data['fallback'])) {
            throw LocalizationException::unknownLanguage();
        }

        $status = isset($data['status']) ? LanguageStatus::from($data['status']) : $language->status;
        if ($status === LanguageStatus::Published && $language->status !== LanguageStatus::Published) {
            $percent = $this->completion($language->code);
            $needed = (int) $this->rules->get('i18n.publish_min_percent', $this->ruleContexts->platform());
            if ($percent < $needed) {
                throw LocalizationException::belowMinimum($percent, $needed);
            }
        }

        return DB::transaction(function () use ($language, $data, $status, $actor) {
            $old = $this->auditValues($language);
            $language->forceFill([
                ...array_intersect_key($data, array_flip(['name', 'english_name', 'direction', 'fallback'])),
                'status' => $status,
                'published_at' => $status === LanguageStatus::Published ? ($language->published_at ?? now()) : $language->published_at,
            ])->save();

            $this->audit->record(action: 'i18n.language_changed', target: $language, old: $old, new: $this->auditValues($language), actor: $actor);
            DB::afterCommit(fn () => $this->languages->forget());

            return $language;
        });
    }

    public function remove(Language $language, User $actor): void
    {
        if ($language->status !== LanguageStatus::Draft) {
            throw LocalizationException::onlyDraftRemovable();
        }

        DB::transaction(function () use ($language, $actor) {
            // A draft is worded at the platform only; any level found is moved on all the same.
            $scopes = TranslationOverride::query()->where('locale', $language->code)->distinct()->get(['scope_type', 'scope_id']);
            TranslationOverride::query()->where('locale', $language->code)->delete();
            foreach ($scopes as $scope) {
                $this->store->bump(TranslationScope::fromStored($scope->scope_type, $scope->scope_id));
            }

            $this->audit->record(action: 'i18n.language_removed', target: $language, old: $this->auditValues($language), actor: $actor);
            $language->delete();
            DB::afterCommit(fn () => $this->languages->forget());
        });
    }

    /**
     * How much of a language is translated (0–100): keys with a text in its
     * file or at the platform, out of every key the app has.
     */
    public function completion(string $locale): int
    {
        $total = 0;
        $done = 0;
        foreach (Channel::cases() as $channel) {
            $keys = $this->catalog->texts($channel);
            $have = $this->languages->isFile($locale) ? $this->catalog->texts($channel, $locale) : [];
            $have += $this->overlay->texts([TranslationScope::platform()], $channel, $locale);
            $total += count($keys);
            $done += count(array_intersect_key($keys, $have));
        }

        return $total === 0 ? 100 : (int) floor($done * 100 / $total);
    }

    /** @return array<string, mixed> */
    private function auditValues(Language $language): array
    {
        return [
            'code' => $language->code,
            'name' => $language->name,
            'english_name' => $language->english_name,
            'direction' => $language->direction,
            'fallback' => $language->fallback,
            'status' => $language->status->value,
        ];
    }
}
