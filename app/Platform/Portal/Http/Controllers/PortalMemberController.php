<?php

namespace App\Platform\Portal\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\Exceptions\PortalException;
use App\Platform\Portal\Services\PortalRecords;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Enums\MembershipType;
use Illuminate\Http\JsonResponse;

/**
 * A portal member's own view (Phase 5C-4): the records linked to them, and
 * nothing else. Anything that is not theirs is simply not found.
 */
class PortalMemberController extends Controller
{
    public function __construct(private CurrentContext $context, private PortalRecords $records) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'organization' => $this->context->organization()->displayName(),
            'records' => $this->records->list($this->membership()),
        ]]);
    }

    public function show(string $link): JsonResponse
    {
        return response()->json(['data' => $this->records->show($this->membership(), $link) ?? throw PortalException::linkNotFound()]);
    }

    private function membership()
    {
        $membership = $this->context->membership();

        // Staff see records through their modules and roles, not through links.
        if ($membership->membership_type !== MembershipType::Portal) {
            throw PortalException::linkNotFound();
        }

        return $membership->loadMissing('organization');
    }
}
