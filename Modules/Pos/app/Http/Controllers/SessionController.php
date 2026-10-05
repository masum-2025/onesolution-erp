<?php

namespace Modules\Pos\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Pos\Exceptions\PosException;
use Modules\Pos\Http\Controllers\Concerns\FindsPos;
use Modules\Pos\Http\PosPresenter;
use Modules\Pos\Http\Requests\PosRequest;
use Modules\Pos\Models\Register;
use Modules\Pos\Models\Session;
use Modules\Pos\Services\Sessions;
use Modules\Pos\Services\Tills;

/**
 * Shifts: opened and closed by a cashier (pos.sell at the counter's
 * branch), a difference beyond the rule reviewed by a supervisor
 * (pos.supervise, not who closed it), read with the Z report (pos.view or
 * pos.sell).
 */
class SessionController extends Controller
{
    use FindsPos;

    public function __construct(private Tills $tills, private Sessions $sessions, private PosPresenter $presenter) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::any(['pos.view', 'pos.sell'], $unit), 403);
        $filters = $request->validate(['register_id' => ['nullable', 'string', 'max:26'], 'status' => ['nullable', 'in:open,pending_review,closed']]);
        $query = $this->tills->query(Session::class, $company)->whereIn('register_id', $this->registerIds($unit, $company))->orderByDesc('opened_at')->limit(200);
        foreach (['register_id', 'status'] as $key) {
            if (! empty($filters[$key])) {
                $query->where($key, $filters[$key]);
            }
        }

        return response()->json(['data' => $query->get()->map(fn (Session $session) => $this->presenter->session($session))->values()]);
    }

    public function open(PosRequest $request, string $organization, string $register): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->registerIn($unit, $company, $register);
        Gate::authorize('pos.sell', $this->unitOf($found->unit_id));

        return response()->json(['data' => $this->full($company, $this->sessions->open($company, $found, (int) $request->validated('opening_float_minor'), $request->user()))], 201);
    }

    public function show(string $organization, string $session): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        abort_unless(Gate::any(['pos.view', 'pos.sell'], $unit), 403);

        return response()->json(['data' => $this->full($company, $this->sessionIn($unit, $company, $session))]);
    }

    public function close(PosRequest $request, string $organization, string $session): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->sessionIn($unit, $company, $session);
        Gate::authorize('pos.sell', $this->unitOf($this->registerIn($unit, $company, $found->register_id)->unit_id));
        $data = $request->validated();

        return response()->json(['data' => $this->full($company, $this->sessions->close($company, $found, (int) $data['base_version'], (int) $data['counted_cash_minor'], $data['note'] ?? null, $request->user()))]);
    }

    public function review(PosRequest $request, string $organization, string $session): JsonResponse
    {
        [$unit, $company] = $this->workplace($organization);
        $found = $this->sessionIn($unit, $company, $session);
        Gate::authorize('pos.supervise', $this->unitOf($this->registerIn($unit, $company, $found->register_id)->unit_id));
        $data = $request->validated();

        return response()->json(['data' => $this->full($company, $this->sessions->review($company, $found, (int) $data['base_version'], $data['note'], $request->user()))]);
    }

    private function sessionIn(Organization $unit, Organization $company, string $id): Session
    {
        $session = $this->tills->query(Session::class, $company)->whereKey($id)->first();
        if ($session === null || ! in_array($session->register_id, $this->registerIds($unit, $company), true)) {
            throw PosException::notFound('session');
        }

        return $session;
    }

    /**
     * @return array<string, mixed>
     */
    private function full(Organization $company, Session $session): array
    {
        $unit = $this->unitOf($this->tills->query(Register::class, $company)->findOrFail($session->register_id)->unit_id);
        $mine = in_array(auth()->id(), [$session->opened_by, $session->closed_by], true);

        return $this->presenter->session($session, [
            ...$this->sessions->report($company, $session),
            'expected_cash_minor' => $session->status === Session::OPEN ? $this->sessions->expectedCash($company, $session) : $session->expected_cash_minor,
        ], [
            'sell' => $session->status === Session::OPEN && Gate::allows('pos.sell', $unit),
            'close' => $session->status === Session::OPEN && Gate::allows('pos.sell', $unit),
            'review' => $session->status === Session::PENDING && ! $mine && Gate::allows('pos.supervise', $unit),
        ]);
    }
}
