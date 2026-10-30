<?php

namespace Modules\Projects\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\Projects\Http\Controllers\Concerns\FindsWorkplace;
use Modules\Projects\Http\Requests\AcceptRequest;
use Modules\Projects\Http\Requests\DeclineRequest;
use Modules\Projects\Http\Requests\ResultRequest;
use Modules\Projects\Models\IncomingRequest;
use Modules\Projects\Services\Inbox;
use Modules\Projects\Services\ProjectBook;

/**
 * Renewal requests from connected clients, and results sent back (optional:
 * only over a business connection). Reading needs projects.view; accepting,
 * declining and sending results projects.manage at the company that got them.
 */
class InboxController extends Controller
{
    use FindsWorkplace;

    public function __construct(private Inbox $inbox, private ProjectBook $projects) {}

    public function index(Request $request, string $organization): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');

        return response()->json(['data' => $this->inbox->list($unit, $request->boolean('new'))
            ->map(fn (IncomingRequest $item) => $this->shape($item, $unit))->values()]);
    }

    public function accept(AcceptRequest $request, string $organization, string $incoming): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');
        $found = $this->inbox->find($unit, $incoming);
        $this->writable($found->organization_id, 'projects.manage');
        $project = $this->inbox->accept($found, $request->validated(), $request->user());

        return response()->json(['data' => ['project_id' => $project->getKey(), 'code' => $project->code]], 201);
    }

    public function decline(DeclineRequest $request, string $organization, string $incoming): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');
        $found = $this->inbox->find($unit, $incoming);
        $this->writable($found->organization_id, 'projects.manage');
        $declined = $this->inbox->decline($found, $request->validated('reason'), $request->user());

        return response()->json(['data' => $this->shape($declined, $unit)]);
    }

    /** The audit's result to the connected client, with the certificate file. */
    public function result(ResultRequest $request, string $organization, string $project): JsonResponse
    {
        $unit = $this->unit($organization, 'projects.view');
        $found = $this->projects->find($unit, $project);
        $this->writable($found->organization_id, 'projects.manage');
        $this->inbox->sendResult($found, $request->safe()->except('file'), $request->file('file'), $request->user());

        return response()->json(['data' => ['sent' => true]], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(IncomingRequest $item, Organization $unit): array
    {
        $writes = app(CurrentContext::class)->canWriteTo($item->organization_id);
        $template = $item->status === IncomingRequest::NEW ? $this->inbox->templateFor($unit, $item) : null;

        return [
            'id' => $item->getKey(),
            'client' => $item->client_name,
            'factory' => $item->factory_name,
            'service' => ['code' => $item->service_code, 'name' => $item->serviceName()],
            'expires_on' => $item->expires_on?->toDateString(),
            'wanted_by' => $item->wanted_by?->toDateString(),
            'note' => $item->note,
            'requested_by' => $item->requested_by,
            'status' => $item->status,
            'reason' => $item->reason,
            'project_id' => $item->project_id,
            // The template the project would start from (its service matches).
            'template_id' => $template?->getKey(),
            'created_at' => $item->created_at?->toIso8601String(),
            'can' => ['decide' => $writes && $item->status === IncomingRequest::NEW && Gate::allows('projects.manage', $unit)],
        ];
    }
}
