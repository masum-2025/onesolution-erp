<?php

namespace Modules\Compliance\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of the register: a factory (organization_id: a company or a
 * branch) and an audit service, with the official last audit and expiry
 * days. Days left come from expires_on (Support\Validity), never stored.
 */
#[Fillable(['organization_id', 'company_id', 'service_id', 'provider_id', 'audit_type_id', 'last_audit_on', 'expires_on', 'result', 'certificate_no', 'notes', 'version'])]
class Audit extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    protected $table = 'cmp_audits';

    protected function casts(): array
    {
        return ['last_audit_on' => 'immutable_date', 'expires_on' => 'immutable_date', 'version' => 'integer'];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * @return BelongsTo<AuditType, $this>
     */
    public function auditType(): BelongsTo
    {
        return $this->belongsTo(AuditType::class);
    }
}
