<?php

namespace App\Platform\Partners\Contracts;

/**
 * Reads DNS TXT records (domain ownership checks). Swappable so tests and
 * air-gapped installs never depend on the public DNS.
 */
interface DnsTxtLookup
{
    /**
     * @return list<string> Every TXT value published at the name (empty when none or on failure).
     */
    public function txt(string $name): array;
}
