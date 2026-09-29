<?php

namespace Tests\Fixtures;

use App\Platform\Offline\Contracts\SyncableRecords;
use App\Platform\Offline\SyncOperation;
use App\Platform\Offline\SyncResult;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/**
 * How a module would let people change its notes offline: validated like a
 * request, versioned (stale = conflict), deletions reported in changes().
 */
class FixtureNoteSync implements SyncableRecords
{
    public function key(): string
    {
        return 'crm.note';
    }

    public function isMoney(): bool
    {
        return false;
    }

    public function permission(string $action): string
    {
        return 'crm.manage';
    }

    public function rules(): array
    {
        return [];
    }

    public function apply(SyncOperation $operation): SyncResult
    {
        if ($operation->action === SyncOperation::CREATE) {
            $data = Validator::make($operation->data, ['title' => ['required', 'string', 'max:100']])->validate();
            $note = FixtureSyncNote::query()->create(['title' => $data['title'], 'version' => 1]);

            return SyncResult::applied($note->getKey(), 1);
        }

        // The tenant scope applies: another organization's note is simply not found.
        $note = FixtureSyncNote::query()->whereKey($operation->recordId)->lockForUpdate()->first();
        if ($note === null) {
            return SyncResult::rejected('not_found');
        }

        if ($operation->baseVersion !== $note->version) {
            return SyncResult::conflict($note->getKey(), $note->version, ['title' => $note->title]);
        }

        if ($operation->action === SyncOperation::DELETE) {
            $note->forceFill(['version' => $note->version + 1])->save();
            $note->delete();

            return SyncResult::applied($note->getKey(), $note->version);
        }

        $data = Validator::make($operation->data, ['title' => ['required', 'string', 'max:100']])->validate();
        $note->forceFill(['title' => $data['title'], 'version' => $note->version + 1])->save();

        return SyncResult::applied($note->getKey(), $note->version);
    }

    public function changes(Organization $organization, ?CarbonImmutable $since, int $limit): array
    {
        $notes = FixtureSyncNote::query()->withTrashed()
            ->when($since !== null, fn ($query) => $query->where('updated_at', '>', $since))
            ->orderBy('updated_at')->limit($limit)->get();

        return [
            'records' => $notes->whereNull('deleted_at')->map(fn (FixtureSyncNote $note) => ['id' => $note->getKey(), 'title' => $note->title, 'version' => $note->version])->values()->all(),
            'deleted' => $notes->whereNotNull('deleted_at')->pluck('id')->values()->all(),
        ];
    }
}
