<?php

namespace Modules\Letters\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Letters\Contracts\LetterSource;
use Modules\Letters\Exceptions\LettersException;

/**
 * The records of other modules letters are issued from (LetterSource, tag
 * letters.sources): who a record is for, refused with why when it is not
 * ready, the same 404 when it is not there (or its module is off).
 */
class Sources
{
    /**
     * @return array{title: string, kind: string, employee_ids: list<string>}
     */
    public function people(Organization $company, string $type, string $id, string $locale): array
    {
        $source = collect(app()->tagged('letters.sources'))->first(fn (LetterSource $item) => $item->type() === $type);
        $found = $source?->people($company, $id, $locale);
        if ($found === null) {
            throw LettersException::sourceNotFound();
        }
        if (isset($found['refused'])) {
            throw LettersException::sourceRefused($found['refused']);
        }

        return $found;
    }
}
