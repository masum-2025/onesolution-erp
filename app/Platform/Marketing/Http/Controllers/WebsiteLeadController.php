<?php

namespace App\Platform\Marketing\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Marketing\Http\Requests\UpdateLeadRequest;
use App\Platform\Marketing\Models\Lead;
use App\Platform\Marketing\Models\Site;
use App\Platform\Marketing\Services\Leads;
use App\Platform\Marketing\Services\Websites;
use App\Platform\Partners\Http\Controllers\Concerns\PartnerConsole;
use App\Platform\Support\Http\PerPage;
use App\Platform\Tenancy\Enums\PartnerUserRole;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Partner console: messages from the website's contact form. They hold a
 * visitor's phone and email, so only owners and sales staff see them;
 * sales staff follow up, owners may also remove one. Only the partner's own
 * site, from the context.
 */
class WebsiteLeadController extends Controller
{
    use PartnerConsole;

    private const READERS = [PartnerUserRole::Owner, PartnerUserRole::Sales];

    public function __construct(private Websites $websites, private Leads $leads) {}

    public function index(Request $request): JsonResponse
    {
        $this->requireRole(...self::READERS);
        $filters = $request->validate([
            'status' => ['nullable', Rule::in(Lead::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $site = $this->site();
        $query = Lead::query()->where('site_id', $site->getKey())
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['q'] ?? null, fn ($query, $q) => $query->where(fn ($inner) => $inner
                ->where('name', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('phone', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('email', 'like', '%'.addcslashes($q, '%_\\').'%')
                ->orWhere('company', 'like', '%'.addcslashes($q, '%_\\').'%')))
            ->orderByDesc('created_at')->orderByDesc('id');
        $rows = $query->paginate(PerPage::from($request, 50));
        // A few statuses: one small count each (no raw SQL).
        $counts = collect(Lead::STATUSES)->mapWithKeys(fn ($status) => [$status => Lead::query()->where('site_id', $site->getKey())->where('status', $status)->count()]);

        return response()->json([
            'data' => collect($rows->items())->map(fn (Lead $lead) => $this->row($lead))->values(),
            'meta' => [
                'total' => $rows->total(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(),
                'counts' => $counts,
                'statuses' => Lead::STATUSES,
                'can_remove' => $this->hasRole(PartnerUserRole::Owner),
            ],
        ]);
    }

    public function update(UpdateLeadRequest $request, string $lead): JsonResponse
    {
        $this->requireRole(...self::READERS);
        $data = $request->validated();
        $row = $this->leads->update($this->leads->find($this->site(), $lead), $data, (int) $data['base_version'], $request->user());

        return response()->json(['data' => $this->row($row), 'message' => __('marketing.messages.saved')]);
    }

    public function destroy(Request $request, string $lead): JsonResponse
    {
        $this->requireRole(PartnerUserRole::Owner);
        $site = $this->site();
        $this->leads->remove($site, $this->leads->find($site, $lead), $request->user());

        return response()->json(['message' => __('marketing.messages.deleted')]);
    }

    private function site(): Site
    {
        return $this->websites->require($this->partner());
    }

    /** @return array<string, mixed> */
    private function row(Lead $lead): array
    {
        return [
            'id' => $lead->getKey(), 'name' => $lead->name, 'phone' => $lead->phone, 'email' => $lead->email,
            'company' => $lead->company, 'message' => $lead->message, 'page_slug' => $lead->page_slug, 'locale' => $lead->locale,
            'status' => $lead->status, 'note' => $lead->note, 'version' => $lead->version,
            'created_at' => $lead->created_at?->toIso8601String(), 'updated_at' => $lead->updated_at?->toIso8601String(),
        ];
    }
}
