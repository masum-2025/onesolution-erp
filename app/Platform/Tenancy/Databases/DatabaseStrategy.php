<?php

namespace App\Platform\Tenancy\Databases;

/**
 * Where a client tree's business data lives.
 */
enum DatabaseStrategy: string
{
    /** The main database, together with every other client (default). */
    case Shared = 'shared';

    /** A database of its own (large or regulated clients). */
    case Dedicated = 'dedicated';

    /** The database of the client's data-residency region. */
    case Regional = 'regional';
}
