<?php

namespace Modules\Compliance\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Portal\PortalAccess;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Context\CurrentContext;
use App\Platform\Tenancy\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Modules\Compliance\Exceptions\ComplianceException;
use Modules\Compliance\Models\Audit;
use Modules\Compliance\Portal\FactorySubjects;
use Modules\Compliance\Services\Place;
use Modules\Compliance\Services\Register;
use Modules\Compliance\Support\Validity;

/**
 * A factory's audit register in the client's portal (B2B2C): only for the
 * factories the client linked to the member (a company with its branches),
 * read only. Services, kinds, providers, dates, days left, state, result and
 * (by rule) certificate numbers; never notes, renewals, quotations, invoices
 * or the people who changed things. Anything else is the same 404.
 */
class PortalRegisterController extends Controller
{
    public function __construct(
        private Place $place,
        private Register $register,
        private PortalAccess $portal,
        private CurrentContext $context,
        private RuleResolver $rules,
        private RuleContextFactory $contexts,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $request->validate(['factory' => ['nullable', 'string', 'size:26']]);
        $organization = $this->context->organization();
        $linked = $this->linked($organization);
        if ($request->filled('factory')) {
            $linked = $linked->where('id', $request->string('factory')->toString())->values();
            if ($linked->isEmpty()) {
                throw ComplianceException::portalFactoryNotFound();
            }
        }

        $expiring = $this->register->expiringDays($organization);
        $certificates = (bool) $this->rules->get('compliance.portal_shows_certificate_no', $this->contexts->forOrganization($organization));
        $todays = [];

        return response()->json(['data' => [
            'organization' => $organization->displayName(),
            'expiring_days' => $expiring,
            'factories' => $linked->map(function (Organization $linkedUnit) use ($expiring, $certificates, &$todays) {
                // A linked company shows its branches too, each as its own factory.
                $factories = $this->place->factories($linkedUnit);
                $lines = $this->register->withLists($this->place->query(Audit::class, $linkedUnit, $factories->pluck('id')->map(fn ($id) => (string) $id)->all())->get());

                return [
                    'id' => $linkedUnit->getKey(),
                    'name' => $linkedUnit->displayName(),
                    'units' => $factories->map(function (Organization $factory) use ($lines, $expiring, $certificates, &$todays) {
                        $today = $todays[$factory->getKey()] ??= $this->place->today($this->place->companyOf($factory));
                        $mine = $lines->where('organization_id', $factory->getKey())
                            ->map(fn (Audit $audit) => $this->line($audit, $today, $expiring, $certificates))
                            ->sortBy(fn (array $line) => $line['days_left'] ?? PHP_INT_MAX)->values();

                        return [
                            'id' => $factory->getKey(),
                            'name' => $factory->displayName(),
                            'type' => $factory->type->value,
                            'summary' => collect(Validity::STATES)->mapWithKeys(fn (string $state) => [$state => $mine->where('state', $state)->count()]),
                            'lines' => $mine,
                        ];
                    })->values(),
                ];
            })->values(),
        ]]);
    }

    /**
     * The open factories the member is linked to, inside the portal's organization;
     * nothing for staff (who use the register).
     *
     * @return Collection<int, Organization>
     */
    private function linked(Organization $organization): Collection
    {
        if (! $this->portal->isPortal()) {
            return collect();
        }
        $ids = $this->portal->subjectIds(FactorySubjects::KEY);

        return $this->place->factories($organization)->whereIn('id', $ids)->values();
    }

    /**
     * @return array<string, mixed>
     */
    private function line(Audit $audit, CarbonImmutable $today, int $expiring, bool $certificates): array
    {
        return [
            'id' => $audit->getKey(),
            'service' => $audit->service === null ? null : ['code' => $audit->service->code, 'name' => $audit->service->textIn('name')],
            'audit_type' => $audit->auditType?->textIn('name'),
            'provider' => $audit->provider?->name,
            'last_audit_on' => $audit->last_audit_on?->toDateString(),
            'expires_on' => $audit->expires_on?->toDateString(),
            'days_left' => Validity::daysLeft($audit->expires_on, $today),
            'state' => Validity::state($audit->expires_on, $today, $expiring),
            'result' => $audit->result,
            'certificate_no' => $certificates ? $audit->certificate_no : null,
        ];
    }
}
