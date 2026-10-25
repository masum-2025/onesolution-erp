<?php

namespace Modules\EduWebsite\Services;

use App\Models\User;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Services\OrganizationSettingsResolver;
use Illuminate\Validation\ValidationException;
use Modules\EduWebsite\Exceptions\WebsiteException;
use Modules\EduWebsite\Models\Enquiry;
use Modules\EduWebsite\Models\Site;
use Modules\Education\Directory\AcademicDirectory;

/**
 * Admission enquiries from the public website. The form carries a signed
 * token (it works on kept pages, and only this site's form is taken);
 * a form sent too fast or with the hidden field filled is a robot. A
 * number may ask a few times a day (edu_website.enquiry_per_phone_day).
 * The people who handle enquiries are told (without names or numbers in
 * the mail) when the rule edu_website.enquiry_notify_email says so.
 */
class Enquiries
{
    /** People need at least this long to fill the form. */
    public const MIN_SECONDS = 3;

    /** A form is taken for this long after its page was made. */
    public const TOKEN_HOURS = 24;

    public function __construct(
        private Place $place,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private ModuleResolver $modules,
        private Notifier $notifier,
        private Recipients $recipients,
        private OrganizationSettingsResolver $settings,
    ) {}

    /** "{time}.{signature}" for this site's form. */
    public function token(Site $site, ?int $at = null): string
    {
        $at ??= now()->getTimestamp();

        return $at.'.'.hash_hmac('sha256', "edu_website.enquiry|{$site->getKey()}|{$at}", (string) config('app.key'));
    }

    /** ok, fast (a robot), or bad (not this site's form, or too old). */
    public function check(Site $site, ?string $token): string
    {
        if (! is_string($token) || preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/', $token, $match) !== 1
            || ! hash_equals($this->token($site, (int) $match[1]), $token)) {
            return 'bad';
        }
        $age = now()->getTimestamp() - (int) $match[1];
        if ($age > self::TOKEN_HOURS * 3600 || $age < -60) {
            return 'bad';
        }

        return $age < self::MIN_SECONDS ? 'fast' : 'ok';
    }

    /**
     * Classes one may ask for (Education's, when it is on).
     *
     * @return list<array{id: string, name: array<string, string>}>
     */
    public function levels(Organization $company): array
    {
        if (! class_exists(AcademicDirectory::class) || ! $this->modules->isEnabled('education', $company)) {
            return [];
        }

        return array_map(fn (array $level) => ['id' => $level['id'], 'name' => $level['name']], app(AcademicDirectory::class)->levels($company));
    }

    /**
     * @param  array{student_name: string, guardian_name: string, phone: string, level_id?: string|null, message?: string|null}  $data
     */
    public function receive(Organization $company, Site $site, array $data, string $locale): Enquiry
    {
        $phone = $this->mobile($company, $data['phone']);
        if ($phone === null) {
            throw ValidationException::withMessages(['phone' => __('edu_website::public.enquiry_bad_phone', [], $locale)]);
        }
        $level = null;
        if (! empty($data['level_id'])) {
            $level = collect($this->levels($company))->firstWhere('id', $data['level_id'])
                ?? throw ValidationException::withMessages(['level_id' => __('edu_website::public.enquiry_bad_level', [], $locale)]);
        }
        $hash = hash_hmac('sha256', 'edu_website.phone|'.$phone, (string) config('app.key'));
        $perDay = (int) $this->rules->get('edu_website.enquiry_per_phone_day', $this->contexts->forOrganization($company));

        $enquiry = $this->place->transaction($company, function () use ($company, $site, $data, $locale, $phone, $hash, $level, $perDay) {
            $today = $this->place->query(Enquiry::class, $company)->where('phone_hash', $hash)->where('created_at', '>=', now()->subDay())->lockForUpdate()->count();
            if ($today >= $perDay) {
                throw WebsiteException::tooManyEnquiries();
            }
            $enquiry = new Enquiry;
            $enquiry->fill([
                'organization_id' => $company->getKey(), 'site_id' => $site->getKey(),
                'student_name' => trim($data['student_name']), 'guardian_name' => trim($data['guardian_name']),
                'phone' => $phone, 'phone_hash' => $hash, 'level_id' => $level['id'] ?? null, 'level_name' => $level['name'] ?? null,
                'message' => trim((string) ($data['message'] ?? '')) ?: null, 'locale' => $locale, 'status' => 'new', 'version' => 1,
            ])->save();

            return $enquiry;
        });
        $this->tell($company, $enquiry);

        return $enquiry;
    }

    /**
     * The office's handling: its status and note, with the version seen.
     *
     * @param  array{status?: string, note?: string|null, base_version: int}  $data
     */
    public function update(Organization $company, Enquiry $enquiry, array $data): Enquiry
    {
        return $this->place->transaction($company, function () use ($company, $enquiry, $data) {
            /** @var Enquiry $fresh */
            $fresh = $this->place->query(Enquiry::class, $company)->whereKey($enquiry->getKey())->lockForUpdate()->firstOrFail();
            if ($fresh->version !== (int) $data['base_version']) {
                throw WebsiteException::versionConflict(['version' => $fresh->version]);
            }
            if (isset($data['status'])) {
                $fresh->status = $data['status'];
            }
            if (array_key_exists('note', $data)) {
                $fresh->note = trim((string) $data['note']) ?: null;
            }
            $fresh->forceFill(['version' => $fresh->version + 1])->save();

            return $fresh;
        });
    }

    /** Enquiries older than the company's rule are removed (the people asked; their details go). */
    public function prune(Organization $company): int
    {
        $days = (int) $this->rules->get('edu_website.enquiry_retention_days', $this->contexts->forOrganization($company));

        return $this->place->query(Enquiry::class, $company)->where('created_at', '<', now()->subDays(max(1, $days)))->delete();
    }

    /** A mobile number of the company's country, its own or from a level above (E.164); any 6 to 15 digits when none is known. */
    private function mobile(Organization $company, string $input): ?string
    {
        // Bangla digits are digits too (০১৭১১…).
        $input = strtr($input, array_combine(['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'], range(0, 9)));
        $country = $this->settings->values($company)['country_code'] ?? null;
        if (is_string($country) && $country !== '') {
            return PhoneNumber::normalize($input, $country);
        }
        $digits = preg_replace('/[\s\-()]/', '', $input);

        return preg_match('/^\+?[0-9]{6,15}$/', $digits) === 1 ? $digits : null;
    }

    private function tell(Organization $company, Enquiry $enquiry): void
    {
        if (! $this->rules->get('edu_website.enquiry_notify_email', $this->contexts->forOrganization($company))) {
            return;
        }
        /** @var iterable<User> $to */
        $to = $this->recipients->holding('edu_website.enquiries', $company);
        $this->notifier->notify(
            'edu_website.enquiry_received',
            $to,
            fn (string $locale) => [
                'organization' => $company->displayName($locale),
                // The class only: names and numbers stay in the app.
                'level' => $enquiry->level_name[$locale] ?? $enquiry->level_name['en'] ?? collect($enquiry->level_name ?? [])->first() ?? '—',
            ],
            $company->partner,
            $company,
        );
    }
}
