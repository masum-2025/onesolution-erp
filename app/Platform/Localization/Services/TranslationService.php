<?php

namespace App\Platform\Localization\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Localization\Enums\Channel;
use App\Platform\Localization\Exceptions\LocalizationException;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Localization\Models\TranslationOverride;
use App\Platform\Localization\OverrideStore;
use App\Platform\Localization\TranslationCatalog;
use App\Platform\Localization\TranslationScope;
use Illuminate\Support\Facades\DB;

/**
 * Wording a level gives a text, in one language. Only keys the app has can
 * be worded; texts are plain (no markup) and use only the placeholders of
 * the original. Every change is audited and moves the level's version on.
 *
 * Who may change which level is checked by the caller (controllers).
 */
class TranslationService
{
    public const MAX_LENGTH = 1000;

    public const MAX_IMPORT = 5000;

    public function __construct(
        private TranslationCatalog $catalog,
        private LanguageRegistry $languages,
        private OverrideStore $store,
        private AuditLogger $audit,
    ) {}

    /**
     * Whether a level may word texts in a language: the platform in any of its
     * languages (drafts too, that is how they are translated); partners and
     * organizations in the ones offered to people.
     */
    public function assertLanguage(TranslationScope $scope, string $locale): void
    {
        $language = $this->languages->get($locale) ?? throw LocalizationException::unknownLanguage();

        if (! $scope->isPlatform() && $language['status'] !== 'published') {
            throw LocalizationException::languageNotOffered();
        }
    }

    /** Normalised text, or an exception naming what is wrong with it. */
    public function check(Channel $channel, string $key, string $value): string
    {
        $source = $this->catalog->source($channel, $key) ?? throw LocalizationException::unknownKey($key);
        $value = trim(str_replace("\r\n", "\n", $value));

        // Shown escaped everywhere; still, no tags at all keeps it plain text.
        if (preg_match('/<\s*[A-Za-z\/!?]/', $value) === 1) {
            throw LocalizationException::markup($key);
        }

        $unknown = array_values(array_diff($channel->placeholders($value), $channel->placeholders($source)));
        if ($unknown !== []) {
            throw LocalizationException::unknownPlaceholders($key, $unknown);
        }

        return $value;
    }

    public function save(TranslationScope $scope, Channel $channel, string $locale, string $key, string $value, User $actor, ?string $organizationId = null, ?string $partnerId = null): TranslationOverride
    {
        $this->assertLanguage($scope, $locale);
        $value = $this->check($channel, $key, $value);

        return DB::transaction(function () use ($scope, $channel, $locale, $key, $value, $actor, $organizationId, $partnerId) {
            $row = $this->row($scope, $channel, $locale, $key)->lockForUpdate()->first() ?? new TranslationOverride;
            $old = $row->exists ? $row->value : null;

            $row->forceFill([
                'scope_type' => $scope->type,
                'scope_id' => $scope->id,
                'locale' => $locale,
                'channel' => $channel,
                'key' => $key,
                'value' => $value,
                'version' => ($row->version ?? 0) + 1,
                'updated_by' => $actor->getKey(),
            ])->save();

            $this->store->bump($scope);
            $this->audit->record(
                action: 'i18n.text_saved',
                target: $row,
                old: $old === null ? [] : ['value' => $old],
                new: ['level' => $scope->type, 'locale' => $locale, 'channel' => $channel->value, 'key' => $key, 'value' => $value],
                actor: $actor,
                organizationId: $organizationId,
                partnerId: $partnerId,
            );

            return $row;
        });
    }

    /** Back to what the levels above (or the file) say. */
    public function reset(TranslationScope $scope, Channel $channel, string $locale, string $key, User $actor, ?string $organizationId = null, ?string $partnerId = null): void
    {
        DB::transaction(function () use ($scope, $channel, $locale, $key, $actor, $organizationId, $partnerId) {
            $row = $this->row($scope, $channel, $locale, $key)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }

            $this->audit->record(
                action: 'i18n.text_reset',
                target: $row,
                old: ['level' => $scope->type, 'locale' => $locale, 'channel' => $channel->value, 'key' => $key, 'value' => $row->value],
                actor: $actor,
                organizationId: $organizationId,
                partnerId: $partnerId,
            );
            $row->delete();
            $this->store->bump($scope);
        });
    }

    /**
     * Many texts at once (a translator's file). All are checked first: one
     * bad text and nothing is saved, the error naming its key. Empty texts
     * are passed over. Returns how many were saved.
     *
     * @param  array<string, string>  $texts  key => text
     */
    public function import(TranslationScope $scope, Channel $channel, string $locale, array $texts, User $actor, ?string $organizationId = null, ?string $partnerId = null): int
    {
        $this->assertLanguage($scope, $locale);

        $clean = [];
        foreach ($texts as $key => $value) {
            if (trim((string) $value) === '') {
                continue;
            }
            $clean[(string) $key] = $this->check($channel, (string) $key, (string) $value);
        }

        if ($clean === []) {
            return 0;
        }

        return DB::transaction(function () use ($scope, $channel, $locale, $clean, $actor, $organizationId, $partnerId) {
            $existing = TranslationOverride::query()
                ->where('scope_type', $scope->type)
                ->where('scope_id', $scope->id)
                ->where('channel', $channel->value)
                ->where('locale', $locale)
                ->whereIn('key', array_keys($clean))
                ->lockForUpdate()
                ->get()
                ->keyBy('key');

            $changed = 0;
            foreach ($clean as $key => $value) {
                $row = $existing[$key] ?? new TranslationOverride;
                if ($row->exists && $row->value === $value) {
                    continue;
                }
                $row->forceFill([
                    'scope_type' => $scope->type,
                    'scope_id' => $scope->id,
                    'locale' => $locale,
                    'channel' => $channel,
                    'key' => $key,
                    'value' => $value,
                    'version' => ($row->version ?? 0) + 1,
                    'updated_by' => $actor->getKey(),
                ])->save();
                $changed++;
            }

            if ($changed > 0) {
                $this->store->bump($scope);
                // One entry for the file: the texts themselves are in the table, each with its version.
                $this->audit->record(
                    action: 'i18n.texts_imported',
                    new: ['level' => $scope->type, 'locale' => $locale, 'channel' => $channel->value, 'count' => $changed],
                    actor: $actor,
                    organizationId: $organizationId,
                    partnerId: $partnerId,
                );
            }

            return $changed;
        });
    }

    /**
     * A level's own texts in a language (key => text).
     *
     * @return array<string, string>
     */
    public function own(TranslationScope $scope, Channel $channel, string $locale): array
    {
        return TranslationOverride::query()
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->where('channel', $channel->value)
            ->where('locale', $locale)
            ->orderBy('key')
            ->pluck('value', 'key')
            ->all();
    }

    private function row(TranslationScope $scope, Channel $channel, string $locale, string $key)
    {
        return TranslationOverride::query()
            ->where('scope_type', $scope->type)
            ->where('scope_id', $scope->id)
            ->where('channel', $channel->value)
            ->where('locale', $locale)
            ->where('key', $key);
    }
}
