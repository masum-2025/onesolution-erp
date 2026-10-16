<?php

namespace Modules\TeacherFeedback\Models\Concerns;

use Illuminate\Database\Eloquent\Concerns\HasUlids;

/**
 * ULID keys that tell nothing about when or in which order a row was
 * written: the time part is fixed and the other 80 bits are fresh random
 * bits each time (a normal ULID counts up within a millisecond). Used where
 * a row must not be tied to who wrote it (feedback answers and comments).
 */
trait HasUntimedUlids
{
    use HasUlids;

    private const CROCKFORD = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public function newUniqueId(): string
    {
        $random = '';
        for ($i = 0; $i < 16; $i++) {
            $random .= self::CROCKFORD[random_int(0, 31)];
        }

        return strtolower('0000000000'.$random);
    }
}
