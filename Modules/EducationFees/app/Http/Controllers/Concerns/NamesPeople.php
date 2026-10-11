<?php

namespace Modules\EducationFees\Http\Controllers\Concerns;

use App\Models\User;

/**
 * The names of the people a list mentions (who took the money, asked,
 * decided), so a screen shows names, never ids. Names only.
 */
trait NamesPeople
{
    /**
     * @param  iterable<string|null>  $ids
     * @return array<string, string>
     */
    protected function people(iterable $ids): array
    {
        $ids = collect($ids)->filter()->unique()->values()->all();

        return $ids === [] ? [] : User::query()->whereKey($ids)->pluck('name', 'id')->map(fn ($name) => (string) $name)->all();
    }
}
