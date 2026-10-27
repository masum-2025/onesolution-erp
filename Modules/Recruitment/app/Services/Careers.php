<?php

namespace Modules\Recruitment\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Sites\SiteAddress;
use App\Platform\Sites\SiteResolver;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Facades\DB;
use Modules\Recruitment\Exceptions\RecruitmentException;

/**
 * The public careers page of a company: its address "/careers/{slug}" (a
 * name the company picks, kept with the other public sites' names so no
 * two are the same), and the signed token its forms carry (the page may
 * be kept a while; only this company's form, sent by a person, is taken).
 */
class Careers
{
    public const MODULE = 'recruitment';

    /** People need at least this long to fill the form. */
    public const MIN_SECONDS = 4;

    /** A form is taken for this long after its page was made. */
    public const TOKEN_HOURS = 24;

    public function __construct(private SiteResolver $resolver, private AuditLogger $audit) {}

    public function slug(Organization $company): ?string
    {
        return SiteAddress::query()->where('organization_id', $company->getKey())->where('module', self::MODULE)->value('slug');
    }

    /** The company of a careers address, or null. */
    public function company(string $slug): ?Organization
    {
        return $this->resolver->find(self::MODULE, strtolower($slug))?->organization;
    }

    public function setSlug(Organization $company, string $slug, User $actor): string
    {
        $slug = strtolower($slug);
        $previous = $this->slug($company);
        DB::transaction(function () use ($company, $slug) {
            if (SiteAddress::query()->where('slug', $slug)->where(fn ($query) => $query->where('organization_id', '!=', $company->getKey())->orWhere('module', '!=', self::MODULE))->lockForUpdate()->exists()) {
                throw RecruitmentException::slugTaken();
            }
            SiteAddress::query()->updateOrCreate(['organization_id' => $company->getKey(), 'module' => self::MODULE], ['slug' => $slug]);
        });
        $this->resolver->forget($slug);
        if ($previous !== null && $previous !== $slug) {
            $this->resolver->forget($previous);
        }
        $this->audit->record('recruitment.careers_address_set', $company, old: ['slug' => $previous], new: ['slug' => $slug], actor: $actor, organizationId: $company->getKey());

        return $slug;
    }

    /** "{time}.{signature}" for a form of this company's careers page. */
    public function token(Organization $company, ?int $at = null): string
    {
        $at ??= now()->getTimestamp();

        return $at.'.'.hash_hmac('sha256', "recruitment.apply|{$company->getKey()}|{$at}", (string) config('app.key'));
    }

    /** ok, fast (a robot), or bad (not this company's form, or too old). */
    public function check(Organization $company, ?string $token): string
    {
        if (! is_string($token) || preg_match('/^(\d{9,11})\.([0-9a-f]{64})$/', $token, $match) !== 1
            || ! hash_equals($this->token($company, (int) $match[1]), $token)) {
            return 'bad';
        }
        $age = now()->getTimestamp() - (int) $match[1];
        if ($age > self::TOKEN_HOURS * 3600 || $age < -60) {
            return 'bad';
        }

        return $age < self::MIN_SECONDS ? 'fast' : 'ok';
    }
}
