<?php

use App\Platform\Audit\AuditLog;
use App\Platform\Tenancy\Actions\CreateOrganization;
use App\Platform\Tenancy\Enums\OrganizationType;
use App\Platform\Tenancy\Exceptions\HierarchyViolation;
use App\Platform\Tenancy\Exceptions\OrganizationChangeForbidden;
use App\Platform\Tenancy\Models\Organization;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Tenancy\Services\HierarchyService;

it('builds group > company > branch > department with correct paths', function () {
    $partner = Partner::factory()->create();

    $group = createGroup($partner);
    $company = createChild($group, OrganizationType::Company, 'Company');
    $branch = createChild($company, OrganizationType::Branch, 'Branch');
    $department = createChild($branch, OrganizationType::Department, 'Department');

    expect($group->path)->toBe("/{$group->id}/")
        ->and($group->depth)->toBe(0)
        ->and($group->root_id)->toBe($group->id)
        ->and($company->path)->toBe("/{$group->id}/{$company->id}/")
        ->and($branch->path)->toBe("/{$group->id}/{$company->id}/{$branch->id}/")
        ->and($department->path)->toBe("/{$group->id}/{$company->id}/{$branch->id}/{$department->id}/")
        ->and($department->depth)->toBe(3)
        ->and($department->root_id)->toBe($group->id)
        ->and($department->partner_id)->toBe($partner->id);

    expect(AuditLog::where('action', 'organization.created')->count())->toBe(4);
});

it('allows a department directly under a company', function () {
    $company = createChild(createGroup(Partner::factory()->create()), OrganizationType::Company, 'Company');

    $department = createChild($company, OrganizationType::Department, 'Department');

    expect($department->parent_id)->toBe($company->id);
});

it('rejects invalid parent types', function (OrganizationType $type, ?string $parentType) {
    $group = createGroup(Partner::factory()->create());
    $company = createChild($group, OrganizationType::Company, 'Company');
    $parent = match ($parentType) {
        'group' => $group,
        'company' => $company,
        default => null,
    };

    app(CreateOrganization::class)->handle(
        $type,
        ['name' => ['en' => 'X'], 'sector_key' => 'school'],
        parent: $parent,
        partner: $group->partner,
    );
})->with([
    'company at root' => [OrganizationType::Company, null],
    'branch under group' => [OrganizationType::Branch, 'group'],
    'group under company' => [OrganizationType::Group, 'company'],
    'company under company' => [OrganizationType::Company, 'company'],
])->throws(HierarchyViolation::class);

it('finds ancestors and descendants', function () {
    $w = tenancyWorld();
    $hierarchy = app(HierarchyService::class);

    expect($hierarchy->ancestors($w->d1)->pluck('id')->all())->toBe([$w->g1->id, $w->c1->id, $w->b1->id])
        ->and($hierarchy->descendants($w->c1)->pluck('id')->all())->toBe([$w->b1->id, $w->d1->id])
        ->and($hierarchy->isAncestorOf($w->g1, $w->d1))->toBeTrue()
        ->and($hierarchy->isAncestorOf($w->c2, $w->d1))->toBeFalse()
        ->and($hierarchy->isAncestorOf($w->d1, $w->d1))->toBeFalse();
});

it('moves a branch with its subtree to another company and audits it', function () {
    $w = tenancyWorld();

    app(HierarchyService::class)->move($w->b1, $w->c2, 'Campus handed over to C2');

    $branch = $w->b1->fresh();
    $department = $w->d1->fresh();

    expect($branch->parent_id)->toBe($w->c2->id)
        ->and($branch->path)->toBe("/{$w->g1->id}/{$w->c2->id}/{$w->b1->id}/")
        ->and($department->path)->toBe("/{$w->g1->id}/{$w->c2->id}/{$w->b1->id}/{$w->d1->id}/")
        ->and($department->depth)->toBe(3);

    $audit = AuditLog::where('action', 'organization.moved')->sole();
    expect($audit->target_id)->toBe($w->b1->id)
        ->and($audit->reason)->toBe('Campus handed over to C2')
        ->and($audit->old_values['parent_id'])->toBe($w->c1->id)
        ->and($audit->new_values['parent_id'])->toBe($w->c2->id)
        ->and($audit->new_values['descendants_moved'])->toBe(1);
});

it('updates root ids when a company moves to another group', function () {
    $w = tenancyWorld();

    app(HierarchyService::class)->move($w->c1, $w->g2, 'Regrouping');

    expect($w->d1->fresh()->root_id)->toBe($w->g2->id)
        ->and($w->d1->fresh()->path)->toStartWith("/{$w->g2->id}/{$w->c1->id}/");
});

it('rejects moves that would create a cycle', function () {
    $w = tenancyWorld();

    app(HierarchyService::class)->move($w->b1, $w->d1, 'Cycle attempt');
})->throws(HierarchyViolation::class);

it('rejects moving to another partner', function () {
    $w = tenancyWorld();

    app(HierarchyService::class)->move($w->c1, $w->g3, 'Wrong partner');
})->throws(HierarchyViolation::class);

it('rejects moving to the current parent', function () {
    $w = tenancyWorld();

    app(HierarchyService::class)->move($w->b1, $w->c1, 'Same parent');
})->throws(HierarchyViolation::class);

it('rejects moves that break parent type rules', function () {
    $w = tenancyWorld();

    app(HierarchyService::class)->move($w->b1, $w->g2, 'Branch under group');
})->throws(HierarchyViolation::class);

it('rejects trees deeper than the configured maximum', function () {
    config(['tenancy.max_depth' => 2]);
    $company = createChild(createGroup(Partner::factory()->create()), OrganizationType::Company, 'Company');
    $branch = createChild($company, OrganizationType::Branch, 'Branch');

    createChild($branch, OrganizationType::Department, 'Too deep');
})->throws(HierarchyViolation::class);

it('refuses tree changes outside of move', function (string $column) {
    $w = tenancyWorld();
    $value = match ($column) {
        'parent_id' => $w->c2->id,
        'path' => $w->c2->path.$w->b1->id.'/',
        'root_id' => $w->g2->id,
        'partner_id' => $w->partnerB->id,
    };

    $w->b1->forceFill([$column => $value])->save();
})->with(['parent_id', 'path', 'root_id', 'partner_id'])->throws(OrganizationChangeForbidden::class);

it('keeps the tree unchanged when a move fails', function () {
    $w = tenancyWorld();
    $before = Organization::orderBy('id')->pluck('path', 'id')->all();

    try {
        app(HierarchyService::class)->move($w->c1, $w->g3, 'Wrong partner');
    } catch (HierarchyViolation) {
    }

    expect(Organization::orderBy('id')->pluck('path', 'id')->all())->toBe($before);
});
