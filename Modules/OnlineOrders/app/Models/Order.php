<?php

namespace Modules\OnlineOrders\Models;

use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use App\Platform\Tenancy\Databases\UsesTenantDatabase;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/** An online order: its customer, delivery, frozen totals and where it stands. */
#[Fillable(['organization_id', 'unit_id', 'warehouse_id', 'number', 'channel', 'channel_ref', 'status', 'customer_name', 'customer_phone', 'customer_id', 'address', 'delivery_zone', 'payment_method', 'subtotal_minor', 'discount_minor', 'tax_minor', 'delivery_minor', 'total_minor', 'currency_code', 'prices_include_tax', 'note', 'created_by', 'op_id', 'version'])]
class Order extends Model
{
    use BelongsToOrganization, HasUlids, UsesTenantDatabase;

    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    public const PACKED = 'packed';

    public const SHIPPED = 'shipped';

    public const DELIVERED = 'delivered';

    public const CANCELLED = 'cancelled';

    public const RETURNED = 'returned';

    public const STATUSES = [self::DRAFT, self::CONFIRMED, self::PACKED, self::SHIPPED, self::DELIVERED, self::CANCELLED, self::RETURNED];

    /** Orders holding stock in their warehouse (confirmed, not yet gone out). */
    public const RESERVING = [self::CONFIRMED, self::PACKED];

    public const CHANNELS = ['manual', 'facebook', 'website'];

    public const PAYMENT_METHODS = ['cod', 'prepaid'];

    protected $table = 'online_orders';

    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'discount_minor' => 'integer',
            'tax_minor' => 'integer',
            'delivery_minor' => 'integer',
            'total_minor' => 'integer',
            'paid_minor' => 'integer',
            'refunded_minor' => 'integer',
            'cost_minor' => 'integer',
            'prices_include_tax' => 'boolean',
            'confirmed_at' => 'immutable_datetime',
            'shipped_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'version' => 'integer',
        ];
    }

    /** Money kept from the customer: paid less given back. */
    public function heldMinor(): int
    {
        return $this->paid_minor - $this->refunded_minor;
    }

    /**
     * What the customer still owes: the total; once taken back, only the
     * delivery; nothing once cancelled.
     */
    public function dueMinor(): int
    {
        $owed = match ($this->status) {
            self::CANCELLED => 0,
            self::RETURNED => $this->delivery_minor,
            default => $this->total_minor,
        };

        return max(0, $owed - $this->heldMinor());
    }
}
