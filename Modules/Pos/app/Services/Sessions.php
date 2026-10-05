<?php

namespace Modules\Pos\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Modules\Accounting\Ledger\LedgerLine;
use Modules\Pos\Exceptions\PosException;
use Modules\Pos\Models\Payment;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Sale;
use Modules\Pos\Models\Session;

/**
 * Shifts at a counter. One open shift per counter: opened with a float of
 * cash; closed with the cash counted. The cash expected is the float plus
 * cash taken (less change) less cash paid back on returns. A difference
 * within rule pos.cash_variance_allowed (a money rule; empty = none allowed)
 * closes the shift and is posted to pos.cash_variance; beyond it the shift
 * waits for a supervisor (pos.supervise, not who closed it) who posts it.
 */
class Sessions
{
    public function __construct(
        private Tills $tills,
        private PosPostings $postings,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
        private AuditLogger $audit,
    ) {}

    public function open(Organization $company, Register $register, int $float, User $actor): Session
    {
        if (! $register->is_active) {
            throw PosException::registerInactive();
        }

        return $this->tills->transaction($company, function () use ($company, $register, $float, $actor) {
            // One at a time per counter (the counter row is the lock).
            $this->tills->query(Register::class, $company)->whereKey($register->getKey())->lockForUpdate()->first();
            if ($this->current($company, $register) !== null) {
                throw PosException::sessionOpen();
            }
            $session = new Session;
            $session->fill([
                'organization_id' => $company->getKey(), 'register_id' => $register->getKey(), 'status' => Session::OPEN, 'opened_by' => $actor->getKey(),
                'opened_at' => now(), 'opening_float_minor' => $float, 'currency_code' => $this->tills->currency($company), 'version' => 1,
            ])->save();
            $this->audit->record('pos.session_opened', $session, new: ['register_id' => $register->getKey(), 'opening_float_minor' => $float], actor: $actor, organizationId: $company->getKey());

            return $session;
        });
    }

    public function current(Organization $company, Register $register): ?Session
    {
        return $this->tills->query(Session::class, $company)->where('register_id', $register->getKey())->where('status', Session::OPEN)->first();
    }

    /** Cash the drawer should hold now: the float, cash taken less change, less cash paid back. */
    public function expectedCash(Organization $company, Session $session): int
    {
        $sales = $this->tills->query(Sale::class, $company)->where('session_id', $session->getKey())->get(['id', 'kind', 'change_minor']);
        $cash = $this->tills->query(Payment::class, $company)->whereIn('sale_id', $sales->pluck('id')->all())->where('method', 'cash')->get(['sale_id', 'amount_minor'])->groupBy('sale_id');
        $total = $session->opening_float_minor;
        foreach ($sales as $sale) {
            $amount = (int) ($cash[$sale->getKey()] ?? collect())->sum('amount_minor');
            $total += $sale->kind === 'return' ? -$amount : $amount - $sale->change_minor;
        }

        return $total;
    }

    /**
     * Takings by payment method (less change and returns) and counts: the shift's Z report.
     *
     * @return array{methods: array<string, int>, sales: int, returns: int, sales_minor: int, returns_minor: int, tax_minor: int, discount_minor: int}
     */
    public function report(Organization $company, Session $session): array
    {
        $sales = $this->tills->query(Sale::class, $company)->where('session_id', $session->getKey())->get();
        $payments = $this->tills->query(Payment::class, $company)->whereIn('sale_id', $sales->pluck('id')->all())->get()->groupBy('sale_id');
        $methods = array_fill_keys(Register::METHODS, 0);
        foreach ($sales as $sale) {
            foreach ($payments[$sale->getKey()] ?? [] as $payment) {
                $methods[$payment->method] += $sale->kind === 'return' ? -$payment->amount_minor : $payment->amount_minor;
            }
            if ($sale->kind === 'sale') {
                $methods['cash'] -= $sale->change_minor;
            }
        }
        $byKind = $sales->groupBy('kind');

        return [
            'methods' => $methods,
            'sales' => ($byKind['sale'] ?? collect())->count(), 'returns' => ($byKind['return'] ?? collect())->count(),
            'sales_minor' => (int) ($byKind['sale'] ?? collect())->sum('total_minor'), 'returns_minor' => (int) ($byKind['return'] ?? collect())->sum('total_minor'),
            'tax_minor' => (int) ($byKind['sale'] ?? collect())->sum('tax_minor') - (int) ($byKind['return'] ?? collect())->sum('tax_minor'),
            'discount_minor' => (int) ($byKind['sale'] ?? collect())->sum('discount_minor'),
        ];
    }

    public function close(Organization $company, Session $session, int $baseVersion, int $counted, ?string $note, User $actor): Session
    {
        return $this->tills->transaction($company, function () use ($company, $session, $baseVersion, $counted, $note, $actor) {
            $session = $this->locked($company, $session, $baseVersion);
            if ($session->status !== Session::OPEN) {
                throw PosException::sessionNotOpen();
            }
            $expected = $this->expectedCash($company, $session);
            $variance = $counted - $expected;
            $session->forceFill([
                'closed_by' => $actor->getKey(), 'closed_at' => now(), 'expected_cash_minor' => $expected, 'counted_cash_minor' => $counted,
                'variance_minor' => $variance, 'close_note' => $note, 'version' => $session->version + 1,
                'status' => $this->withinLimit($company, $session, $variance) ? Session::CLOSED : Session::PENDING,
            ]);
            if ($session->status === Session::CLOSED) {
                $session->variance_journal_id = $this->postVariance($company, $session);
            }
            $session->save();
            $this->audit->record('pos.session_closed', $session, new: ['expected_cash_minor' => $expected, 'counted_cash_minor' => $counted, 'variance_minor' => $variance, 'status' => $session->status],
                reason: $note, actor: $actor, organizationId: $company->getKey());

            return $session;
        });
    }

    /** A supervisor (not who closed it) accepts a difference beyond the limit; it is posted. */
    public function review(Organization $company, Session $session, int $baseVersion, string $note, User $actor): Session
    {
        return $this->tills->transaction($company, function () use ($company, $session, $baseVersion, $note, $actor) {
            $session = $this->locked($company, $session, $baseVersion);
            if ($session->status !== Session::PENDING) {
                throw PosException::notPending();
            }
            if (in_array($actor->getKey(), [$session->closed_by, $session->opened_by], true)) {
                throw PosException::ownSession();
            }
            $session->forceFill(['status' => Session::CLOSED, 'reviewed_by' => $actor->getKey(), 'reviewed_at' => now(), 'review_note' => $note, 'version' => $session->version + 1]);
            $session->variance_journal_id = $this->postVariance($company, $session);
            $session->save();
            $this->audit->record('pos.session_reviewed', $session, new: ['variance_minor' => $session->variance_minor], reason: $note, actor: $actor, organizationId: $company->getKey());

            return $session;
        });
    }

    private function withinLimit(Organization $company, Session $session, int $variance): bool
    {
        if ($variance === 0) {
            return true;
        }
        $register = $this->tills->query(Register::class, $company)->findOrFail($session->register_id);
        $limit = $this->rules->get('pos.cash_variance_allowed', $this->contexts->forOrganization(Organization::query()->findOrFail($register->unit_id)));

        return is_array($limit) && $limit['currency'] === $session->currency_code && abs($variance) <= (int) $limit['amount'];
    }

    /** Cash over (credit) or short (debit) against the drawer. */
    private function postVariance(Organization $company, Session $session): ?string
    {
        $variance = (int) $session->variance_minor;
        if ($variance === 0) {
            return null;
        }
        $register = $this->tills->query(Register::class, $company)->findOrFail($session->register_id);
        $lines = $variance < 0
            ? [LedgerLine::debit('pos.cash_variance', -$variance, $register->unit_id), LedgerLine::credit('pos.cash', -$variance, $register->unit_id)]
            : [LedgerLine::debit('pos.cash', $variance, $register->unit_id), LedgerLine::credit('pos.cash_variance', $variance, $register->unit_id)];

        return $this->postings->post($company, "pos-session-variance-{$session->getKey()}", CarbonImmutable::now()->startOfDay(),
            __('pos::pos.narration.variance', ['register' => $register->code]), 'session', $session->getKey(), $session->currency_code, $lines);
    }

    private function locked(Organization $company, Session $session, int $baseVersion): Session
    {
        /** @var Session $fresh */
        $fresh = $this->tills->query(Session::class, $company)->whereKey($session->getKey())->lockForUpdate()->firstOrFail();
        if ($fresh->version !== $baseVersion) {
            throw PosException::versionConflict(['version' => $fresh->version, 'status' => $fresh->status]);
        }

        return $fresh;
    }
}
