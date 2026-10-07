<?php

namespace Modules\Crm\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Support\PhoneNumber;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Validation\ValidationException;
use Modules\Crm\Exceptions\CrmException;
use Modules\Crm\Models\Contact;

/**
 * Contacts: people and organizations a company sells to or might.
 *
 * - The phone is kept in E.164 (read by the company's country) and is unique
 *   in the company; with rule crm.duplicate_match "phone_email" the email is
 *   too. A duplicate is refused with the contact it matches.
 * - Consent to marketing (SMS, email) is kept with its time; turning it off
 *   keeps the time it changed.
 * - A contact is never deleted: on a person's request it is made anonymous
 *   (name, phone, email, address and extra fields cleared); deals, quotes
 *   and sales keep their amounts.
 * - An op id makes creating safe to retry (offline, imports).
 */
class Contacts
{
    /** Most rows one import reads. */
    public const MAX_IMPORT = 2000;

    /** Details people may set directly. */
    private const FIELDS = ['kind', 'name', 'company_name', 'email', 'address', 'tags', 'source', 'owner_id', 'is_active'];

    public function __construct(
        private Crm $crm,
        private Fields $fields,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    /** A typed number as the company keeps it (E.164), or null when it is not a phone number. */
    public function phone(Organization $company, ?string $typed): ?string
    {
        $typed = trim((string) $typed);

        return $typed === '' ? null : PhoneNumber::normalize($typed, $this->crm->country($company));
    }

    public function findByPhone(Organization $company, ?string $typed): ?Contact
    {
        $phone = $this->phone($company, $typed);

        return $phone === null ? null : $this->crm->query(Contact::class, $company)->where('phone', $phone)->whereNull('anonymized_at')->first();
    }

    /**
     * @param  array<string, mixed>  $data  Validated by ContactRequest.
     * @param  bool  $requireFields  Required extra fields must be filled (not when a counter adds a customer in passing).
     */
    public function create(Organization $company, string $unitId, array $data, ?User $actor, bool $requireFields = true): Contact
    {
        if (! empty($data['op_id'])) {
            $existing = $this->crm->query(Contact::class, $company)->where('op_id', $data['op_id'])->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $phone = $this->checkedPhone($company, $data, null);
        $extra = $this->fields->apply($company, 'contact', (array) ($data['extra'] ?? []), [], $requireFields);

        return $this->crm->transaction($company, function () use ($company, $unitId, $data, $actor, $phone, $extra) {
            $contact = new Contact;
            $contact->fill([
                ...array_intersect_key($data, array_flip(self::FIELDS)),
                'organization_id' => $company->getKey(), 'unit_id' => $unitId, 'phone' => $phone, 'email' => self::email($data['email'] ?? null),
                'tags' => self::tags($data['tags'] ?? []), 'extra' => $extra ?: null, 'created_by' => $actor?->getKey(), 'op_id' => $data['op_id'] ?? null, 'version' => 1,
            ]);
            $contact->kind ??= 'person';
            $this->consent($contact, $data);
            $contact->save();
            $this->audit->record('crm.contact_created', $contact, new: $this->auditValues($contact), actor: $actor, organizationId: $company->getKey());

            return $contact;
        });
    }

    /**
     * @param  array<string, mixed>  $data  Only the fields to change.
     */
    public function update(Organization $company, Contact $contact, int $baseVersion, array $data, User $actor): Contact
    {
        return $this->crm->transaction($company, function () use ($company, $contact, $baseVersion, $data, $actor) {
            $contact = $this->locked($company, $contact, $baseVersion);
            $old = $this->auditValues($contact);
            $contact->fill(array_intersect_key($data, array_flip(self::FIELDS)));
            if (array_key_exists('phone', $data)) {
                $contact->phone = $this->checkedPhone($company, $data, $contact);
            } elseif (array_key_exists('email', $data)) {
                $this->checkedPhone($company, ['phone' => $contact->phone, 'email' => $data['email']], $contact);
            }
            if (array_key_exists('email', $data)) {
                $contact->email = self::email($data['email']);
            }
            if (array_key_exists('tags', $data)) {
                $contact->tags = self::tags($data['tags'] ?? []);
            }
            if (array_key_exists('extra', $data)) {
                $contact->extra = $this->fields->apply($company, 'contact', (array) $data['extra'], (array) ($contact->extra ?? []), false) ?: null;
            }
            $this->consent($contact, $data);
            $contact->version++;
            $contact->save();
            $this->audit->record('crm.contact_updated', $contact, old: $old, new: $this->auditValues($contact), actor: $actor, organizationId: $company->getKey());

            return $contact;
        });
    }

    /** On the person's request: their details cleared for good; amounts and history stay. */
    public function anonymize(Organization $company, Contact $contact, int $baseVersion, string $reason, User $actor): Contact
    {
        return $this->crm->transaction($company, function () use ($company, $contact, $baseVersion, $reason, $actor) {
            $contact = $this->locked($company, $contact, $baseVersion);
            $contact->forceFill([
                'name' => __('crm::crm.anonymous'), 'company_name' => null, 'phone' => null, 'email' => null, 'address' => null, 'tags' => null,
                'extra' => null, 'sms_consent' => false, 'email_consent' => false, 'is_active' => false, 'anonymized_at' => now(), 'version' => $contact->version + 1,
            ])->save();
            // The audit keeps that it happened and why, never the details removed.
            $this->audit->record('crm.contact_anonymized', $contact, new: ['reason' => $reason], actor: $actor, organizationId: $company->getKey());

            return $contact;
        });
    }

    /**
     * Rows read from a CSV (name, phone, email, company, tags, source, then
     * extra fields by key): each checked; with $commit the good ones are
     * made. Duplicates (in the file or already here) are skipped.
     *
     * @param  list<array<string, string>>  $rows
     * @return array{rows: list<array{line: int, status: string, errors: array<string, string>, contact_id: string|null}>, ok: int, duplicates: int, errors: int, made: int}
     */
    public function import(Organization $company, string $unitId, array $rows, bool $commit, User $actor): array
    {
        $result = ['rows' => [], 'ok' => 0, 'duplicates' => 0, 'errors' => 0, 'made' => 0];
        $seen = [];
        $known = array_flip(['name', 'phone', 'email', 'company_name', 'tags', 'source']);
        foreach (array_slice($rows, 0, self::MAX_IMPORT) as $index => $row) {
            $line = $index + 2;
            $data = [
                'name' => trim((string) ($row['name'] ?? '')), 'phone' => $row['phone'] ?? null, 'email' => $row['email'] ?? null,
                'company_name' => ($row['company_name'] ?? '') ?: null, 'source' => ($row['source'] ?? '') ?: 'import',
                'tags' => array_values(array_filter(array_map('trim', explode(';', (string) ($row['tags'] ?? ''))))),
                'extra' => array_filter(array_diff_key($row, $known), fn ($value) => $value !== '' && $value !== null),
            ];
            $errors = [];
            if ($data['name'] === '' || mb_strlen($data['name']) > 150) {
                $errors['name'] = __('crm::crm.validation.name');
            }
            $phone = $this->phone($company, $data['phone']);
            if (trim((string) $data['phone']) !== '' && $phone === null) {
                $errors['phone'] = __('crm::crm.validation.phone');
            }
            if ($data['email'] !== null && trim($data['email']) !== '' && self::email($data['email']) === null) {
                $errors['email'] = __('crm::crm.validation.email');
            }
            $errors += $this->fields->check($company, 'contact', $data['extra'], [], true)['errors'];
            $duplicate = $phone !== null && (isset($seen[$phone]) || $this->crm->query(Contact::class, $company)->where('phone', $phone)->exists());
            $status = $errors !== [] ? 'error' : ($duplicate ? 'duplicate' : 'ok');
            $contactId = null;
            if ($status === 'ok' && $commit) {
                $contactId = $this->create($company, $unitId, $data, $actor)->getKey();
                $result['made']++;
            }
            if ($phone !== null) {
                $seen[$phone] = true;
            }
            $result[$status === 'ok' ? 'ok' : ($status === 'duplicate' ? 'duplicates' : 'errors')]++;
            $result['rows'][] = ['line' => $line, 'status' => $status, 'errors' => $errors, 'contact_id' => $contactId];
        }
        if ($commit) {
            $this->audit->record('crm.contacts_imported', null, new: ['made' => $result['made'], 'duplicates' => $result['duplicates'], 'errors' => $result['errors']], actor: $actor, organizationId: $company->getKey());
        }

        return $result;
    }

    /** A sale or return (from POS) adds to or takes from what the contact spent. */
    public function recordPurchase(Organization $company, string $contactId, int $signedAmount, string $on): void
    {
        $this->crm->transaction($company, function () use ($company, $contactId, $signedAmount, $on) {
            $contact = $this->crm->query(Contact::class, $company)->whereKey($contactId)->lockForUpdate()->first();
            if ($contact === null) {
                return;
            }
            $contact->forceFill([
                'spent_minor' => max(0, $contact->spent_minor + $signedAmount),
                'purchases' => $contact->purchases + ($signedAmount > 0 ? 1 : 0),
                'last_purchase_on' => $signedAmount > 0 && ($contact->last_purchase_on === null || $contact->last_purchase_on->toDateString() < $on) ? $on : $contact->last_purchase_on,
            ])->save();
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function checkedPhone(Organization $company, array $data, ?Contact $current): ?string
    {
        $typed = $data['phone'] ?? null;
        $phone = $this->phone($company, $typed);
        if (trim((string) $typed) !== '' && $phone === null) {
            throw ValidationException::withMessages(['phone' => __('crm::crm.validation.phone')]);
        }
        $match = (string) $this->rules->get('crm.duplicate_match', $this->contexts->forOrganization($company));
        $email = self::email($data['email'] ?? null);
        $others = $this->crm->query(Contact::class, $company)->whereNull('anonymized_at')
            ->when($current !== null, fn ($query) => $query->whereKeyNot($current->getKey()));
        if ($phone !== null && ($found = (clone $others)->where('phone', $phone)->first()) !== null) {
            throw ValidationException::withMessages(['phone' => __('crm::crm.validation.phone_taken', ['name' => $found->name])]);
        }
        if ($match === 'phone_email' && $email !== null && ($found = (clone $others)->where('email', $email)->first()) !== null) {
            throw ValidationException::withMessages(['email' => __('crm::crm.validation.email_taken', ['name' => $found->name])]);
        }

        return $phone;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function consent(Contact $contact, array $data): void
    {
        foreach (['sms_consent', 'email_consent'] as $key) {
            if (array_key_exists($key, $data) && (bool) $data[$key] !== (bool) $contact->{$key}) {
                $contact->{$key} = (bool) $data[$key];
                $contact->consent_at = now();
            }
        }
    }

    private function locked(Organization $company, Contact $contact, int $baseVersion): Contact
    {
        /** @var Contact $fresh */
        $fresh = $this->crm->query(Contact::class, $company)->whereKey($contact->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->anonymized_at !== null) {
            throw CrmException::anonymized();
        }
        if ($fresh->version !== $baseVersion) {
            throw CrmException::versionConflict(['version' => $fresh->version]);
        }

        return $fresh;
    }

    private static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    /**
     * @param  list<string>|null  $tags
     * @return list<string>|null
     */
    private static function tags(?array $tags): ?array
    {
        $clean = array_values(array_unique(array_filter(array_map(fn ($tag) => mb_substr(trim((string) $tag), 0, 30), $tags ?? []))));

        return $clean === [] ? null : array_slice($clean, 0, 20);
    }

    /**
     * What the audit keeps of a contact: never the phone or email, only that they are there.
     *
     * @return array<string, mixed>
     */
    private function auditValues(Contact $contact): array
    {
        return [...$contact->only(['kind', 'unit_id', 'source', 'owner_id', 'sms_consent', 'email_consent', 'is_active']), 'has_phone' => $contact->phone !== null, 'has_email' => $contact->email !== null];
    }
}
