<?php

namespace App\Platform\Connections\Jobs;

use App\Platform\Audit\AuditLogger;
use App\Platform\Connections\ConnectionDelivery;
use App\Platform\Connections\ConnectionKinds;
use App\Platform\Connections\Models\Connection;
use App\Platform\Connections\Models\ConnectionMessage;
use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\Notifier;
use App\Platform\Notifications\Services\Recipients;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Databases\TenantDatabases;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Carries one message across a business connection: checks the connection
 * is still active and the kind's module still on at the receiver, copies the
 * files into the receiver's own storage (checksums compared), and lets the
 * receiving module store it inside the receiver's own database. Then the
 * payload and the file list are wiped from the envelope. A message is
 * delivered at most once; a closed connection or a module switched off ends
 * it as failed, and the sender's people are told.
 */
class DeliverConnectionMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 300];

    public function __construct(public string $messageId) {}

    public function handle(ConnectionKinds $kinds, ModuleResolver $modules, TenantDatabases $databases, AuditLogger $audit): void
    {
        $message = ConnectionMessage::query()->find($this->messageId);
        if ($message === null || $message->status !== ConnectionMessage::QUEUED) {
            return;
        }
        $message->forceFill(['attempts' => $message->attempts + 1])->save();

        $connection = Connection::query()->find($message->connection_id);
        $to = Organization::query()->find($message->to_unit_id);
        $from = Organization::query()->find($message->from_unit_id);
        if ($connection === null || ! $connection->isActive() || $to === null || $from === null) {
            $this->giveUp($message, 'connection_closed');

            return;
        }
        if (! $kinds->has($message->kind) || ! $modules->isEnabled('business_network', $to) || ! $modules->isEnabled($kinds->module($message->kind), $to)) {
            $this->giveUp($message, 'kind_off');

            return;
        }

        $files = $this->copyFiles($message, $to);
        if ($files === null) {
            $this->giveUp($message, 'file_damaged');

            return;
        }

        $delivery = new ConnectionDelivery(
            messageId: $message->getKey(),
            connectionId: $connection->getKey(),
            kind: $message->kind,
            to: $to,
            fromUnitId: $from->getKey(),
            fromName: $from->displayName(),
            payload: (array) $message->payload,
            files: $files,
        );
        // The receiver's data, in the receiver's own database, with no signed-in context (never the sender's).
        $receivedAs = $this->withoutContext(fn () => $databases->within($to, fn () => $kinds->handler($message->kind)->receive($delivery)));

        $message->forceFill([
            'status' => ConnectionMessage::DELIVERED,
            'delivered_at' => now(),
            'received_as' => $receivedAs,
            'payload' => null,
            'files' => null,
            'error' => null,
        ])->save();
        $audit->record('business_network.message_received', $message, new: ['kind' => $message->kind, 'from' => $from->getKey(), 'received_as' => $receivedAs],
            organizationId: $to->getKey(), partnerId: $to->partner_id);
    }

    /** Out of tries (an error in the receiving module): failed, payload wiped, sender told. */
    public function failed(?Throwable $exception): void
    {
        $message = ConnectionMessage::query()->find($this->messageId);
        if ($message !== null && $message->status === ConnectionMessage::QUEUED) {
            $this->giveUp($message, 'delivery_failed');
        }
    }

    private function giveUp(ConnectionMessage $message, string $error): void
    {
        $message->forceFill(['status' => ConnectionMessage::FAILED, 'error' => $error, 'payload' => null, 'files' => null])->save();

        $from = Organization::query()->find($message->from_unit_id);
        if ($from === null) {
            return;
        }
        app(AuditLogger::class)->record('business_network.message_failed', $message, new: ['kind' => $message->kind, 'error' => $error],
            organizationId: $from->getKey(), partnerId: $from->partner_id);
        $people = app(Recipients::class)->holding('business_network.manage', $from);
        if ($people->isNotEmpty()) {
            $to = Organization::query()->find($message->to_unit_id);
            app(Notifier::class)->notify('business_network.message_failed', $people->values(), fn (string $locale) => [
                'organization' => $from->displayName($locale),
                'other' => (string) $to?->displayName($locale),
                'reason' => __("connections.delivery_errors.{$error}", [], $locale),
            ], $from->partner, $from);
        }
    }

    /**
     * Copy each file into the receiver's area of the private disk and compare checksums.
     *
     * @return list<array{path: string, name: string, mime: string, size: int, sha256: string}>|null  null: a file is gone or damaged.
     */
    private function copyFiles(ConnectionMessage $message, Organization $to): ?array
    {
        $disk = Storage::disk('local');
        $copied = [];
        foreach ((array) $message->files as $index => $file) {
            if (! $disk->exists($file['path'])) {
                return null;
            }
            $target = 'connections/'.$to->root_id.'/'.$message->getKey().'/'.$index.'-'.Str::slug(pathinfo($file['name'], PATHINFO_FILENAME)).'.'.Str::lower((string) pathinfo($file['name'], PATHINFO_EXTENSION));
            $disk->put($target, $disk->readStream($file['path']));
            if (hash_file('sha256', $disk->path($target)) !== $file['sha256']) {
                $disk->delete($target);

                return null;
            }
            $copied[] = [...$file, 'path' => $target];
        }

        return $copied;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutContext(callable $callback): mixed
    {
        // Run inline (sync queue) the job would see the sender's signed-in context: set it aside.
        $previous = app(CurrentContext::class);
        app()->instance(CurrentContext::class, new CurrentContext);
        try {
            return $callback();
        } finally {
            app()->instance(CurrentContext::class, $previous);
        }
    }
}
