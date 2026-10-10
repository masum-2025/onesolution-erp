<?php

namespace App\Platform\Portal\Services;

use App\Platform\Portal\Contracts\PortalSubjectPage;
use App\Platform\Portal\Models\PortalLink;
use App\Platform\Portal\PortalSubject;
use App\Platform\Portal\PortalSubjects;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\OrganizationMembership;

/**
 * What a portal member sees: the records of their active links, as each
 * module describes them, without the fields the client hides (rule
 * client_portal.hidden_fields). A kind whose module is off shows nothing.
 */
class PortalRecords
{
    public function __construct(
        private PortalSubjects $subjects,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(OrganizationMembership $membership): array
    {
        $organization = $membership->organization;
        $usable = $this->subjects->available($organization);

        return PortalLink::query()
            ->where('membership_id', $membership->getKey())
            ->where('organization_id', $organization->getKey())
            ->whereIn('status', [PortalLink::ACTIVE, PortalLink::PENDING])
            ->orderBy('created_at')
            ->get()
            ->filter(fn (PortalLink $link) => in_array($link->subject_type, $usable, true))
            ->map(function (PortalLink $link) use ($organization) {
                $subject = $link->isActive() ? $this->subjects->provider($link->subject_type)->find($organization, $link->subject_id) : null;

                return [
                    'id' => $link->getKey(),
                    'status' => $link->status,
                    'kind' => $this->subjects->provider($link->subject_type)->label(),
                    'relation' => $link->relation,
                    // Until the client approves, not even the name is shown.
                    'name' => $subject?->name,
                    'page' => $subject === null ? null : $this->page($link->subject_type, $subject),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * One record of an active link of this member; null for anything else
     * (another member's, waiting, revoked, deleted, module off).
     *
     * @return array<string, mixed>|null
     */
    public function show(OrganizationMembership $membership, string $linkId): ?array
    {
        $organization = $membership->organization;

        $link = PortalLink::query()
            ->whereKey($linkId)
            ->where('membership_id', $membership->getKey())
            ->where('organization_id', $organization->getKey())
            ->where('status', PortalLink::ACTIVE)
            ->first();

        if ($link === null || ! $this->subjects->usable($link->subject_type, $organization)) {
            return null;
        }

        $provider = $this->subjects->provider($link->subject_type);
        $subject = $provider->find($organization, $link->subject_id);
        if ($subject === null) {
            return null;
        }

        $hidden = $this->hidden($organization, $link->subject_type);
        $fields = [];
        foreach ($provider->details($subject) as $key => $field) {
            if (! in_array($key, $hidden, true)) {
                $fields[] = ['key' => $key, 'label' => $field['label'], 'value' => $field['value']];
            }
        }

        return [
            'id' => $link->getKey(),
            'kind' => $provider->label(),
            'relation' => $link->relation,
            'name' => $subject->name,
            'fields' => $fields,
            'page' => $this->page($link->subject_type, $subject),
            'pages' => $this->subjects->pages($link->subject_type, $organization, $link->getKey()),
            'online_payment' => (bool) $this->rules->get('client_portal.allow_online_payment', $this->contexts->forOrganization($organization)),
        ];
    }

    /**
     * @return list<string>
     */
    private function hidden(Organization $organization, string $type): array
    {
        $hidden = [];
        foreach ((array) $this->rules->get('client_portal.hidden_fields', $this->contexts->forOrganization($organization)) as $entry) {
            if (is_string($entry) && str_starts_with($entry, $type.'.')) {
                $hidden[] = substr($entry, strlen($type) + 1);
            }
        }

        return $hidden;
    }

    /** The module's own portal screen for the record, when it has one. */
    private function page(string $type, PortalSubject $subject): ?string
    {
        $provider = $this->subjects->provider($type);

        return $provider instanceof PortalSubjectPage ? $provider->portalPath($subject) : null;
    }
}
