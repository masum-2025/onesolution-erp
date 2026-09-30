<?php

namespace App\Platform\Support\Http;

use Illuminate\Http\Request;

/**
 * The page size a list endpoint returns: the caller's ?per_page within
 * 1..security.api.max_per_page (Phase 8-2). Every paginate() goes through this
 * (tests/Feature/Architecture/ApplicationSafetyTest).
 */
final class PerPage
{
    public static function from(Request $request, int $default = 50): int
    {
        $max = max(1, (int) config('security.api.max_per_page', 100));

        return max(1, min($request->integer('per_page', $default), $max));
    }
}
