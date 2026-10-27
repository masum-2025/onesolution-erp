<?php

namespace Modules\Recruitment\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use Modules\Recruitment\Exceptions\RecruitmentException;
use Modules\Recruitment\Models\LetterTemplate;

/**
 * The offer letter of a company: its own text per language, else the
 * rule's (recruitment.offer_template, per country or partner). Plain text
 * with {placeholders}; an unknown placeholder is refused, so a typo never
 * prints as "{candidte.name}". Filled in for one offer in one language.
 */
class LetterTemplates
{
    public const PLACEHOLDERS = ['candidate.name', 'job.title', 'company.name', 'unit.name', 'salary', 'start_date', 'respond_by', 'today'];

    public function __construct(private Place $place, private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    /**
     * The company's letter per language, and whether it is its own (else the rule's).
     *
     * @return array{body: array<string, string>, own: bool, version: int}
     */
    public function of(Organization $company): array
    {
        $own = $this->place->query(LetterTemplate::class, $company)->first();
        if ($own !== null) {
            return ['body' => $own->texts('body'), 'own' => true, 'version' => $own->version];
        }

        return ['body' => array_filter((array) $this->rules->get('recruitment.offer_template', $this->contexts->forOrganization($company)), 'is_string'), 'own' => false, 'version' => 0];
    }

    /**
     * The company's own letter (English needed), with the version seen (0 when it has none yet).
     *
     * @param  array<string, string>  $body
     */
    public function save(Organization $company, array $body, int $baseVersion, User $actor): array
    {
        $body = array_filter(array_intersect_key($body, array_flip(LanguageRegistry::codes())), fn ($text) => is_string($text) && trim($text) !== '');
        $errors = [];
        foreach ($body as $locale => $text) {
            $unknown = array_diff($this->placeholdersIn($text), self::PLACEHOLDERS);
            if ($unknown !== []) {
                $errors["body.{$locale}"] = __('recruitment::recruitment.validation.placeholders', ['names' => '{'.implode('}, {', $unknown).'}']);
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $this->place->transaction($company, function () use ($company, $body, $baseVersion, $actor) {
            $own = $this->place->query(LetterTemplate::class, $company)->lockForUpdate()->first();
            if (($own?->version ?? 0) !== $baseVersion) {
                throw RecruitmentException::versionConflict(['version' => $own?->version ?? 0]);
            }
            $old = $own?->texts('body') ?? [];
            $own ??= new LetterTemplate(['organization_id' => $company->getKey(), 'version' => 0]);
            $own->putTexts('body', $body);
            $own->forceFill(['version' => $own->version + 1])->save();
            $this->audit->record('recruitment.letter_changed', $own, old: ['languages' => array_keys($old)], new: ['languages' => array_keys($body)], actor: $actor, organizationId: $company->getKey());
        });

        return $this->of($company);
    }

    /**
     * The letter in one language (the company's, else English) with the values filled in.
     *
     * @param  array<string, string>  $values  By placeholder name.
     */
    public function render(Organization $company, string $locale, array $values): string
    {
        $body = $this->of($company)['body'];
        $text = $body[$locale] ?? $body['en'] ?? (string) reset($body);

        return preg_replace_callback('/\{([a-z_]+(?:\.[a-z_]+)?)\}/', fn (array $match) => $values[$match[1]] ?? $match[0], $text) ?? $text;
    }

    /** "25 October 2026" in the language. */
    public static function date(CarbonImmutable|\DateTimeInterface $date, string $locale): string
    {
        return CarbonImmutable::parse($date->format('Y-m-d'), 'UTC')->locale($locale)->isoFormat('LL');
    }

    /** "৳25,000" / "BDT 25,000.50" in the language's digits and grouping (integer maths only). */
    public static function money(int $minor, string $currency, string $locale): string
    {
        $whole = intdiv($minor, 100);
        $cents = $minor % 100;
        $number = new \NumberFormatter($locale, \NumberFormatter::DECIMAL);
        $text = (string) $number->format($whole);
        if ($cents !== 0) {
            $text .= $number->getSymbol(\NumberFormatter::DECIMAL_SEPARATOR_SYMBOL).str_pad((string) $number->format($cents), 2, (string) $number->format(0), STR_PAD_LEFT);
        }

        return $currency.' '.$text;
    }

    /** @return list<string> */
    private function placeholdersIn(string $text): array
    {
        preg_match_all('/\{([^{}\s]{1,40})\}/', $text, $found);

        return array_values(array_unique($found[1]));
    }
}
