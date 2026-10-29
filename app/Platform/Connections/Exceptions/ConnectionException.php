<?php

namespace App\Platform\Connections\Exceptions;

use App\Platform\Tenancy\Exceptions\TenancyException;

/**
 * Business connection errors (NET-1), each telling the person what to do
 * next: lang/{locale}/connections.php "errors.*".
 */
class ConnectionException extends TenancyException
{
    protected function translationKey(): string
    {
        return 'connections.errors.'.$this->errorCode;
    }

    public static function notFound(): self
    {
        return new self('not_found', 404);
    }

    /** Support access never shows a client's connections: the other side may be another provider's client. */
    public static function notDuringSupport(): self
    {
        return new self('not_during_support', 403);
    }

    public static function providerMustBeCompany(): self
    {
        return new self('provider_must_be_company', 422);
    }

    public static function clientMustBeGroupOrCompany(): self
    {
        return new self('client_must_be_group_or_company', 422);
    }

    public static function codeNotFound(): self
    {
        return new self('code_not_found', 404, [], ['field' => 'code']);
    }

    public static function codeClosed(): self
    {
        return new self('code_closed', 410, [], ['field' => 'code']);
    }

    public static function ownOrganization(): self
    {
        return new self('own_organization', 422, [], ['field' => 'code']);
    }

    public static function otherPartner(): self
    {
        return new self('other_partner', 422, [], ['field' => 'code']);
    }

    public static function alreadyConnected(): self
    {
        return new self('already_connected', 409, [], ['field' => 'code']);
    }

    public static function unitsNeeded(): self
    {
        return new self('units_needed', 422, [], ['field' => 'unit_ids']);
    }

    public static function unitNotYours(): self
    {
        return new self('unit_not_yours', 422, [], ['field' => 'unit_ids']);
    }

    public static function notYourSide(): self
    {
        return new self('not_your_side', 403);
    }

    public static function wrongStatus(string $status): self
    {
        return new self('wrong_status', 409, ['status' => __("connections.statuses.{$status}")]);
    }

    public static function versionConflict(): self
    {
        return new self('version_conflict', 409);
    }

    public static function notActive(): self
    {
        return new self('not_active', 409);
    }

    public static function unitNotCovered(): self
    {
        return new self('unit_not_covered', 422);
    }

    public static function kindNotAvailable(string $kind): self
    {
        return new self('kind_not_available', 422, ['kind' => $kind]);
    }

    public static function payloadTooLarge(): self
    {
        return new self('payload_too_large', 413);
    }

    public static function fileTooLarge(int $mb): self
    {
        return new self('file_too_large', 413, ['mb' => $mb]);
    }

    public static function moduleNotGrantable(): self
    {
        return new self('module_not_grantable', 422, [], ['field' => 'module']);
    }

    public static function permissionsNotOfModule(): self
    {
        return new self('permissions_not_of_module', 422, [], ['field' => 'permissions']);
    }

    /** No working permission (not given, taken back, past its day, or the factory not shared). */
    public static function noGrant(): self
    {
        return new self('no_grant', 403);
    }

    /** The person's own company has not let them act for it with clients (business_network.act). */
    public static function notAllowedToAct(): self
    {
        return new self('not_allowed_to_act', 403);
    }

    /** In a connection context: this part of the client's data was not given. */
    public static function outsideGrant(): self
    {
        return new self('outside_grant', 403);
    }

    public static function fileMissing(): self
    {
        return new self('file_missing', 422);
    }
}
