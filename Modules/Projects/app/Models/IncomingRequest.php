<?php

namespace Modules\Projects\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A renewal request a connected client sent: new until the provider accepts
 * it (a project is opened) or declines it; cancelled when the client takes it back.
 */
#[Fillable(['organization_id', 'connection_id', 'client_request_id', 'message_id', 'client_unit_id', 'client_name', 'factory_name', 'service_code', 'service_names', 'expires_on', 'wanted_by', 'note', 'requested_by', 'status', 'reason', 'project_id', 'decided_by', 'decided_at', 'version'])]
class IncomingRequest extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const NEW = 'new';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const CANCELLED = 'cancelled';

    protected $table = 'prj_requests';

    protected function casts(): array
    {
        return [
            'service_names' => 'array',
            'expires_on' => 'immutable_date',
            'wanted_by' => 'immutable_date',
            'decided_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** The service's name in the reader's language (else English, else the code). */
    public function serviceName(?string $locale = null): string
    {
        $names = (array) $this->service_names;

        return (string) ($names[$locale ?? app()->getLocale()] ?? $names['en'] ?? $this->service_code ?? '');
    }
}
