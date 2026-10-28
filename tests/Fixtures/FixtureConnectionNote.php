<?php

namespace Tests\Fixtures;

use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Scopes\OrganizationScope;
use RuntimeException;

/**
 * Stand-in for a module's connection message (a renewal request, a report):
 * stores a TenantNote in the receiver's own database, once per message.
 * "fail": true in the payload makes it throw (a receiving module's error).
 */
class FixtureConnectionNote implements ConnectionMessageHandler
{
    /** @var list<array{to: string, from: string, database: string, files: list<array<string, mixed>>}> */
    public static array $received = [];

    public function __construct(private string $direction = ConnectionMessage::TO_CLIENT) {}

    public function key(): string
    {
        return $this->direction === ConnectionMessage::TO_CLIENT ? 'crm.fixture_note' : 'crm.fixture_reply';
    }

    public function direction(): string
    {
        return $this->direction;
    }

    public function receive(ConnectionDelivery $delivery): ?string
    {
        if ($delivery->payload['fail'] ?? false) {
            throw new RuntimeException('Receiving module failed.');
        }
        $title = 'msg:'.$delivery->messageId.':'.($delivery->payload['title'] ?? '');
        $existing = TenantNote::query()->withoutGlobalScope(OrganizationScope::class)->where('title', $title)->first();
        if ($existing !== null) {
            return $existing->id;
        }
        $note = TenantNote::create(['title' => $title, 'organization_id' => $delivery->to->id]);
        self::$received[] = [
            'to' => $delivery->to->id,
            'from' => $delivery->fromName,
            'database' => app(TenantDatabases::class)->forModel($note),
            'files' => $delivery->files,
        ];

        return $note->id;
    }
}
