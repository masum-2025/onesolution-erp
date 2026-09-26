<?php

namespace App\Platform\Partners\Services;

use App\Platform\Partners\Contracts\DnsTxtLookup;

/**
 * TXT lookup through the server's resolver.
 */
class SystemDnsTxtLookup implements DnsTxtLookup
{
    public function txt(string $name): array
    {
        $records = @dns_get_record($name, DNS_TXT);

        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (array $record) => isset($record['entries']) ? implode('', $record['entries']) : ($record['txt'] ?? null),
            $records,
        ), 'is_string'));
    }
}
