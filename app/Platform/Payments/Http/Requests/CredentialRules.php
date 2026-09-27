<?php

namespace App\Platform\Payments\Http\Requests;

use App\Platform\Payments\Contracts\GatewayDriver;

/**
 * Validation for a gateway's own credential fields, as "credentials.{field}".
 */
final class CredentialRules
{
    /**
     * @return array<string, list<string>>
     */
    public static function for(?GatewayDriver $driver, bool $required): array
    {
        if ($driver === null) {
            return [];
        }

        $rules = [];
        foreach ($driver->credentialFields() as $field => $definition) {
            $rules["credentials.{$field}"] = $required ? $definition['rules'] : ['sometimes', ...$definition['rules']];
        }

        return $rules;
    }
}
