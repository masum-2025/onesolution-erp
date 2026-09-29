<?php

namespace App\Platform\Identity\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Identity\Models\UserSession;
use App\Platform\Identity\Support\DeviceLabel;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * The person's signed-in browsers: recorded on use (at most once a minute),
 * listed on "My account", and ended from there. An ended session is refused
 * on its next request, whatever the session store is.
 */
class SessionTracker
{
    public function __construct(private AuditLogger $audit) {}

    public function isRevoked(string $sessionId): bool
    {
        return UserSession::query()->where('session_hash', UserSession::hashOf($sessionId))->whereNotNull('revoked_at')->exists();
    }

    /**
     * @param  string|null  $previousId  The session id at the start of the request: when it was
     *                                   renewed (e.g. entering a context), the same device row moves along.
     */
    public function touch(User $user, Request $request, ?string $previousId = null): void
    {
        $hash = UserSession::hashOf($request->session()->getId());
        $row = UserSession::query()->where('session_hash', $hash)->first();

        if ($row === null && $previousId !== null && $previousId !== $request->session()->getId()) {
            $row = UserSession::query()->where('session_hash', UserSession::hashOf($previousId))->where('user_id', $user->getKey())->whereNull('revoked_at')->first();
            $row?->forceFill(['session_hash' => $hash]);
        }

        if ($row !== null && ($row->user_id !== $user->getKey() || $row->revoked_at !== null)) {
            return;
        }

        if ($row !== null && ! $row->isDirty('session_hash') && $row->last_seen_at->gt(now()->subMinute())) {
            return;
        }

        $device = DeviceLabel::from($request->userAgent());
        $row ??= (new UserSession)->forceFill(['user_id' => $user->getKey(), 'session_hash' => $hash]);
        $row->forceFill([
            'browser' => $device['browser'],
            'platform' => $device['platform'],
            'ip' => $request->ip(),
            'last_seen_at' => now(),
        ])->save();
    }

    /**
     * @return Collection<int, UserSession>
     */
    public function active(User $user): Collection
    {
        return UserSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->where('last_seen_at', '>=', now()->subMinutes((int) config('session.lifetime', 120) * 12))
            ->orderByDesc('last_seen_at')
            ->get();
    }

    public function end(User $user, string $id): bool
    {
        $ended = UserSession::query()->where('user_id', $user->getKey())->whereKey($id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        if ($ended > 0) {
            $this->audit->record(action: 'identity.session_ended', target: $user, new: ['session' => $id], actor: $user);
        }

        return $ended > 0;
    }

    /**
     * Ends every session of the person, except the given one.
     */
    public function endAll(User $user, ?string $exceptSessionId = null): int
    {
        return UserSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->when($exceptSessionId !== null, fn ($query) => $query->where('session_hash', '!=', UserSession::hashOf($exceptSessionId)))
            ->update(['revoked_at' => now()]);
    }

    public function endCurrent(Request $request): void
    {
        if ($request->hasSession()) {
            UserSession::query()->where('session_hash', UserSession::hashOf($request->session()->getId()))->update(['revoked_at' => now()]);
        }
    }
}
