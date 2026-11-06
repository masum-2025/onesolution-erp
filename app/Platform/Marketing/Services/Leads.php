<?php

namespace App\Platform\Marketing\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Marketing\Events\LeadReceived;
use App\Platform\Marketing\Exceptions\MarketingException;
use App\Platform\Marketing\Models\Lead;
use App\Platform\Marketing\Models\Site;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Messages from a company website's contact form. A visitor's message is
 * kept only when it passes the form's checks: a hidden field people never
 * fill (bots do), a signed time stamp from the page (not sent within a few
 * seconds of opening it, nor days later), and a number per address an hour
 * (PublicWebsiteController). The same phone again within minutes is the
 * same message. Staff follow up (status, note); owners remove one.
 */
class Leads
{
    /** The hidden field a person never sees. */
    public const TRAP = 'website';

    private const MIN_SECONDS = 3;

    private const MAX_SECONDS = 172800;

    private const SAME_MINUTES = 10;

    public function __construct(private AuditLogger $audit) {}

    /** The stamp a form carries: when the page was drawn, signed for this site. */
    public static function stamp(Site $site, ?int $at = null): string
    {
        $at ??= time();

        return $at.'.'.hash_hmac('sha256', "mkt-lead|{$site->getKey()}|{$at}", (string) config('app.key'));
    }

    public static function stampValid(Site $site, string $stamp, ?int $now = null): bool
    {
        [$at] = explode('.', $stamp, 2) + [''];
        if (! ctype_digit($at) || ! hash_equals(self::stamp($site, (int) $at), $stamp)) {
            return false;
        }
        $age = ($now ?? time()) - (int) $at;

        return $age >= self::MIN_SECONDS && $age <= self::MAX_SECONDS;
    }

    /**
     * The visitor's answers checked against what the form asks.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, mixed>  $form  The block's data (ask_email, ask_company, ask_message).
     * @return array{0: array<string, string>, 1: array<string, mixed>} Errors (field => message), clean values.
     */
    public function check(array $input, array $form, string $locale): array
    {
        $message = fn (string $key) => __("marketing.public.form.errors.{$key}", [], $locale);
        // What the form does not ask is not taken.
        $rules = array_filter([
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'regex:/^\+?[0-9][0-9 ()-]{5,24}$/'],
            'email' => ($form['ask_email'] ?? false) ? ['nullable', 'email:rfc', 'max:190'] : null,
            'company' => ($form['ask_company'] ?? false) ? ['nullable', 'string', 'max:150'] : null,
            'message' => ($form['ask_message'] ?? false) ? ['nullable', 'string', 'max:2000'] : null,
        ]);
        $values = array_map(fn ($value) => is_string($value) ? trim($value) : $value, array_intersect_key($input, $rules));
        $values = array_map(fn ($value) => $value === '' ? null : $value, $values);
        $validator = Validator::make($values, $rules, [
            'name.*' => $message('name'), 'phone.*' => $message('phone'), 'email.*' => $message('email'),
            'company.*' => $message('long'), 'message.*' => $message('long'),
        ]);
        $errors = collect($validator->errors()->toArray())->map(fn ($messages) => $messages[0])->all();

        return [$errors, $errors === [] ? $validator->validated() : $values];
    }

    /**
     * Keeps the message and tells the partner (once for the same phone within minutes).
     *
     * @param  array<string, mixed>  $values  Checked (check()).
     */
    public function receive(Site $site, array $values, string $pageSlug, string $locale): Lead
    {
        $same = Lead::query()->where('site_id', $site->getKey())->where('phone', $values['phone'])
            ->where('created_at', '>=', now()->subMinutes(self::SAME_MINUTES))->first();
        if ($same !== null) {
            return $same;
        }
        $lead = Lead::create([
            'site_id' => $site->getKey(), 'name' => $values['name'], 'phone' => $values['phone'],
            'email' => $values['email'] ?? null, 'company' => $values['company'] ?? null, 'message' => $values['message'] ?? null,
            'page_slug' => mb_substr($pageSlug, 0, 80), 'locale' => $locale, 'status' => 'new',
        ]);
        LeadReceived::dispatch($lead);

        return $lead;
    }

    public function find(Site $site, string $id): Lead
    {
        return Lead::query()->where('site_id', $site->getKey())->find($id) ?? throw MarketingException::leadNotFound();
    }

    /** @param  array{status?: string, note?: string|null}  $changes */
    public function update(Lead $lead, array $changes, int $baseVersion, User $actor): Lead
    {
        return DB::transaction(function () use ($lead, $changes, $baseVersion, $actor) {
            $lead = Lead::query()->lockForUpdate()->findOrFail($lead->getKey());
            if ($lead->version !== $baseVersion) {
                throw MarketingException::changedMeanwhile();
            }
            $lead->fill(array_intersect_key($changes, array_flip(['status', 'note'])));
            if ($lead->isDirty()) {
                $lead->handled_by = $actor->getKey();
                $lead->version++;
                $lead->save();
            }

            return $lead;
        });
    }

    /** A visitor's message removed for good (they asked, or it was spam). */
    public function remove(Site $site, Lead $lead, User $actor): void
    {
        $lead->delete();
        // Who and when, never the visitor's details.
        $this->audit->record(action: 'marketing.lead_removed', target: $lead, old: ['status' => $lead->status], actor: $actor, partnerId: $site->partner_id);
    }
}
