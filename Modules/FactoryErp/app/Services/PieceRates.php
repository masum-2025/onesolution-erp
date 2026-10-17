<?php

namespace Modules\FactoryErp\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Localization\LanguageRegistry;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Modules\FactoryErp\Exceptions\FactoryException;
use Modules\FactoryErp\Models\Operation;
use Modules\FactoryErp\Models\Order;
use Modules\FactoryErp\Models\PayLock;
use Modules\FactoryErp\Models\PieceTicket;
use Modules\Hrm\Directory\EmployeeDirectory;

/**
 * Piece rates: what each operator (an HRM employee) did — pieces of one
 * operation of an order in production on a day — at the operation's rate
 * then (per piece, or per dozen: rule factory_erp.piece_rate_per), paid
 * through Payroll as a taxable earning (PieceRateEarnings). All operators
 * together do an operation at most once per piece cut. Nobody writes their
 * own ticket unless the company allows it (factory_erp.piece_ticket_self).
 * Pay: append-only, a mistake voided with a reason; once a month's
 * payroll is approved its tickets no longer change. Needs HRM on.
 */
class PieceRates
{
    public function __construct(
        private Floor $floor,
        private Cuttings $cuttings,
        private EmployeeDirectory $employees,
        private ModuleResolver $modules,
        private AuditLogger $audit,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /** What a ticket pays: pieces × rate (÷ 12 for a dozen, half up), minor units. */
    public static function amount(int $pieces, int $rateMinor, int $per): int
    {
        return intdiv($pieces * $rateMinor * 2 + $per, 2 * $per);
    }

    public function locked(Organization $company, CarbonImmutable $day): bool
    {
        return $this->floor->query(PayLock::class, $company)->where('period', $day->format('Y-m'))->exists();
    }

    /** A month's payroll was approved: its tickets stay as they are. */
    public function lock(Organization $company, string $period): void
    {
        $this->floor->query(PayLock::class, $company)->firstOrCreate(['organization_id' => $company->getKey(), 'period' => $period]);
    }

    /**
     * @param  array<string, mixed>  $data  Validated by PieceTicketRequest.
     */
    public function record(Organization $company, Organization $unit, array $data, User $actor): PieceTicket
    {
        if (($data['op_id'] ?? null) !== null) {
            $done = $this->floor->query(PieceTicket::class, $company)->where('op_id', $data['op_id'])->first();
            if ($done !== null) {
                return $done;
            }
        }
        if (! $this->modules->isEnabled('hrm', $company)) {
            throw FactoryException::needsHrm();
        }
        $today = $this->floor->today($company);
        $on = isset($data['worked_on']) ? CarbonImmutable::parse($data['worked_on'], 'UTC') : $today;
        if ($on->greaterThan($today)) {
            throw FactoryException::futureDate('worked_on');
        }
        if ($this->locked($company, $on)) {
            throw FactoryException::monthLocked();
        }
        $units = $this->floor->subtreeIds($unit);
        $employee = $this->employees->find($company, $data['employee_id']);
        if ($employee === null || ! in_array($employee->unitId, $units, true) || $employee->joinedOn->greaterThan($on) || ($employee->exitsOn !== null && $employee->exitsOn->lessThan($on))) {
            throw FactoryException::notFound('employee');
        }
        $context = $this->contexts->forOrganization($unit);
        if ($employee->userId === $actor->getKey() && ! $this->rules->get('factory_erp.piece_ticket_self', $context)) {
            throw FactoryException::ownTicket();
        }
        $order = $this->floor->query(Order::class, $company)->whereKey($data['order_id'])->whereIn('unit_id', $units)->first() ?? throw FactoryException::notFound('order');
        $operation = $this->floor->query(Operation::class, $company)->whereKey($data['operation_id'])->where('style_id', $order->style_id)->first()
            ?? throw FactoryException::notFound('operation');
        if ($operation->piece_rate_minor === null) {
            throw FactoryException::noRate();
        }
        $per = (string) $this->rules->get('factory_erp.piece_rate_per', $context) === 'dozen' ? 12 : 1;
        $pieces = (int) $data['pieces'];
        $currency = $this->floor->currency($company);

        return $this->floor->transaction($company, function () use ($company, $employee, $order, $operation, $on, $pieces, $per, $currency, $data, $actor) {
            $order = $this->floor->query(Order::class, $company)->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($order->status !== 'in_production') {
                throw FactoryException::wrongStatus();
            }
            $cut = array_sum($this->cuttings->cut($company, $order));
            $done = (int) $this->floor->query(PieceTicket::class, $company)->where('order_id', $order->getKey())->where('operation_id', $operation->getKey())->whereNull('voided_at')->sum('pieces');
            if ($done + $pieces > $cut) {
                throw FactoryException::overOperation($operation->name, $cut - $done);
            }
            $ticket = $this->floor->query(PieceTicket::class, $company)->create([
                'organization_id' => $company->getKey(), 'unit_id' => $employee->unitId, 'employee_id' => $employee->id, 'order_id' => $order->getKey(),
                'operation_id' => $operation->getKey(), 'operation_name' => $operation->name, 'worked_on' => $on->toDateString(), 'pieces' => $pieces,
                'rate_minor' => $operation->piece_rate_minor, 'rate_per' => $per, 'amount_minor' => self::amount($pieces, $operation->piece_rate_minor, $per),
                'currency' => $currency, 'created_by' => $actor->getKey(), 'op_id' => $data['op_id'] ?? null,
            ]);
            $this->audit->record('factory_erp.piece_ticket_written', $ticket,
                new: ['employee' => $employee->code, 'order' => $order->number, 'operation' => $operation->name, 'pieces' => $pieces, 'amount_minor' => $ticket->amount_minor],
                actor: $actor, organizationId: $company->getKey());

            return $ticket;
        });
    }

    /** A wrong ticket stops paying; not once its month's payroll is approved. */
    public function void(Organization $company, PieceTicket $ticket, int $baseVersion, string $reason, User $actor): PieceTicket
    {
        return $this->floor->transaction($company, function () use ($company, $ticket, $baseVersion, $reason, $actor) {
            $ticket = $this->floor->query(PieceTicket::class, $company)->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            if ($ticket->version !== $baseVersion) {
                throw FactoryException::versionConflict(['version' => $ticket->version]);
            }
            if ($ticket->voided_at !== null) {
                throw FactoryException::wrongStatus();
            }
            if ($this->locked($company, $ticket->worked_on)) {
                throw FactoryException::monthLocked();
            }
            $ticket->forceFill(['voided_at' => now(), 'voided_by' => $actor->getKey(), 'void_reason' => $reason, 'version' => $ticket->version + 1])->save();
            $this->audit->record('factory_erp.piece_ticket_voided', $ticket, old: ['pieces' => $ticket->pieces, 'amount_minor' => $ticket->amount_minor], new: ['voided' => true],
                reason: $reason, actor: $actor, organizationId: $company->getKey());

            return $ticket;
        });
    }

    /**
     * Tickets of a day at the unit, newest first.
     *
     * @return Collection<int, PieceTicket>
     */
    public function ofDay(Organization $company, Organization $unit, CarbonImmutable $day): Collection
    {
        return $this->floor->query(PieceTicket::class, $company)->whereIn('unit_id', $this->floor->subtreeIds($unit))->where('worked_on', $day->toDateString())
            ->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    /**
     * Each employee's pieces and pay for the days from-to (tickets not voided).
     *
     * @param  list<string>|null  $employeeIds  null: everyone at the units.
     * @param  list<string>|null  $units
     * @return array<string, array{pieces: int, amount: int}>
     */
    public function totals(Organization $company, ?array $employeeIds, CarbonImmutable $from, CarbonImmutable $to, ?array $units = null): array
    {
        return $this->floor->query(PieceTicket::class, $company)->whereNull('voided_at')
            ->whereBetween('worked_on', [$from->toDateString(), $to->toDateString()])
            ->when($employeeIds !== null, fn ($query) => $query->whereIn('employee_id', $employeeIds))
            ->when($units !== null, fn ($query) => $query->whereIn('unit_id', $units))
            ->get(['employee_id', 'pieces', 'amount_minor'])->groupBy('employee_id')
            ->map(fn ($tickets) => ['pieces' => (int) $tickets->sum('pieces'), 'amount' => (int) $tickets->sum('amount_minor')])->all();
    }

    /**
     * Piece-rate pay for Payroll: one taxable line per employee, nothing where Factory ERP is off.
     *
     * @param  list<string>  $employeeIds
     * @return array<string, list<array{code: string, name: array<string, string>, amount: int, taxable: bool}>>
     */
    public function earnings(Organization $company, array $employeeIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if (! $this->modules->isEnabled('factory_erp', $company)) {
            return [];
        }
        $name = collect(LanguageRegistry::codes())->mapWithKeys(fn (string $locale) => [$locale => __('factory_erp::factory.piece_rate_line', [], $locale)])
            ->filter(fn (string $text) => $text !== 'factory_erp::factory.piece_rate_line')->all();

        return collect($this->totals($company, $employeeIds, $from, $to))
            ->filter(fn (array $total) => $total['amount'] > 0)
            ->map(fn (array $total) => [['code' => 'PIECE_RATE', 'name' => $name, 'amount' => $total['amount'], 'taxable' => true]])->all();
    }
}
