<?php

namespace Modules\Accounting\Http\Controllers\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Accounting\Services\MoneyFloors;

/**
 * A person who saw the "below zero" warning and posts anyway sends
 * allow_negative with a reason; it counts for this request only.
 */
trait ConfirmsNegativeBalances
{
    protected function confirmNegative(FormRequest $request): void
    {
        if ($request->boolean('allow_negative')) {
            app(MoneyFloors::class)->acknowledge((string) $request->validated('negative_reason'));
        }
    }

    /**
     * Validation rules the posting requests share.
     *
     * @return array<string, list<string>>
     */
    public static function negativeRules(): array
    {
        return [
            'allow_negative' => ['sometimes', 'boolean'],
            'negative_reason' => ['required_if_accepted:allow_negative', 'nullable', 'string', 'min:3', 'max:500'],
        ];
    }
}
