<?php

namespace App\Platform\Audit\Http;

use App\Platform\Support\Http\StrictFormRequest;
use App\Platform\Tenancy\Context\CurrentContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Validator;

/**
 * Filters of the audit log, its reports and exports (Phase 9-1). Dates are
 * whole days in the organization's time zone; "to" includes that day.
 */
class AuditFilterRequest extends StrictFormRequest
{
    /** The longest period, in days, this request may cover (null = any). */
    protected ?int $maxDays = null;

    /** Whether a period must be given. */
    protected bool $needsPeriod = false;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer'],
            'filter' => ['sometimes', 'nullable', 'in:all,changes,support'],
            'from' => [$this->needsPeriod ? 'required' : 'sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => [$this->needsPeriod ? 'required' : 'sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'action' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[a-z0-9_]+(\.[a-z0-9_.]+)?$/'],
            'actor' => ['sometimes', 'nullable', 'string', 'ulid'],
        ];
    }

    /**
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [
            ...parent::after(),
            function (Validator $validator) {
                [$from, $to] = [$this->date('from', 'Y-m-d'), $this->date('to', 'Y-m-d')];

                if ($this->maxDays !== null && $from !== null && $to !== null && $from->diffInDays($to) + 1 > $this->maxDays) {
                    $validator->errors()->add('to', __('audit.errors.period_too_long', ['days' => $this->maxDays]));
                }
            },
        ];
    }

    public function timezone(): string
    {
        return app(CurrentContext::class)->timezone() ?? 'UTC';
    }

    /**
     * @return array{from: ?CarbonImmutable, to: ?CarbonImmutable, action: ?string, actor: ?string, filter: ?string}
     */
    public function filters(): array
    {
        $zone = $this->timezone();
        $from = $this->validated('from');
        $to = $this->validated('to');

        return [
            'from' => $from === null ? null : CarbonImmutable::parse($from, $zone)->startOfDay(),
            'to' => $to === null ? null : CarbonImmutable::parse($to, $zone)->startOfDay()->addDay(),
            'action' => $this->validated('action'),
            'actor' => $this->validated('actor'),
            'filter' => $this->validated('filter'),
        ];
    }
}
