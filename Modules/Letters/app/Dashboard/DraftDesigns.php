<?php

namespace Modules\Letters\Dashboard;

use App\Platform\Attention\AttentionItem;
use App\Platform\Attention\Contracts\AttentionProvider;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\OrganizationType;
use Illuminate\Support\Facades\Gate;
use Modules\Letters\Models\LetterTemplate;
use Modules\Letters\Services\Place;

/** In the bell of people who design letters: designs still in draft (letters cannot be issued with them yet). */
final class DraftDesigns implements AttentionProvider
{
    public function items(CurrentContext $context): array
    {
        $unit = $context->organization();
        if ($unit->type === OrganizationType::Group) {
            return [];
        }
        $place = app(Place::class);
        $company = $place->companyOf($unit);
        if (! Gate::allows('letters.design', $company)) {
            return [];
        }
        $count = $place->query(LetterTemplate::class, $company)->where('status', LetterTemplate::DRAFT)->count();

        return $count === 0 ? [] : [new AttentionItem('letters.drafts', __('letters::dashboard.attention_drafts'), $count, '/letters/templates', 'info')];
    }
}
