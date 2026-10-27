<?php

namespace Modules\Expenses\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Modules\Expenses\Services\Advances;
use Modules\Expenses\Services\Place;

/** In the bell of the finance people: paid advances past their day to settle with something still owed. */
final class AdvancesOverdue implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($context->user() === null || $unit->type === OrganizationType::Group || ! Gate::any(['expenses.pay', 'expenses.manage'], $unit)) {
            return [];
        }
        $place = app(Place::class);
        $count = app(Advances::class)->overdue($place->companyOf($unit), $place->subtreeIds($unit))->count();

        return $count === 0 ? [] : [new AttentionItem('expenses.advances_overdue', __('expenses::dashboard.attention_advances'), $count, '/expenses/claims?view=advances', 'warn')];
    }
}
