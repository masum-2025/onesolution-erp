<?php

namespace App\Platform\Offline;

/**
 * What happened to one offline change: applied (with the record's new id and
 * version), a conflict (the server's current record, for the device to show
 * and resolve), or rejected (why, per field). Quarantined changes are held
 * for an admin (not applied yet); retry_later ones wait on the device (the
 * client's data is being moved, Phase 10).
 */
final readonly class SyncResult
{
    public const APPLIED = 'applied';

    public const CONFLICT = 'conflict';

    public const REJECTED = 'rejected';

    public const QUARANTINED = 'quarantined';

    /** Not applied yet and not decided: the device keeps it and sends it again later. */
    public const RETRY_LATER = 'retry_later';

    /**
     * @param  array<string, mixed>|null  $server  The current record, on a conflict.
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(
        public string $status,
        public ?string $recordId = null,
        public ?int $version = null,
        public ?array $server = null,
        public array $errors = [],
        public ?string $code = null,
    ) {}

    public static function applied(string $recordId, int $version): self
    {
        return new self(self::APPLIED, $recordId, $version);
    }

    /**
     * @param  array<string, mixed>  $server
     */
    public static function conflict(string $recordId, int $version, array $server): self
    {
        return new self(self::CONFLICT, $recordId, $version, $server, [], 'stale_version');
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function rejected(string $code, array $errors = []): self
    {
        return new self(self::REJECTED, errors: $errors, code: $code);
    }

    public static function quarantined(string $code): self
    {
        return new self(self::QUARANTINED, code: $code);
    }

    public static function retryLater(string $code): self
    {
        return new self(self::RETRY_LATER, code: $code);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'code' => $this->code,
            'record_id' => $this->recordId,
            'version' => $this->version,
            'server' => $this->server,
            'errors' => $this->errors === [] ? null : $this->errors,
            'message' => $this->code === null ? null : __("offline.results.{$this->code}"),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $stored
     */
    public static function fromArray(array $stored): self
    {
        return new self(
            status: (string) $stored['status'],
            recordId: $stored['record_id'] ?? null,
            version: isset($stored['version']) ? (int) $stored['version'] : null,
            server: $stored['server'] ?? null,
            errors: $stored['errors'] ?? [],
            code: $stored['code'] ?? null,
        );
    }
}
