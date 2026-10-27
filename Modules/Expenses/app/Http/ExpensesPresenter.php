<?php

namespace Modules\Expenses\Http;

use App\Models\User;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Support\Collection;
use Modules\Expenses\Models\Advance;
use Modules\Expenses\Models\AdvanceApproval;
use Modules\Expenses\Models\AdvanceMove;
use Modules\Expenses\Models\Approval;
use Modules\Expenses\Models\Category;
use Modules\Expenses\Models\Claim;
use Modules\Expenses\Models\Line;
use Modules\Expenses\Models\Receipt;
use Modules\Expenses\Services\Advances;
use Modules\Expenses\Services\Place;
use Modules\Expenses\Services\Receipts;
use Modules\Hrm\Directory\EmployeeDirectory;

/** Expense records as the API shows them (money in minor units with the currency). */
class ExpensesPresenter
{
    public function __construct(private Place $place, private Receipts $receipts, private EmployeeDirectory $directory) {}

    /**
     * @return array<string, mixed>
     */
    public function category(Category $category): array
    {
        return [
            'id' => $category->getKey(), 'code' => $category->code, 'name' => $category->textIn('name'), 'names' => $category->texts('name'),
            'posting_key' => $category->posting_key, 'mileage' => $category->mileage, 'per_diem' => $category->per_diem, 'is_active' => $category->is_active, 'version' => $category->version,
        ];
    }

    /**
     * Claims for a list: the employee's name, the totals and where they stand.
     *
     * @param  iterable<Claim>  $claims
     * @return list<array<string, mixed>>
     */
    public function claims(Organization $company, iterable $claims): array
    {
        $claims = collect($claims);
        $people = collect($this->directory->many($company, $claims->pluck('employee_id')->unique()->values()->all()))->keyBy('id');
        $lines = $this->place->query(Line::class, $company)->whereIn('claim_id', $claims->map(fn (Claim $claim) => $claim->getKey())->all())->get(['claim_id'])->countBy('claim_id');

        return $claims->map(fn (Claim $claim) => [
            ...$this->claim($claim),
            'employee' => ['id' => $claim->employee_id, 'name' => $people[$claim->employee_id]->name ?? '', 'code' => $people[$claim->employee_id]->code ?? ''],
            'line_count' => (int) ($lines[$claim->getKey()] ?? 0),
        ])->values()->all();
    }

    /**
     * A claim with its lines, receipts (each with a short signed link) and decisions.
     *
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function detail(Organization $company, Claim $claim, array $can = []): array
    {
        $categories = $this->place->query(Category::class, $company)->get()->keyBy(fn (Category $category) => $category->getKey());
        $lines = $this->place->query(Line::class, $company)->where('claim_id', $claim->getKey())->orderBy('line_no')->get();
        $receipts = $this->place->query(Receipt::class, $company)->where('claim_id', $claim->getKey())->orderBy('created_at')->get();
        $approvals = $this->place->query(Approval::class, $company)->where('claim_id', $claim->getKey())->orderBy('step')->get();

        return [
            ...$this->claims($company, [$claim])[0],
            'lines' => $lines->map(fn (Line $line) => [
                'id' => $line->getKey(), 'line_no' => $line->line_no, 'spent_on' => $line->spent_on->toDateString(), 'category_id' => $line->category_id,
                'category' => isset($categories[$line->category_id]) ? $categories[$line->category_id]->textIn('name') : '',
                'mileage' => (bool) ($categories[$line->category_id]->mileage ?? false), 'description' => $line->description,
                'amount_minor' => $line->amount_minor, 'distance_tenths' => $line->distance_tenths,
                'per_diem' => (bool) ($categories[$line->category_id]->per_diem ?? false), 'per_diem_code' => $line->per_diem_code, 'days' => $line->days,
            ])->values()->all(),
            'receipts' => $receipts->map(fn (Receipt $receipt) => [
                'id' => $receipt->getKey(), 'line_id' => $receipt->line_id, 'name' => $receipt->name, 'mime' => $receipt->mime,
                'size_bytes' => $receipt->size_bytes, 'url' => $this->receipts->url($company, $receipt),
            ])->values()->all(),
            'approvals' => $this->decisions($approvals),
            'can' => $can,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(Claim $claim): array
    {
        return [
            'id' => $claim->getKey(), 'number' => $claim->number, 'title' => $claim->title, 'unit_id' => $claim->unit_id, 'employee_id' => $claim->employee_id,
            'currency' => $claim->currency, 'total_minor' => $claim->total_minor, 'status' => $claim->status,
            'steps_needed' => $claim->steps_needed, 'steps_done' => $claim->steps_done, 'submitted_at' => $claim->submitted_at?->toIso8601String(),
            'decided_at' => $claim->decided_at?->toIso8601String(), 'decision_note' => $claim->decision_note,
            'pay_method' => $claim->pay_method, 'pay_period' => $claim->pay_period, 'paid_at' => $claim->paid_at?->toIso8601String(),
            'posted' => $claim->journal_id !== null, 'created_at' => $claim->created_at?->toIso8601String(), 'version' => $claim->version,
            'advance_id' => $claim->advance_id, 'advance_minor' => $claim->advance_minor,
        ];
    }

    /**
     * Advances for a list: the employee, what is still owed, where it stands.
     *
     * @param  iterable<Advance>  $advances
     * @return list<array<string, mixed>>
     */
    public function advances(Organization $company, iterable $advances): array
    {
        $advances = collect($advances);
        $people = collect($this->directory->many($company, $advances->pluck('employee_id')->unique()->values()->all()))->keyBy('id');
        $service = app(Advances::class);
        $today = $this->place->today($company)->toDateString();

        return $advances->map(function (Advance $advance) use ($company, $people, $service, $today) {
            $owed = $service->outstanding($company, $advance);

            return [
                'id' => $advance->getKey(), 'number' => $advance->number, 'purpose' => $advance->purpose, 'unit_id' => $advance->unit_id,
                'employee' => ['id' => $advance->employee_id, 'name' => $people[$advance->employee_id]->name ?? '', 'code' => $people[$advance->employee_id]->code ?? ''],
                'currency' => $advance->currency, 'amount_minor' => $advance->amount_minor, 'outstanding_minor' => $owed,
                'settle_by' => $advance->settle_by?->toDateString(), 'status' => $advance->status, 'steps_needed' => $advance->steps_needed, 'steps_done' => $advance->steps_done,
                'decision_note' => $advance->decision_note, 'pay_method' => $advance->pay_method, 'paid_at' => $advance->paid_at?->toIso8601String(),
                'recover_period' => $advance->recover_period,
                'overdue' => $advance->status === Advance::PAID && $advance->settle_by !== null && $advance->settle_by->toDateString() < $today && $owed > 0,
                'created_at' => $advance->created_at?->toIso8601String(), 'version' => $advance->version,
            ];
        })->values()->all();
    }

    /**
     * One advance with its decisions and movements, and what this person may do.
     *
     * @param  array<string, bool>  $can
     * @return array<string, mixed>
     */
    public function advance(Organization $company, Advance $advance, array $can = []): array
    {
        $approvals = $this->place->query(AdvanceApproval::class, $company)->where('advance_id', $advance->getKey())->orderBy('step')->get();
        $moves = $this->place->query(AdvanceMove::class, $company)->where('advance_id', $advance->getKey())->orderBy('created_at')->get();
        $numbers = $this->place->query(Claim::class, $company)->whereKey($moves->pluck('claim_id')->filter()->all())->pluck('number', 'id');
        $names = User::query()->whereKey($approvals->pluck('user_id')->unique()->all())->pluck('name', 'id');

        return [
            ...$this->advances($company, [$advance])[0],
            'approvals' => $approvals->map(fn (AdvanceApproval $approval) => [
                'step' => $approval->step, 'decision' => $approval->decision, 'note' => $approval->note,
                'by' => $names[$approval->user_id] ?? '', 'at' => $approval->created_at?->toIso8601String(),
            ])->values()->all(),
            'moves' => $moves->map(fn (AdvanceMove $move) => [
                'kind' => $move->kind, 'amount_minor' => $move->amount_minor, 'claim_number' => $move->claim_id === null ? null : ($numbers[$move->claim_id] ?? null),
                'method' => $move->method, 'at' => $move->created_at?->toIso8601String(),
            ])->values()->all(),
            'can' => $can,
        ];
    }

    /**
     * @param  Collection<int, Approval>  $approvals
     * @return list<array<string, mixed>>
     */
    private function decisions(Collection $approvals): array
    {
        $names = User::query()->whereKey($approvals->pluck('user_id')->unique()->all())->pluck('name', 'id');

        return $approvals->map(fn (Approval $approval) => [
            'step' => $approval->step, 'decision' => $approval->decision, 'note' => $approval->note,
            'by' => $names[$approval->user_id] ?? '', 'at' => $approval->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
