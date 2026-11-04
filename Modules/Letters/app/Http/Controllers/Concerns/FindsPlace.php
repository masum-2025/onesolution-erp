<?php

namespace Modules\Letters\Http\Controllers\Concerns;

use App\Platform\Tenancy\Http\Controllers\Api\Concerns\FindsVisibleOrganizations;
use App\Platform\Tenancy\Models\Organization;
use Modules\Letters\Exceptions\LettersException;
use Modules\Letters\Models\Letter;
use Modules\Letters\Models\LetterTemplate;
use Modules\Letters\Services\Place;

/**
 * The unit in the address (visible to the context), its company, and
 * records found only inside them; anything else is the same 404.
 */
trait FindsPlace
{
    use FindsVisibleOrganizations;

    /**
     * @return array{0: Organization, 1: Organization} The unit in the address and its company.
     */
    protected function place(string $organization): array
    {
        $unit = $this->findVisible($organization);

        return [$unit, app(Place::class)->companyOf($unit)];
    }

    protected function templateIn(Organization $company, string $id): LetterTemplate
    {
        return app(Place::class)->query(LetterTemplate::class, $company)->whereKey($id)->first() ?? throw LettersException::templateNotFound();
    }

    /** A letter of someone working in the unit or below it. */
    protected function letterIn(Organization $unit, Organization $company, string $id): Letter
    {
        $letter = app(Place::class)->query(Letter::class, $company)->whereKey($id)->first();
        if ($letter === null || ! in_array($letter->unit_id, app(Place::class)->subtreeIds($unit), true)) {
            throw LettersException::letterNotFound();
        }

        return $letter;
    }
}
