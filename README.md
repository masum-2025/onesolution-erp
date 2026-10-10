# One Solutions Platform

Multi-tenant, multi-organization, multi-sector, multi-country SaaS ERP.
Sold B2B (direct and white-label through partners) and B2C.

- Rules for all code: [CLAUDE.md](CLAUDE.md)
- Phase-by-phase build plan: [docs/saas-build-prompts.md](docs/saas-build-prompts.md)

Stack: Laravel 13, PHP 8.3+, MySQL 8 (portable to PostgreSQL), Sanctum, Pest.

## Local setup (Laragon)

```bash
composer install
cp .env.example .env
php artisan key:generate
# create databases onesolution_erp and onesolution_erp_test (utf8mb4_unicode_ci)
php artisan migrate --seed      # in APP_ENV=local also seeds a demo tree and prints demo logins
php artisan test                # runs on onesolution_erp_test (see phpunit.xml)
vendor/bin/pint                 # code style
```

Demo data (local only, only adds, skips what exists): a school tree under the house partner,
and a white-label partner **Acme Solutions** (`DemoPartnerSeeder`) at
`http://erp.acme.localhost:8000` (owner `owner@acme.test`) with a client **Sunrise School**
at `http://sunrise.acme.localhost:8000` (owner `head@sunrise.test`, own sub-brand). Add it
to an existing database with `php artisan db:seed --class=DemoPartnerSeeder`. For sign-in on
those addresses, list them in `SANCTUM_STATEFUL_DOMAINS` (see `.env.example`).

CI (`.github/workflows/tests.yml`) runs the suite on MySQL 8 and PostgreSQL 16.

## Tenancy foundation (Phase 1)

Code: `app/Platform/Tenancy`, `app/Platform/Audit`. Config: `config/tenancy.php`.

### Hierarchy

```
Partner (house or white-label reseller)
 └─ Group ─ Company ─ Branch ─ Department      (table: organizations)
```

- Every organization has exactly one `partner_id`, copied from its parent and never
  editable (a platform "transfer client" action comes later).
- Tree columns: `parent_id`, `root_id` (top group), `path` (`/{root}/{…}/{id}/`), `depth`.
  All subtree queries use `path LIKE 'prefix%'` — no recursive or vendor SQL.
- Allowed parent types and max depth are rules: `tenancy.allowed_parents`, `tenancy.max_depth`
  (platform / partner level).
- `country_code`, `default_locale`, `timezone`, `currency_code`, `region`: `null` means
  inherit from the nearest ancestor, then `config('tenancy.defaults')`.
  `GET /api/organizations/{id}/settings` shows each value and where it comes from.
- Tree position changes only through `HierarchyService::move()` (cycle-safe, same
  partner, rebuilds the subtree, audited with a reason). Any other write to tree
  columns throws.

### Identity, memberships and context

- One `users` row per person. Access goes through memberships:
  `organization_user` (client area: `owner|staff|portal`, `access_scope own|descendants`)
  or `partner_users` (partner console: `owner|sales|support|billing`).
- Flow: `POST /api/auth/login` → token with **no** context + list of contexts →
  `POST /api/auth/context {organization_id | partner_id}` → verified, stored **on the
  token**, old token revoked. Every later request reads the context only from the token
  (`org` / `partner` middleware) and re-checks membership, organization and partner status.
- `CurrentContext` (scoped singleton) holds user, partner, organization, company, group,
  ancestors, locale, country, region and the visible subtree.

### Visibility

| membership at | reads | writes (tenant-scoped data) |
|---|---|---|
| company / branch / department | whole company subtree | whole company subtree |
| group, `access_scope=descendants` | all companies of the group | group node only (read-only aggregate) |
| group, `access_scope=own` | group node only | group node only |
| partner console | own clients' organization metadata only | — |

Reading is company-wide; changes reach only the member's own unit and below and need a
permission (see "Roles and permissions"). Portal members never read the structure.
Out-of-scope ids always return **404**, never 403, so ids cannot be probed.

### Making a model tenant-scoped

```php
use App\Platform\Tenancy\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class Invoice extends Model
{
    use BelongsToOrganization, HasUlids;
}
```

The table needs `organization_id` (`foreignUlid`). The trait adds a global scope,
fills `organization_id` from the context on create, refuses any change to it, and
refuses writes outside the writable subtree. Without a context, queries throw
`MissingTenantContext` (fail closed). Trusted system code opts out explicitly with
`withoutGlobalScope(OrganizationScope::class)`.
`tests/Feature/Architecture/TenantModelsTest` fails if a new model skips the trait
or ULIDs, or if raw SQL appears in `app/`.

### API

| method | path | notes |
|---|---|---|
| POST | /api/auth/login | throttled per email + IP |
| POST | /api/auth/context | throttled |
| POST | /api/auth/logout | |
| GET/POST | /api/organizations | list visible (owner/staff) / create child (`organizations.manage`) |
| GET/PATCH | /api/organizations/{id} | tree fields rejected with "use move" |
| GET | /api/organizations/{id}/settings | effective values + source |
| POST | /api/organizations/{id}/move | `{new_parent_id, reason}`, `organizations.move` on both, throttled |
| GET/POST | /api/organizations/{id}/members | `members.manage`; adding an owner: owners only |
| PATCH | /api/organizations/{id}/members/{membershipId} | `members.manage`, not own membership; owner changes: owners only |
| GET | /api/partner/organizations(/{id}) | partner console, metadata only |

All inputs use Form Requests that reject unknown fields. Errors carry a translated
`message` (en, bn) and a stable `code`.

### Audit

`audit_logs` is append-only (model refuses update/delete). Phase 1 records:
organization created/updated/moved, membership added/changed, login, failed login,
context entered.

### Tests

`tests/Feature/Tenancy/*` cover every Phase 1 acceptance criterion: path building,
IDOR on every endpoint (using the `Tests\Fixtures\TenantNote` stand-in model), group
visibility, rejected organization changes, audited moves, partner isolation and no
partner access to client data.

### Future expansion (Phase 1)

- New sector, country or partner: data only (a sector package entry, organization country
  fields, a `partners` row). No code change.
- New parent rule for a partner: set `tenancy.allowed_parents` at partner level (data).
- Authorization is permission-based since Phase 4 (roles live in `membership_roles`).
- Maker-checker for moves and support access for partners: Phase 3 and Phase 5B.

## Module system (Phase 2)

Code: `app/Platform/Modules`. Module folders: `Modules/{Name}` (nwidart/laravel-modules).
Config: `config/platform_modules.php`. Plans: `database/seeders/data/plans.php` (Phase 5).

### Modules and manifests

Every module folder has `manifest.php` (read by `ModuleRegistry`), `module.json` +
`composer.json` (nwidart loading/autoload), a service provider and `lang/{en,bn}/module.php`.

```php
return [
    'key' => 'payroll',
    'name' => 'payroll::module.name',          // translation key
    'requires' => ['hrm', 'attendance'],
    'sectors' => ['*'],                         // or ['factory', ...]
    'plans' => ['*'],                           // or ['business', 'enterprise']
    'permissions' => ['payroll.view', 'payroll.run', 'payroll.approve'],
    'rules' => [],                              // Phase 3
    'menu' => [[
        'key' => 'payroll', 'label' => 'payroll::module.menu', 'route' => '/payroll', 'order' => 30,
        'icon' => 'banknote',                   // name from resources/js/lib/icons.js
        'section' => 'people',                  // sidebar section; default = the module's category
        'permission' => 'payroll.view',         // optional: entry only for people holding it
        'children' => [                         // optional sub-pages (sidebar disclosure)
            ['key' => 'runs', 'label' => 'payroll::module.menu_runs', 'route' => '/payroll', 'permission' => 'payroll.view'],
        ],
    ]],
    // Header "New" menu; a permission of this module is required.
    'quick_actions' => [['key' => 'run', 'label' => 'payroll::module.new_run', 'route' => '/payroll/new', 'permission' => 'payroll.run', 'icon' => 'banknote']],
    // Header bell: AttentionProvider classes, asked only while the module is on.
    'attention' => [],
    'is_core' => false,
    'requires_consent' => false,                // true for AI modules
];
```

The registry validates every manifest at load (unknown dependency or plan, foreign
permission prefix, dependency cycle → exception) and orders modules by dependency.
nwidart's own global on/off (`modules_statuses.json`) keeps every module loaded;
per-organization on/off is ours.

Installed: hrm, attendance, payroll, accounting, inventory, crm, factory_erp (factory
sector only), offline_mode, multi_currency, multi_language, api_integration,
external_integrations, document_ai, ai_assistant, energy_monitoring, carbon_management,
advanced_audit, custom_reports (AI, sustainability and governance: business/enterprise plans).

### Resolution — `ModuleResolver::isEnabled($key, $organization)`

1. Available: the plan (`organizations.plan_key` of the top organization, default `starter`)
   includes the module **and** the manifest allows that plan, and the effective sector allows it; modules with `requires_consent` need an active consent at the
   organization or an ancestor.
2. State (`organization_modules`): the topmost ancestor **lock** wins; otherwise the nearest
   level that is not `inherit`; otherwise off (core modules: on).
3. Every required module must itself resolve on.

`GET /api/organizations/{id}/modules` shows each module's state, reason, source
organization and who locked it. Resolved maps are cached per organization; any change in
a tree bumps that tree's cache version (works on every cache store).

### Changing modules (`modules.manage` permission)

| method | path | notes |
|---|---|---|
| POST | /api/organizations/{id}/modules/{key}/enable | `{reason, lock?}`; missing dependencies are enabled too (`auto_enabled`) |
| POST | /api/organizations/{id}/modules/{key}/disable | `{reason, lock?, confirm?}`; 409 + `dependents` until `confirm=true` |
| POST | /api/organizations/{id}/modules/{key}/inherit | `{reason}`; remove this level's setting |
| POST/DELETE | /api/organizations/{id}/modules/{key}/consent | AI consent `{terms_version}` / revoke `{reason}` |
| POST/DELETE | /api/organizations/{id}/modules/{key}/purge | `{confirm_text: key, reason}`; runs after `modules.purge_delay_days` (default 7), cancellable |
| GET | /api/menu | enabled modules' menu items for the current organization |

A parent's lock is shown by name in the error. Every write is audited with its reason.
Turning a module off never deletes data; deletion is only the delayed purge
(`modules:purge-due`, scheduled daily), executed by services a module tags as
`module.purgers.{key}` (`App\Platform\Modules\Contracts\PurgesModuleData`).

### Enforcement

- Routes: `->middleware(['auth:sanctum', 'org', 'module:payroll'])` → 403 `module_disabled`.
- Menu: `GET /api/menu` lists enabled modules only.
- Jobs: `middleware()` returns `new EnsureModuleEnabledForJob('payroll', $organizationId)`;
  the job is skipped (not failed) when the module is off.
- Scheduled tasks: `ModuleScheduler::organizationsWithModule('payroll')`.

### Side effects on disable (`ModuleDisabled` / `ModuleEnabled` events, after commit)

- `api_integration` off: integration tokens (Sanctum tokens named `integration:*`) of every
  organization in the subtree where it is now off are revoked immediately.
- AI modules: off without an active consent; revoking consent turns them off.
- `offline_mode` off (block `/sync`, device wipe flag) and `external_integrations` off (stop
  webhooks, deactivate third-party keys): the events fire now; the listeners arrive with the
  offline sync (Phase 7) and integrations features, which own those tables.

### Future expansion (Phase 2)

- New module: add a folder with a manifest; no core change. New plan or sector: data files
  (see "Plans, sectors and packaging").
- Partner- and platform-level locks (Phase 5B) and `modules.manage` permission (Phase 4)
  plug into the same resolver and gate.

## Rule engine (Phase 3)

Code: `app/Platform/Rules`. Config: `config/platform_rules.php` (cache only).
Business numbers are never hardcoded: module code reads them with

```php
use App\Platform\Rules\Facades\Rules;

Rules::get('attendance.late_grace_minutes');              // current tenant, now
Rules::get('payroll.overtime_multiplier', asOf: $monthEnd); // value valid at a past date
Rules::getMany('payroll.');
```

### Defining rules

A module declares rules in its manifest (`rules`); platform rules live in
`app/Platform/Rules/core-rules.php`. Labels are translation keys
(`Modules/{Name}/lang/{en,bn}/rules.php`). A new module adds rules with no core change.

```php
[
    'key' => 'attendance.late_grace_minutes',   // must start with the module key
    'type' => 'integer',                        // boolean integer decimal string enum multi_enum
                                                // duration money time date json table
    'schema' => ['minimum' => 0, 'maximum' => 240],   // JSON Schema on top of the type
    'default' => 10,
    'overridable_levels' => ['platform', 'partner', 'plan', 'group', 'company', 'branch', 'department'],
    'requires_approval' => false, 'sensitive' => false,   // either one => maker-checker
    'country_specific' => false,
    'label' => 'attendance::rules.late_grace_minutes.label',
    'description' => 'attendance::rules.late_grace_minutes.description',
    'category' => 'lateness', 'sort_order' => 10,
]
```

Decimals are strings (`"1.5"`), money is `{"amount": <minor units>, "currency": "BDT"}`.
`php artisan rules:sync` mirrors definitions into `rule_definitions` (run on deploy; the seeder runs it).

### Values and resolution

`rule_values` rows sit at a scope (platform, partner, plan, group, company, branch,
department, role, user) with a mode: `set`, `constrain` (`{min, max, allowed}` for
descendants) or `lock` (fixed; descendants ignored). Resolution:

1. start with the default; walk platform → partner → plan → group → … → role → user,
   using only values in effect at the moment asked (`effective_from` / `effective_to`);
2. a lock stops the walk;
3. the result must satisfy every ancestor constraint — otherwise the nearest valid
   ancestor value is used (or the value is clamped to the limits) and a warning is logged;
4. country-specific rules prefer the row for the organization's country over the generic row.

`explain()` returns the full per-level trace. Results are cached per chain; cache keys carry
global / partner / tree versions and expire exactly when a future-dated value starts.
`snapshot($keys)` returns values plus a `rule_version` for offline leases (Phase 7).

### Changing values — `RuleService`

Checks, in order: level allowed for the rule, country only for country-specific rules,
module enabled at the organization, JSON Schema, no ancestor lock, inside ancestor limits
(new limits may only narrow them). `requires_approval` / `sensitive` changes stay
`pending_approval` until a **different** person holding `rules.approve` at the same organization or an ancestor
approves (self-approval is refused). Changes can be scheduled (`effective_from`), reset to
inherited (history kept, past dates still resolve), rolled back to any version, and
previewed. Every step writes `rule_value_history` (append-only) and the audit log.

| method | path | notes |
|---|---|---|
| GET | /api/organizations/{id}/rules?module= | grouped by module/category: value, source, lock, limits, own values, editable |
| GET | /api/organizations/{id}/rules/{key} | + full `trace` |
| PUT | /api/organizations/{id}/rules/{key} | `{mode, value, country_code?, effective_from?, reason}` → 200, or 202 when approval is needed |
| DELETE | /api/organizations/{id}/rules/{key} | reset to inherited `{reason, slot?: value\|constraint}` |
| POST | /api/organizations/{id}/rules/{key}/preview | organizations whose value would change |
| GET / POST | /api/organizations/{id}/rules/{key}/history, …/rollback | `{version, reason}` |
| GET | /api/organizations/{id}/rule-approvals | pending changes at this level and below |
| POST | /api/organizations/{id}/rule-approvals/{valueId}/approve \| reject | reject needs a reason |
| GET / PUT / DELETE | /api/partner/rules(/{key}) | partner level, partner owners only |
| POST | /api/partner/rule-approvals/{valueId}/approve \| reject | partner maker-checker |

Platform and plan values (no platform admin UI yet): `php artisan rules:set <key> <json> [--plan=] [--country=] [--mode=] [--effective-from=] --reason=...`
and `php artisan rules:explain <key> [--organization=] [--date=]`.

Seeded data (`database/seeders/data/rule-values.php`): Bangladesh overtime rate, weekend,
fiscal year, notice period and income tax slabs FY 2024-25, plus plan storage limits.
**Legal and tax values must be verified by a qualified adviser before production use.**

Phase 1–2 tunables now read rules: `tenancy.allowed_parents`, `tenancy.max_depth`,
`tenancy.token_ttl_minutes`, `modules.purge_delay_days`.

### Future expansion (Phase 3)

- New country: add rows to the data file (or `rules:set --country=XX`). No code change.
- New partner or plan defaults: partner console / `rules:set --plan=`. No code change.
- Role-level values resolve (a member's roles are levels); an API to write them is still open.
  Editing needs the rule's `edit_permission` (default `rules.edit.{module}`).
- A rule editor UI arrives with the frontend; the API already returns everything it needs.

## Roles and permissions (Phase 4)

Code: `app/Platform/Access`. Every permission check answers three questions
(`AccessResolver::allows($permission, $organization)`):

1. **Held?** An owner holds every permission except both sides of a
   separation-of-duties pair; everyone else holds what their roles give. Portal members
   hold nothing here.
2. **In reach?** The target is the member's own unit or below. A branch admin reads the
   company but changes only the branch.
3. **Module on?** A module permission works only where its module is enabled (else 403).

Every catalog permission is also a Gate ability: `Gate::authorize('payroll.run', $org)` or
the `can:payroll.run` route middleware (the target defaults to the current organization).

### Permissions and templates (data)

- Core permissions: `app/Platform/Access/core-permissions.php`. Module permissions: the
  manifest `permissions[]`. Every module with rules also gets `rules.edit.{module}`.
  Labels: `lang/*/access.php` and `Modules/*/lang/*/permissions.php`.
- Role templates per sector: `database/seeders/data/role-templates.php` (patterns such as
  `payroll.*`, `*.view`, `!payroll.approve`).
- `php artisan access:sync` mirrors both into `permissions` / `role_templates` (deploy,
  seeder and test bootstrap run it). Removed entries are marked deprecated, never deleted.

### Roles

A role belongs to one organization and can be given there and in every unit below it.
`RoleService` does every write:

- **No escalation:** nobody puts a permission into a role, or gives/removes a role,
  unless they hold every permission in it (owners may grant anything in their unit).
- **Separation of duties:** rule `access.separation_of_duties` (default from the manifests'
  `separation_of_duties`, e.g. payroll.run / payroll.approve). Sensitive, so changes need
  maker-checker. Checked for one role, for all roles of a member, and for existing holders
  when a role changes. If a pair is added later, neither side works for people who hold
  both (fail closed).
- **Optimistic locking:** updates send `base_version`; a stale one gets 409.
- A role that is still given to someone cannot be deleted (422 with the count).
- Audit: `role.created|updated|deleted`, `membership.roles_changed` (with reason).

| method | path | notes |
|---|---|---|
| GET | /api/organizations/{id}/permissions | grouped; `blocked_by`: `not_held` / `module_disabled`; SoD pairs |
| GET | /api/organizations/{id}/role-templates | templates for the sector + `not_grantable` |
| GET/POST | /api/organizations/{id}/roles | own + inherited roles; create needs `roles.manage` |
| GET/PATCH/DELETE | /api/organizations/{id}/roles/{role} | changed only where the role is owned |
| PUT | /api/organizations/{id}/members/{membership}/roles | `{role_ids, reason}`, `members.manage` |

`/api/me` returns `permissions` (those working in the current organization), `can` built from
them, and `context.roles`. The browser app has a Roles page (permission matrix) and a
"Change roles" dialog on the Members tab.

### Future expansion (Phase 4)

- New sector: add templates with `sector` set and their names in `lang/*/access.php`. No code.
- New module: manifest `permissions[]` (+ optional `separation_of_duties`) and a
  `permissions.php` label file; run `access:sync`. No platform code.
- New country or partner: nothing; a partner can add pairs through the rule.
- Open: role-level rule values API; portal own-records access (Phase 5C); the permission
  list in the offline lease (Phase 7).

## Plans, sectors and packaging (Phase 5)

Code: `app/Platform/Packaging`. Data (the source of truth, read at boot):
`database/seeders/data/plans.php` and `database/seeders/data/sector-packages.php`.
`php artisan packaging:sync` mirrors them into `plans`, `plan_prices` and `sector_packages`
(deploy, seeder and test bootstrap run it); removed entries are deprecated, never deleted.

### Plans

- A plan belongs to a **subscription**: the top organization sets it, every unit below
  follows. Only the partner console changes it (partner `owner` or `billing`), with a
  preview first and a reason; audited as `organization.plan_changed`.
- A module is available when the plan includes it (`modules`, or `*`) **and** its
  manifest allows the plan.
- **Downgrade:** modules the new plan leaves out stop everywhere in the tree
  (`ModuleDisabled` fires once, at the highest unit); their settings and data stay and
  come back with a higher plan.
- Prices: integer minor units per currency and period (placeholders until confirmed).
  There is no invoicing or payment yet.

### Usage limits

Rules `plans.max_users`, `plans.max_branches`, `plans.max_storage_mb` (null = unlimited),
settable at platform, plan and partner level only; plan values are in `rule-values.php`.
Plans sit below partners in the rule hierarchy, so a partner value is a default for plans
without one. A per-client deal is a value at the client's top organization, written only by
the partner console (`organization_editable: false` keeps it out of the client's rule editor).

`UsageLimiter` counts the whole subscription under a row lock: owners and staff (active or
invited, one person counts once; portal users never), and branches that are not archived.
Adding a member, reactivating one, making a portal user staff, or adding a branch beyond
the limit gets 422 `users_limit_reached` / `branches_limit_reached` with `max`, `used` and
`upgrade` (plans that allow more). A downgrade over a limit is allowed: nothing is
removed, but nothing new can be added. Storage is enforced once files exist.

### Sector packages (onboarding)

The package keys are the only valid `sector_key` values. When a company is created,
`ApplySectorPackage` turns on its modules at the company (those the plan lacks are listed
as "higher plan"), sets its rule values at company level (never over the company's own
values; skipped with the reason when locked above) and clones its role templates. It is
applied once per company and package (`organization_packages`), audited, and can be
applied again after a sector change from the overview.

| method | path | notes |
|---|---|---|
| GET | /api/plans | public plans: prices, limits, included modules |
| GET | /api/sectors | sector packages with their modules and roles |
| GET | /api/organizations/{id}/usage | plan, limits in use, modules of higher plans, package applied |
| POST | /api/organizations/{id}/sector-package | apply the current sector's package (`organizations.manage`) |
| GET | /api/partner/organizations/{id}/plan-preview?plan= | what a change would do, nothing saved |
| PUT | /api/partner/organizations/{id}/plan | `{plan, reason}`, partner owner/billing, throttled |

### Future expansion (Phase 5)

- New plan: an entry in `plans.php` + its limit values + names in `lang/*/packaging.php`.
- New sector: an entry in `sector-packages.php` + names. Optionally a module for the
  sector's own screens. No platform code.
- New country: prices in its currency are data.
- Open: partner price lists (5B-3), self-serve upgrade with payment (5C), storage metering.

## Partner layer (Phase 5B-1)

Code: `app/Platform/Partners`, `app/Platform/Branding`. Everything here is partner data;
clients' business data stays out of reach (support access: 5B-2).

### Addresses

`ResolveHost` (first global middleware) decides whose address a request came to:

- **Platform hosts** (`PLATFORM_HOSTS`, default `localhost,127.0.0.1`, plus the host of
  `APP_URL`): every account works, the brand follows the account.
- **An active partner domain** (`partner_domains`): only that partner's brand; only its
  organizations and console open (`ContextResolver` refuses others with 403 `wrong_address`,
  tokens included). A **client domain** (`organization_id` set) opens only that client.
- **Anything else** (unknown, pending, removed): 404, never another tenant.

A domain is added as `pending` with a random token and becomes `active` only when the TXT
record `_onesolution-verify.{host}` contains `onesolution-verify={token}` (`DnsTxtLookup`,
faked in tests). TLS: Caddy on-demand TLS asks `GET /internal/tls/ask?domain=&token=`
(`TLS_ASK_TOKEN`), which answers 200 only for platform hosts and active domains.

### Brand (`partner_brands`)

Product name, colors (checked: text on the color >= 4.5:1, the color on white >= 3:1),
font from `branding.fonts`, logos (light/dark), symbol, favicon, tagline, sign-in heading
and text, footer (bn/en), support email/phone, https legal links. Images: PNG, WebP or
JPEG up to 512 KB (SVG refused), stored on the private disk and served by
`/brand-assets/{partner}/{kind}?v={version}` only at that partner's addresses. The PWA
manifest (`/manifest.webmanifest`) follows the address. "Powered by" shows unless the
platform allows hiding it (`branding.powered_by_removable`).

### Partner console

| method | path | who |
|---|---|---|
| GET / PATCH | /api/partner/brand | all / owner |
| POST / DELETE | /api/partner/brand/assets/{kind} | owner |
| PUT | /api/partner/brand/powered-by | owner (if allowed) |
| GET / POST / DELETE | /api/partner/domains(/{id}) | all / owner |
| POST | /api/partner/domains/{id}/verify | owner |
| GET / PUT | /api/partner/modules(/{module}) | all / owner |
| POST | /api/partner/clients | owner, sales |
| PATCH | /api/partner/clients/{id}/status | owner |
| GET / PUT | /api/partner/clients/{id}/limits | all / owner, billing |

- **Clients:** the partner picks the shape (`structure`): a **single company** at the top,
  no group (default); a **new group** with its first company (`group_name` optional); or
  **another company in a group it already serves** (`group_id`: same account, plan and
  billing, no new client slot, owner optional; the group's owners are told). Optional first
  `branches`; the sector package is applied to the company; the owner is found by email or
  invited. Rule `tenancy.allowed_parents` now lets a company stand at the root.
  Suspending blocks every sign-in, tokens too.
- **Modules for all clients** (`partner_modules`): unlocked = a default each client may
  change; locked = decides for everyone. Turning one on also turns on what it needs.
- **Rules for all clients:** the partner rules page (set, constrain, lock; sensitive
  rules need a second partner owner).
- **Governance** (platform decides, partners only see): `partners.max_clients`,
  `partners.allowed_modules` (others show as "not offered"), `partners.allowed_countries`,
  `partners.sub_resellers_allowed`. Set with `php artisan rules:set KEY VALUE --partner=slug`.

Everything above is audited (`partner.*` actions).

### Future expansion (Phase 5B-1)

- New partner: a `partners` row, a brand, a verified domain. No code.
- New font: an entry in `branding.fonts` (bundled, no external host).
- Open: support access, suspension grace and export (done in 5B-2); billing, partner
  plans, branded email/SMS (5B-3); client transfer, DPA/terms, client sub-brands,
  partner API keys (5B-4).

## Support access, audit log and exit (Phase 5B-2)

Code: `app/Platform/SupportAccess`, `app/Platform/DataExport`, `app/Platform/Audit/Http`.
The client stays in control of its data: a partner looks inside only when the client
allows it, the client sees everything that happened, and it can take all its data out
at any time, even when the partner stops.

### Support access (break-glass, read-only)

1. A partner **owner or support** user asks (`POST /api/partner/support-grants`: client,
   severity, minutes 15..480, reason >= 10 chars). One open request per person and client.
2. The client decides on **Support access** (`support.approve`; owners hold it). The
   time is capped by `support.max_duration_minutes` (default 120). Severities listed in
   `support.auto_approve_severities` (sensitive rule, empty by default) are approved at once.
3. The partner user enters from the console (`POST /session/context` with
   `support_grant_id`, browser session only; tokens are refused). The context is
   **read-only**: every unsafe request gets 403 `read_only_support`, exports included.
   The session ends with the grant.
4. The client can end it at any time (`revoke`); `support:expire` (every minute) closes
   grants whose time is up, and an expired grant is refused at once anyway.

Every step is in the client's audit log (`support.requested`, `approved`, `rejected`,
`revoked`, `expired`, `session_started`), and so is **every request** support makes
(`support.accessed`: method and path; refused changes are marked `blocked`).

| method | path | who |
|---|---|---|
| GET | /api/organizations/{id}/support-grants | `support.approve` |
| POST | /api/organizations/{id}/support-grants/{grant}/approve \| reject \| revoke | `support.approve` |
| GET / POST | /api/partner/support-grants | owner sees all; support sees own / owner, support |
| POST | /api/partner/support-grants/{grant}/cancel | the requester |
| GET | /api/organizations/{id}/audit-log?filter=support\|changes | `audit.view` |

The audit log API never returns IP addresses or user agents.

### Partner suspension and grace

`php artisan partners:status suspend {slug} --reason=...` (the house partner cannot be
suspended; `reactivate` undoes it; both audited). The partner's clients then:

- for `partners.suspension_grace_days` (platform rule, default 30): **read-only**
  (403 `read_only_partner_suspended` on changes), with a banner showing the days left;
- after that: **export only**. Every other API call gets 403 `export_only`; the app
  shows only the Export screen.

A closed partner blocks sign-in as before.

### Data export

`POST /api/organizations/{id}/exports` (`data.export`, rate-limited, one running at a
time) queues `BuildDataExport`. The ZIP holds each dataset as JSON and CSV (UTF-8 BOM,
cells starting with `= + - @` are escaped) plus `manifest.json`. Platform datasets:
organizations, members (name, email), roles and assignments, module settings, rule
values, sector packages, audit log (no IP). Modules add their own by implementing
`ExportsModuleData` and tagging it `module.exporters`.

Files live on the private disk for `exports.retention_days` (default 7; `exports:prune`
daily). Downloading takes two steps: `GET .../exports/{export}/link` returns a signed
URL valid for 5 minutes, then `GET /exports/{export}/download` streams the file.
Exporting works in read-only and export-only contexts, never for support staff.

### Future expansion (Phase 5B-2)

- New module: implement `ExportsModuleData` to include its data in exports; support
  access and the audit log cover it without changes.
- Different country or partner: grace days, support duration and auto-approval are
  rules. No code.
- Open: support access with write rights (maker-checker per change), export to other
  formats, scheduled exports.

## Partner plans and billing (Phase 5B-3)

Code: `app/Platform/Billing`; partner plans and subscriptions in `app/Platform/Packaging`.
Money is always integer minor units plus an ISO currency code; tax and shares are basis
points, rounded half up with integer math (`Billing\Money`). Amounts in different
currencies are never added together or converted.

### Partner plans

A partner builds its own plan on one of ours (`partner_plans`, `partner_plan_prices`):
its own name (bn/en), prices per currency and period, and optionally **fewer** modules
(never more; only modules the platform lets it offer; a module's requirements must come
with it). `ModuleResolver` treats a module the partner plan leaves out as "not in plan".
While clients are on a plan its base plan and modules are fixed (make a new plan); name
and prices can change (they apply from the next invoice). Archived plans take no new
clients; existing clients keep them.

### Subscriptions

One per client (top organization), created on first need: plan (still
`organizations.plan_key`), partner plan, currency, period (monthly/yearly) and
`billed_through`. The partner console's plan dialog (`PUT /api/partner/organizations/{id}/plan`)
now takes `partner_plan_id`, `currency` and `period` too. Clients we invoice ourselves
(direct, revenue share) can only be put on a plan that has a price in their currency
and period.

### Billing models (partner `billing_mode`)

| mode | who is invoiced | price | partner earns |
|---|---|---|---|
| wholesale | the partner, one invoice a month in `billing.partner_currency` | `wholesale_prices` per client or per staff seat | its own margin (billed outside the system) |
| revenue_share | each client, under the partner's brand | the partner plan's price (or our list price) | `partners.revenue_share_bp` of the subtotal |
| direct (house) | each client | our list price | — |

`billing:run` (scheduled on the 1st, 01:00 UTC; safe to repeat) bills the month in
advance. No proration: a client is billed from the first month that starts on or after
the day it joined. Every document has a billing key, so a month is never billed twice;
numbers are gap-free per type and year (`INV-2026-000001`, `CN-2026-000001`). Issued
documents never change: seller and buyer are frozen, line texts are frozen in bn and en,
corrections are credit notes. Paying a revenue-share invoice makes the commission
payable; a credit note takes back the same share; payouts are per currency.

Rules: `billing.partner_currency` (default USD), `partners.revenue_share_bp` (3000,
sensitive), `billing.tax_rate_bp` (0, sensitive, set per country), `billing.payment_terms_days`
(14). Default wholesale prices: `database/seeders/data/wholesale-prices.php`, recorded by
`billing:sync-prices`. **Prices, share and tax are placeholders** until the business and
a tax adviser confirm them. Seller details: `BILLING_ISSUER_NAME`, `_ADDRESS`, `_TAX_ID`,
`_EMAIL`.

### Platform commands (no payment gateway yet)

```
php artisan billing:run [--month=2026-10] [--partner=acme]
php artisan billing:mark-paid INV-2026-000012 --reference="Bank ref 88213"
php artisan billing:credit INV-2026-000012 --amount=150000 --reason="Two weeks of downtime"   (or --full)
php artisan billing:payout acme BDT --reference="Transfer 2026-10-05"
php artisan billing:wholesale-price business USD 2500 --unit=per_client [--partner=acme] [--from=2027-01-01] --reason="2027 contract"
php artisan billing:sync-prices
```

All of them are audited (`billing.*`, `partner_plan.*`); client documents show in the
client's own audit log.

### API

| method | path | who |
|---|---|---|
| GET / POST | /api/partner/plans | all / owner, billing |
| PATCH | /api/partner/plans/{id} | owner, billing |
| POST | /api/partner/plans/{id}/archive | owner, billing |
| GET | /api/partner/clients/{id}/subscription | all partner staff |
| GET | /api/partner/billing, /billing/invoices[?billed_to=organization], /billing/invoices/{id}, /billing/commissions, /billing/payouts | owner, billing |
| GET | /api/organizations/{id}/billing, /billing/invoices/{invoice} | `billing.view` at the top organization |

Wholesale clients see their plan but no price or invoices (their provider bills them).
Invoices open as a printable page ("Print or save as PDF").

### Future expansion (Phase 5B-3)

- New country: its tax rate as a country value of `billing.tax_rate_bp`, prices in its
  currency in the data files or partner plans. No code.
- New partner deal: `billing:wholesale-price --partner=...` or a partner value of
  `partners.revenue_share_bp`. No code.
- Open: payment gateways, checkout, trials, proration and dunning (5C); PDF files;
  currency conversion for payouts (with the multi_currency module); branded invoice
  emails (5B-3b).

## Branded email and SMS (Phase 5B-3b)

Code: `app/Platform/Notifications`. Platform events reach the people who can act on
them, by email, in the partner's brand and the client's language.

### What is sent, to whom

| notification | when | to |
|---|---|---|
| support.requested | a partner asks for support access (not auto-approved) | client owners and staff holding `support.approve`, at the unit or above |
| support.decided | the client approves or rejects | the partner staff member who asked |
| exports.ready | a data export is built | the person who asked |
| billing.invoice_issued | an invoice to a client | client owners and staff holding `billing.view` |
| billing.partner_invoice_issued | a wholesale invoice | the partner's owners and billing staff (our brand) |

Events (`SupportAccessRequested`, `SupportAccessDecided`, `DataExportReady`,
`InvoiceIssued`) fire after commit; `SendPlatformNotifications` picks recipients
(`Recipients`), `Notifier` records one `notification_deliveries` row per person (address
masked, content encrypted and removed once sent) and queues `DeliverNotification`.
Portal users never receive these. Links open the partner's own address (a client's own
domain first).

### Sending domain (per partner)

The owner adds a domain (e.g. `mail.acme.com`); we create a DKIM key (RSA, encrypted at
rest, never returned by the API) and show four TXT records: ownership, SPF
(`include:` + `MAIL_SPF_INCLUDE`), DKIM (selector `osYYMM`) and DMARC. Mail goes out
from `no-reply@` the domain (address, sender name and reply-to are editable), DKIM-signed
with Symfony's `DkimSigner`, only while all four check out; a later failed check stops it.
Otherwise mail comes from `MAIL_FROM_ADDRESS` with the partner's product name. Rule
`mail.custom_domain_allowed` (platform) can switch own domains off per partner.

### SMS

`SmsGateway` contract (driver `SMS_DRIVER`, `log` until a provider is added in 5C). A
partner's sender ID (3 to 11 characters) needs platform approval where
`sms.sender_id_requires_approval` (default yes; `php artisan sms:sender approve acme
--reason=...`); until then the platform's `SMS_DEFAULT_SENDER` is used. SMS is off until
the partner turns on `notifications.sms_enabled`. People have no phone numbers yet
(verified phones arrive with 5C), so today only test SMS are sent.

### Wording

Defaults: `lang/{locale}/notifications.php` (`templates.*`). A partner rewords any
message per channel and language (`notification_templates`): plain text with
`{{ placeholder }}` slots, only the placeholders the notification offers (others are
refused), no markup, nothing evaluated; the mail view escapes everything. The editor
previews with example values; the email preview is served from its own page
(`/partner-preview/{id}`, its author only, 10 minutes) with a policy that allows inline
styles and no scripts, in a sandboxed frame.

### API (partner console; owners change, everyone reads)

| method | path |
|---|---|
| GET | /api/partner/messaging |
| POST / PATCH / DELETE | /api/partner/messaging/domain |
| POST | /api/partner/messaging/domain/verify |
| POST / DELETE | /api/partner/messaging/sms-sender |
| POST | /api/partner/messaging/test-email, /test-sms (5 per hour) |
| GET | /api/partner/templates, /templates/{notification} |
| PUT / DELETE | /api/partner/templates/{notification}/{channel}/{locale} |
| POST | /api/partner/templates/{notification}/{channel}/{locale}/preview |

Every change is audited (`partner.mail_*`, `partner.sms_sender_*`, `partner.template_*`).

### Future expansion (Phase 5B-3b)

- New notification: an entry in `NotificationCatalog`, default wording in the lang
  files, and a listener for its event. Partners can reword it at once.
- New language: add its wording to `lang/{locale}/notifications.php`. No code.
- New SMS provider: implement `SmsGateway` and add its driver. No other code.
- Open: SMS to people (verified phones, 5C), notification preferences for non-essential
  messages, bounce and complaint handling from the mail provider.

## Client transfer and legal documents (Phase 5B-4)

Code: `app/Platform/Transfers`, `app/Platform/Legal`. Clients own their data: they can
move to another provider with all of it, and they accept the terms they work under.

### Moving to another provider

1. The new partner creates a one-time **transfer code** (`XXXX-XXXX-XXXX`, only a hash
   stored, valid `partners.transfer_code_days`, default 14) and gives it to the client.
2. The client's **account owner** (owner at the top organization, not support staff) enters
   it on **Provider & terms**, sees what changes (preview) and consents with a reason.
   Coming back to the house partner needs no code and happens at once.
3. The new partner's owner accepts (or rejects with a reason). The old partner is told
   (without naming the new one) but cannot block it.

`TransferService` then, in one transaction: sets `partner_id` on every unit of the tree
(the tree, members, roles, settings, rule values, audit log all stay), moves the
subscription (the old partner's plan ends; the base plan stays), disables the client's
domain at the old partner's address, ends open support access, flushes caches and fires
module events for modules the new partner does not offer (their data is kept). Past
invoices and commissions stay with the old partner. The new partner's governance
(`partners.max_clients`, `partners.allowed_countries`) is checked in the preview and again
on acceptance. Everything is audited in the client's log and both partners' logs.

Platform decision (e.g. a partner closes), without consent, everyone told:

```
php artisan clients:transfer house --all-from=acme --reason="Acme closed on 1 Nov"
php artisan clients:transfer beta --client=01J... --reason="..."
```

### Legal documents

Terms of service, privacy notice and data processing agreement: the platform's defaults
or a partner's own, in versions that never change once published (bn/en; plain text,
`# ` headings), all stored in `legal_documents`.

- **Platform defaults**: a new installation gets version 1 from
  `database/seeders/data/legal-documents.php` (`legal:sync`, run by the seeder; **placeholder
  text to be replaced by lawyer-reviewed text**). After that, One Solutions' owners (the house
  partner's owners) publish new platform versions from **Legal documents → New platform
  default** in their partner console. `legal:sync` never overwrites them; `legal:sync --force`
  publishes the file's text as a new version on purpose.
- **Partner documents**: any partner owner publishes its own from the same page; they
  replace the platform's for that partner's clients. A client is bound by its partner's
latest version, else the platform's. Account owners accept the terms and the DPA
(`document_acceptances`: who, when, version, language); a new version asks again (banner and
email) but never blocks work. Rule `legal.acceptance_required` (default on).

### API

| method | path | who |
|---|---|---|
| GET | /api/organizations/{id}/provider | anyone in the account |
| GET | /api/organizations/{id}/legal/{kind} | anyone in the account |
| POST | /api/organizations/{id}/legal/{kind}/accept | account owner |
| POST | /api/organizations/{id}/transfer/preview, /transfer | account owner (10 per hour) |
| POST | /api/organizations/{id}/transfer/{transfer}/cancel | account owner |
| GET | /api/partner/transfers[?direction=outgoing] | partner staff |
| POST / DELETE | /api/partner/transfer-codes[/{code}] | owner, sales |
| POST | /api/partner/transfers/{id}/accept, /reject | owner |
| GET / POST | /api/partner/legal[/{kind}], /legal/{kind}/{version} | all / owner |

### Future expansion (Phase 5B-4)

- New country: its legal text as a platform or partner version in that language. No code.
- New document kind: add it to `LegalDocument::KINDS` (and `ACCEPTED_KINDS` if clients
  accept it) with default text in the data file.
- Open: moving a single company out of a group; e-signature providers for DPAs.

## Client sub-brands, invitations and partner API (Phase 5B-5)

Code: `app/Platform/Branding` (client brand), `app/Platform/Invitations`,
`app/Platform/PartnerApi`.

### Client sub-brands

A client (e.g. a school group) can show its own **name, main color and logo** to its
people, where its partner allows it: partner rule `branding.client_sub_brands_allowed`
(default off; the partner turns it on in **Partner rules**).

- Who edits: an **account owner** with `branding.manage`, on **Our brand** (`/branding`).
  Colors are checked for contrast (white text on buttons, visible on a white page).
  Logo: PNG/WebP/JPEG up to 512 KB, stored on the private disk, served at
  `/client-brand-assets/{client}/logo` only on the platform host, the partner's domains
  or the client's own domain.
- Where it shows: the app for everyone in the client (every unit of the tree), the
  client's own domain (its sign-in page, with the neutral title instead of the partner's),
  and emails to the client's people.
- Where it never shows: invoices and legal documents (they keep the partner's details),
  the partner console, and other clients. Fonts, legal links and "Powered by" stay the
  partner's.
- `BrandResolver::for($partner, $client)` lays the client brand over the partner brand;
  when the partner turns the rule off, every client falls back at once (data kept).
- Audit: `organization.brand_updated`, `organization.brand_logo_changed`.

### Invitations

Adding someone who has no account (partner console, or the API) creates the account
without a usable password and emails a one-time link `/invite/{token}` (only a sha256
hash stored, valid 72 hours). The person sets a password (10+ characters, letters and
numbers) and is signed in. People who already have an account just get access and a
"you were added" email. Emails `members.invited` / `members.added` use the partner's
(or client's) brand and are editable in **Message wording**. An unknown email without a
name is refused: the form asks for the owner's name. Audit: `membership.invited`,
`membership.invitation_accepted`.

### Partner API keys

A partner **owner** creates keys on **API keys** in the partner console:

- Format `osk_{prefix}_{secret}`, **shown once**; only the prefix and a hash are stored.
- Scopes: `clients:read`, `clients:write`, `members:write`, `plans:read`,
  `subscriptions:write`.
- Valid `partners.api_key_days` (default 365); revocable at once.
- A key acts as the owner who made it. It stops working if that person stops being an
  active owner, or the partner is suspended.
- Every change made with a key is in the audit log with its `api_key_id`.
- The API gives account data only (clients, people, plans), never a client's business data.

### Partner API v1

Base URL: `https://{partner domain or platform host}/api/partner/v1`, header
`Authorization: Bearer osk_…`, JSON in and out.

| method | path | scope | body |
|---|---|---|---|
| GET | /clients | clients:read | |
| GET | /clients/{id} | clients:read | |
| POST | /clients | clients:write | as the console: `name`, `sector_key`, `plan`, `owner_email`, `owner_name`, `country_code`, … |
| POST | /clients/{id}/members | members:write | `email`, `name`, `membership_type` (owner, staff), `organization_id?`, `access_scope?` |
| GET | /plans | plans:read | |
| PUT | /clients/{id}/plan | subscriptions:write | `plan`, `reason?` |

```
curl -X POST https://erp.acme.example/api/partner/v1/clients \
  -H "Authorization: Bearer osk_…" \
  -H "Idempotency-Key: signup-4711" \
  -H "Content-Type: application/json" \
  -d '{"name":{"en":"Sunrise School"},"sector_key":"school","plan":"business",
       "owner_email":"head@sunrise.test","owner_name":"Head Teacher","country_code":"BD"}'
```

- **Idempotency**: send `Idempotency-Key` on writes. A retry with the same key and body
  gets the first answer again (header `Idempotent-Replayed: true`); the same key with
  another body gets 422. Answers are kept for a day per API key.
- **Rate limit**: `partners.api_rate_per_minute` per key (default 60), 429 beyond it.
- Errors: 401 (missing, wrong, expired or revoked key), 403 (scope missing), 404 (unknown
  client, or another partner's), 422 (validation, with `errors`), all with a readable
  `message`.
- The partner's governance rules (`partners.max_clients`, allowed countries, plans) apply
  exactly as in the console.

Console endpoints: `GET/POST /api/partner/api-keys`, `DELETE /api/partner/api-keys/{id}`
(owner); `GET/PATCH /api/organizations/{id}/brand`, `POST/DELETE .../brand/logo`;
`GET/POST /session/invitations/{token}` (rate limited).

### Future expansion (Phase 5B-5)

- New partner: turns sub-brands on with its rule; creates its own keys. No code.
- New scope: add it to `PartnerApiKey::SCOPES` with a route and its label (bn, en).
- Open: webhooks to partners (client created, plan changed), OAuth apps, client-set
  email sending domains, API v2 once business modules expose public services.

## Self-serve sign-up and identity (Phase 5C-1)

Code: `app/Platform/Identity`. Individuals create their own account (B2C), sign in with an
email or a verified phone, recover a forgotten password, and manage their account. Phase
5C continues with self-serve billing (5C-2), upgrading to a company and "my data" (5C-3), and
client portals (5C-4).

### Sign-up

1. `/signup`: name, email **or** mobile number (with country), password, the terms (their
   current version) and an optional marketing consent. A **bot check** runs first.
2. A 6-digit **code** goes to that address (SMS or email); nothing is created yet.
3. The right code creates, in one transaction: the user (address verified), a **personal
   workspace** (organization type `personal`, top of the tree, the person as owner) on the
   plan of rule `b2c.default_plan` (`personal_free`), and the terms acceptance. The person is
   signed in and gets a three-step **first run** (`/welcome`: language, country, kind of work;
   the sector sets the workspace up like a company's).

Where: the partner of the address (the house partner on the platform's own address) when
its rule `b2c.self_signup_allowed` is on (house: on in the seed data; others: off). A client's
own address never takes sign-ups. Phone sign-up only where the partner sends SMS
(`notifications.sms_enabled`) and for numbers of `identity.allowed_phone_countries`
(default BD). Formats of numbers per country: `config/identity.php` (data).

A **personal workspace** works like a company for rules, modules and sectors (its rule
values are stored at the company level), so an upgrade later only changes its type. It
never has branches, takes only **personal plans** (`plans.php` `audience: personal`; business
clients only business plans; `GET /api/plans?audience=personal`), does not use a partner's
`partners.max_clients` slots, and accepts the terms but not the DPA.

### Codes and abuse protection

- Codes: 6 digits, 10 minutes, 5 wrong tries, 4 sends per challenge, 60 s between sends
  (`config/identity.php`). Only an HMAC of the code and of the address is stored; the send job
  is encrypted in the queue; the wording is fixed ("never share it").
- Limits: per address and per network per hour (rules `identity.otp_per_hour_per_destination`,
  `identity.otp_per_hour_per_ip`), plus bursts per minute per network (`identity-start`,
  `identity-code` limiters).
- No account discovery: an address that already has an account gets exactly the same answer,
  no code (a decoy that can never pass), and its owner is told someone tried.
- Throwaway inboxes are refused (`identity.block_disposable_email`, list in
  `resources/data/disposable-email-domains.txt`).
- Bot check: `BOT_CHECK_DRIVER=turnstile` with `TURNSTILE_SITE_KEY` / `TURNSTILE_SECRET`
  (Cloudflare Turnstile; the CSP allows it only then). Without it, production keeps sign-up
  and recovery closed; local and tests use `none`.

### Sign-in, recovery and my account

- Sign in with an email, or a **verified** phone (`phone` + `country_code`, both on
  `/session/login` and `/api/auth/login`).
- `/forgot`: a code to the account's email or verified phone, then a new password. Every
  other session and API token ends, **every address of the account is told**, and the email
  and phone cannot be changed for `identity.recovery_cooldown_hours` (24), so a stolen inbox
  cannot also move the account.
- `/account` (any context): name, language, marketing consent; email and phone (the new one
  proved by a code, the old one told; the phone can be removed while an email is left);
  password (current one needed; other devices signed out); **signed-in devices** (own
  `user_sessions` table, any session store: end one or all others; an ended session is signed
  out on its next request).
- People without an email get notifications by SMS on their verified phone. Security notices
  (`identity.password_changed`, `identity.contact_changed`, `identity.signup_attempt`) go to
  every address and cannot be reworded by partners.
- Audit: `identity.signed_up`, `identity.recovered`, `identity.password_changed`,
  `identity.email_changed`, `identity.phone_changed`, `identity.session_ended`, and more.

### API

| method | path | who |
|---|---|---|
| GET | /session/signup/options, /session/legal/{terms\|privacy} | anyone |
| POST | /session/signup, /session/recovery | anyone (bot check, 5/min) |
| POST | /session/signup/verify, /session/recovery/verify, /session/otp/resend | anyone (10/min) |
| GET / PATCH | /api/me/account | signed in |
| POST | /api/me/contact, /api/me/contact/verify; DELETE /api/me/phone | signed in (password) |
| PUT | /api/me/password | signed in (password) |
| GET / DELETE | /api/me/sessions[/{id}] | signed in |
| POST | /api/me/onboarding | signed in |

Local development: set `SMS_LOG_TEXT=true` in `.env` to see SMS codes in
`storage/logs/laravel.log` (email codes are there with `MAIL_MAILER=log`). The local demo seed
turns SMS on for the house partner and adds `self.demo@demo.test` (`DemoIdentitySeeder`).

### Future expansion (Phase 5C-1)

- New country for phone sign-up: its number format in `config/identity.php` and the country
  in `identity.allowed_phone_countries`. New SMS provider: an `SmsGateway` driver. No other code.
- New partner offering B2C: turn on `b2c.self_signup_allowed`, pick `b2c.default_plan`. No code.
- Open: Google/Apple sign-in and passkeys (Phase 8), a real SMS provider, trials and payment
  (5C-2), device fingerprinting for trial abuse (5C-2).

## Self-serve billing (Phase 5C-2)

Code: `app/Platform/Payments` (gateways, payments, callbacks) and `app/Platform/Billing/SelfServe`
(checkout, trials, renewals, overdue). A personal workspace buys, pays for and changes its own
plan on `/billing` (and keeps doing so after it becomes a company, 5C-3: self-serve is the
subscription's `self_serve` flag, not the organization type). Coupons, mid-period plan changes with proration and trial-abuse checks by
device or card are Phase 5C-2b.

### Payment gateway

- `PaymentGateway` interface; one driver: **SSLCommerz, sandbox only** (cards, bKash, Nagad on
  one hosted page). There is no live address in the code, and the sandbox refuses to take
  payments in production (no plan can be unlocked with test money). Going live is a separate,
  reviewed change. The page shows "Test mode".
- Credentials: `SSLCOMMERZ_STORE_ID`, `SSLCOMMERZ_STORE_PASSWORD` (sandbox store from
  developer.sslcommerz.com). Empty = no online payment offered ("contact support").
- Which gateway a country offers: rule `billing.payment_gateways` (country-specific; BD =
  `sslcommerz` in the seed data). A new gateway is a driver + a value of that rule.
- **A payment counts only when SSLCommerz's validation API confirms it** (by `val_id`), for the
  exact amount and currency the server fixed when it started. A different amount, or the
  gateway's risk flag, holds the payment for review (`payments.held` in the audit log) and
  unlocks nothing. Failures and cancellations need a valid `verify_sign`. Card data never
  reaches us; card numbers in gateway messages are not stored.
- Idempotent everywhere: one `op_id` per click (a repeated click returns the same payment and
  page); every gateway message is stored once per reference (`gateway_events`), so a repeated
  notice changes nothing; a second payment of an already paid invoice is flagged
  `refund_due`, never applied twice.
- Callbacks: `POST /payments/{gateway}/notify` (server to server) and
  `/payments/{gateway}/return/{success|fail|cancel}` (the browser coming back). Both run without
  session or CSRF (a new session there would replace the person's) and confirm with the
  gateway before changing anything. The status page and `payments:reconcile` (every 5 minutes)
  also ask the gateway, so a lost notice (or a laptop the gateway cannot reach) still confirms;
  unfinished payments expire after `billing.checkout_expiry_minutes` (30).

### Plans, trials and renewals

- Checkout: quote (price, tax at `billing.tax_rate_bp`, the period from today), then the
  gateway. On success, in one transaction: an invoice for the exact amounts, marked paid (the
  receipt), the plan switched (`ChangePlan`, modules follow), the period recorded, any trial
  ended. Revenue-share partners get their commission as usual. Wholesale partners' clients are
  billed by their partner, not here.
- During a paid period the plan changes only when it ends ("change at period end"); an unpaid
  invoice must be paid (or the account moved to the free plan) before buying.
- **Trial** of `b2c.trial_plan` (`personal_plus`) for `b2c.trial_days` (14; 0 = off), from the
  free plan, verified people only, **once per person and partner**: the account and each
  verified email and phone (hashed, `trial_grants`), so a new account with the same phone gets
  none. Reminder `b2c.trial_reminder_days` (3) before; at the end, back to the free plan with
  all data.
- **Renewal**: `billing:self-serve` (hourly) issues the next period's invoice
  `billing.renewal_notice_days` (5) before the paid one ends, due on its first day. No stored
  card: the person pays it online. Self-serve subscriptions are left out of the monthly
  `billing:run`.
- **Move to the free plan**: during a paid period it happens when the period ends (and can be
  undone until then, "Keep my plan"); with unpaid invoices they are cancelled by credit notes
  and it happens at once.

### Unpaid bills (dunning)

1. Reminders on the days of `billing.overdue_reminder_days` ([0, 3, 6]) after the due date.
2. After `billing.overdue_grace_days` (7) the workspace becomes **read-only** (context mode
   `read_only`, reason `payment_overdue`, set through the Tenancy `WorkspaceRestrictions`
   contract): it can view, export, pay and move to the free plan; every other change gets 403
   `read_only_payment_overdue`. **Nothing is deleted.**
3. Paying the invoice, or moving to the free plan, restores it at once.

Notifications (partner-rewordable, bn/en): `billing.payment_received` (receipt),
`billing.payment_failed`, `billing.trial_ending`, `billing.trial_ended`,
`billing.payment_overdue`, `billing.workspace_restricted`, `billing.workspace_restored`.
Audit: `payments.succeeded`, `payments.held`, `payments.refund_due`, `billing.trial_started`,
`billing.cancel_scheduled`, `billing.cancel_undone`, `billing.workspace_restricted`,
`billing.workspace_restored`, plus invoices and plan changes. All numbers above are rules
(PLACEHOLDER values until the business confirms them).

### API

| method | path | who |
|---|---|---|
| GET | /api/organizations/{id}/billing/self-serve | billing.view |
| GET | /api/organizations/{id}/billing/payments/{payment} | billing.view |
| POST | /api/organizations/{id}/billing/quote, /checkout, /invoices/{invoice}/pay | billing.manage (10/min) |
| POST | /api/organizations/{id}/billing/trial, /free (confirm), /keep-plan | billing.manage |
| POST | /payments/{gateway}/notify, /payments/{gateway}/return/{outcome} | the gateway |

`billing.manage` is a new core permission (owners hold it). Screens: `/billing` (plan, trial,
plans, open invoices, pay), `/billing/payments/{id}` (status after the payment page), and a
"Pay now" banner while read-only.

Local check: put sandbox credentials in `.env`, sign up (or use `self.demo@demo.test`), open
Billing, choose Personal Plus and pay with a sandbox test card or wallet. The gateway cannot
reach `localhost` with its notice; the return page and the status page confirm it instead.
Run `php artisan billing:self-serve` to see renewals and reminders.

### Future expansion (Phase 5C-2)

- New country or gateway: a `PaymentGateway` driver and the country in
  `billing.payment_gateways`. New partner selling B2C: its own trial and grace rules; no code.
- Open: live SSLCommerz (reviewed change), coupons and proration (5C-2b), partner-owned
  merchant accounts, auto-charge with stored tokens, BD VAT invoice format (Mushak 6.3) and
  real tax rates with an adviser, refunds through the gateway, trial abuse by device or card.

## Upgrade to a company, my data, deleting an account (Phase 5C-3)

Code: `app/Platform/Identity` (`Actions/UpgradeWorkspace`, `Services/AccountDeletion`,
`Services/PersonalDataExport`).

### Personal workspace → company

- `/upgrade` (the workspace's owner only; rule `b2c.upgrade_allowed`, default on): company name
  (en required, bn optional), kind of work (sector package applied), business plan (suggested:
  rule `b2c.upgrade_plan`, `starter`) and period.
- **Same organization and id**: every record, setting, invoice and audit entry stays. Only the
  type (`personal` → `company`), name and sector change; the plan becomes a business plan
  (`ChangePlan`, modules follow). A company takes one of the partner's `partners.max_clients`
  places, must accept the DPA, and can add members up to its plan's `plans.max_users`.
- **Billing without proration**: a paid personal period runs to its end with the company's
  features and the business plan is billed from the next day by the usual renewal; with nothing
  paid running (free or trial), the first business invoice is issued at once, due after
  `billing.payment_terms_days`. The company stays **self-serve** (`subscriptions.self_serve`):
  it pays online, renews and goes read-only when overdue like a personal workspace. A company
  has no free plan to fall back to.
- Refused with a clear reason: not the owner (403), an unpaid invoice first, no client place
  left, a personal plan picked. It cannot be turned back. Audit: `organization.upgraded`.
  "Team" is a small company: there is no separate type.

### My data

`GET /api/me/data` (My account → "Download my data", 5 per hour): one JSON file with what we
keep about the person: account, memberships, partner memberships, devices, legal acceptances,
payments, free trials, messages sent to them and their own actions (last 5000 audit entries).
Audited (`identity.data_downloaded`). A workspace's business data has its own export (5B-2).

### Delete my account

1. `POST /api/me/deletion` with the password and the word `DELETE`. The deletion is scheduled
   after `privacy.account_deletion_grace_days` (30, country-specific, PLACEHOLDER); every
   address is told (`identity.deletion_requested`, fixed wording). A banner shows it everywhere;
   `DELETE /api/me/deletion` cancels (`identity.deletion_cancelled`).
2. **Blocked** (and listed on My account beforehand) while the person is the only owner of an
   organization that has other members, or the only owner of a partner account: nothing is left
   without an owner.
3. `privacy:erase-due` (daily) erases due accounts (an account that became blocked meanwhile
   waits):
   - self-serve workspaces that are theirs alone: open invoices cancelled by credit notes,
     subscription cancelled, export files deleted, archived with the name removed;
     `WorkspaceErased` lets modules delete their business data;
   - memberships elsewhere are suspended; **the records a B2B client owns stay with it**;
   - the user row is anonymized ("Deleted person", no email, phone or password) and its
     sessions, tokens and codes are deleted. The row stays so the audit log and client records
     keep pointing at an anonymous person.
   - Kept on purpose: issued invoices (tax law), the audit log (security), trial-grant hashes
     (trial abuse). How long invoices are kept per country is a later rule.

### API

| method | path | who |
|---|---|---|
| GET / POST | /api/organizations/{id}/upgrade | owner of the personal workspace |
| GET | /api/me/data | signed in (5/hour) |
| POST / DELETE | /api/me/deletion | signed in (password to ask) |

`GET /api/me` and `/api/me/account` show `deletion_due_at`; `/api/me/account` also lists
`deletion_blockers`. Console: `php artisan privacy:erase-due`.

### Future expansion (Phase 5C-3)

- Another country's waiting period: a country value of `privacy.account_deletion_grace_days`.
  A partner without upgrades: `b2c.upgrade_allowed` off. No code.
- Modules with business data listen to `WorkspaceErased` (delete) and appear in "my data" when
  they hold data about the person (e.g. an employee record) through their own export.
- Open: invoice retention per country, handing over ownership from the members screen in one
  step, a reminder shortly before erasure.

## B2B2C portals (Phase 5C-4)

Code: `app/Platform/Portal` (the framework every module uses) and `Modules/ClientPortal` (the
switch, rules, permissions and texts: module `client_portal`, in starter/business/enterprise,
off until turned on like any module). A client gives its own people (parents, employees,
customers) a portal, in its brand, where each sees **only the records linked to them**.

### Record kinds come from modules

A module declares in its manifest which of its records a portal may show:

```php
'portal_subjects' => [Modules\School\Portal\StudentProvider::class],
```

The provider (`PortalSubjectProvider`) answers for kind `school.student`: label, relations
(`guardian`, `self`), find/search within an organization, and the fields it may show. The portal
never reads the module's tables. In its own queries a module restricts portal members with
`app(PortalAccess::class)->restrict($query, 'school.student')` (staff are not affected). A kind
works only while its module and `client_portal` are on. No module has records yet: the tests use
a fixture student (`tests/Fixtures/FixtureStudentProvider`).

### Inviting and joining

1. Staff with `client_portal.manage` open **Portal** (`/portal-admin`), pick a record, the
   relation, the person's name and their **email or phone**. The invitation (a link and a code
   like `K7QDP-9MXPA`, valid `client_portal.invitation_valid_days`, 14) is sent there, or handed
   over (printed); the code is shown only once, only hashes are stored.
2. The person opens `/portal/join/{token}` or types the code. The invitation is **bound to that
   address**: an existing account must have it verified; someone new gets a one-time code there
   and an account is created (verified, no personal workspace).
3. The link waits for the client's approval (`client_portal.link_approval`: `manual`, or
   `auto_verified`). Staff approve, reject, or later remove access (at once). All audited:
   `portal.invited`, `portal.joined`, `portal.link_approved|rejected|revoked`,
   `portal.invitation_revoked`.

Limits: `client_portal.max_links_per_person` (10), code lookups 10 per minute per network.
Fields a client hides: `client_portal.hidden_fields` (`["school.student.date_of_birth"]`).
`client_portal.allow_online_payment` is a flag for modules that will offer payments.

### What a portal member can reach

A portal membership holds no permissions and takes no seat. **Every organization endpoint
except `/api/portal*` answers 403 `portal_only`** (middleware, so a new endpoint is closed by
default). `GET /api/portal` lists their links (a waiting one shows no record name);
`GET /api/portal/records/{link}` shows one active record; anything else (another member's link,
another child's id, a revoked link, the module off) is 404 or 403. The app shows portal members
only "My records" and My account.

### API

| method | path | who |
|---|---|---|
| GET | /api/organizations/{id}/portal | client_portal.view |
| GET | /api/organizations/{id}/portal/kinds/{kind}/records?q= | client_portal.manage |
| POST / DELETE | /api/organizations/{id}/portal/invitations[/{invitation}] | client_portal.manage |
| POST | /api/organizations/{id}/portal/links/{link}/approve\|reject\|revoke | client_portal.manage |
| GET | /api/portal, /api/portal/records/{link} | the portal member |
| GET | /session/portal/invitations/{code or token} | anyone (10/min) |
| POST | /session/portal/join (signed in), /session/portal/signup, /session/portal/signup/verify | anyone (10/min) |

### Future expansion (Phase 5C-4)

- A new kind of record (students, payslips, patient visits, orders): the owning module adds a
  provider and one manifest line. No portal code changes.
- A new sector or partner: turn on `client_portal`, choose the approval rule and hidden fields.
- Open: telling staff when a link waits (notification), bulk invitations (e.g. a whole class),
  portal payments once a fees or invoicing module exists, and a portal-only address
  (e.g. `parents.school.com`) through client domains.

## A client's own payment gateway accounts (Phase 6, first part)

Code: `app/Platform/Payments` (merchant accounts, collections, gateway drivers) and
`Modules/OnlinePayments` (the switch, rules, permissions and texts: module `online_payments`, in
starter/business/enterprise, off until turned on). A company connects **its own** gateway store,
so its customers (parents, patients, shop customers) pay it directly. Paying *us* for a plan
(Phase 5C-2) still uses the platform's own store from `.env` and is unchanged.

The rest of Phase 6 (countries table, locales and RTL, timezones, exchange rates, bKash and
Stripe drivers) comes later.

### Connecting a store

Staff with `online_payments.manage` at the **company** open **Online payments**
(`/online-payments`); branches and departments collect into their company's account and see it
read-only. They pick a gateway offered for the country (`online_payments.gateways`, BD:
`sslcommerz`; a partner or group may narrow it), a name, the mode, the store id and password,
and **their own password**.

- The store details are **checked with the gateway before anything is saved** (a transaction
  query that must answer `DONE`).
- Credentials are encrypted with the app key (`encrypted:array`), hidden from every answer, log
  and audit entry. People see a hint only (`sunr***01`).
- Mode: `sandbox` (test money; never takes payments in production) or `live`, only where the
  platform rule `online_payments.live_mode_allowed` is on (off by default).

### Every change waits for a second person

Connecting, new store details or another mode go into a **waiting change**; the approved details
keep working until then.

- Another person holding `online_payments.manage` there (or an owner above) approves it with
  their password. The person who made it cannot.
- Nobody else can approve: it takes effect by itself after
  `online_payments.single_approver_wait_hours` (24), after checking with the gateway once more
  (`php artisan payments:apply-merchant-changes`, every 10 minutes).
- Everyone who could approve is told at once, by email and SMS, in fixed wording
  (`payments.merchant_change_requested`), and again when it takes effect.
- Anyone who may manage can reject a waiting change, or **turn the account off at once**.
  Turning it on again needs a password. A name change applies at once. Nothing is ever deleted.
- Audited: `payments.merchant_account.connected|change_requested|approved|applied_after_wait|
  change_rejected|disabled|enabled|renamed` (gateway, mode, hint; never a secret).
- Every write carries `base_version`: a stale screen gets 409 `stale`.

### Customers paying the client (collections)

A module declares what its customers can pay for, in its manifest:

```php
'payment_collectables' => [Modules\School\Payments\FeeCollectable::class],
```

The provider (`CollectableProvider`, kind `school.fee`) says what a person owes for a record
(`due()`, integer minor units; null when they may not pay it), marks it paid (`paid()`, inside
the confirmation's transaction) and where the browser goes back to. The module's own screen calls
`app(CollectPayment::class)->start($organization, $payer, 'school.fee', $id, $opId)`:

- the amount comes from the module, never the request; the same `op_id` gives the same payment;
- the money goes to the company's **active** merchant account (right gateway, currency, mode);
  with none, the answer is `no_merchant_account`. The platform's store is never used for a
  client's customers;
- gateway messages are checked with **the credentials of the account the payment belongs to**,
  and a message checked with one account's credentials only applies to that account's payments;
- after confirmation `payments.collected` (`PaymentCollected`) goes out; platform billing
  messages are not sent for collections (the module tells its payer).

Turning `online_payments` off stops new collections; money already taken is still applied.
No module has fees yet: the tests use a fixture (`tests/Fixtures/FixtureFeeCollectable`).

### API

| method | path | who |
|---|---|---|
| GET | /api/organizations/{id}/merchant-accounts | online_payments.view (at the company) |
| POST | /api/organizations/{id}/merchant-accounts | online_payments.manage + password (6/min) |
| PATCH | /api/organizations/{id}/merchant-accounts/{account} | online_payments.manage + password (6/min) |
| POST | …/{account}/test, …/{account}/approve, …/{account}/enable | online_payments.manage (approve, enable: + password; 6/min) |
| POST | …/{account}/reject, …/{account}/disable | online_payments.manage |

### Future expansion (Phase 6, merchant accounts)

- A new gateway (bKash, Stripe, …): a `GatewayDriver` + `PaymentGateway` pair registered in
  `PaymentsServiceProvider`, its name in `lang/*/payments.php`, and its key in the rule's enum.
  Screens and approvals need no change (fields come from the driver).
- A new country or partner: data only (rule `online_payments.gateways` for the country, and
  `online_payments.live_mode_allowed` when live money is approved).
- A new sector: its module adds a `CollectableProvider`. No payment code changes.
- Open: branch-level accounts, refunds, settlement reports, and the portal's "Pay" button once a
  fees or invoicing module exists.

## Countries, languages and timezones (Phase 6-1)

Code: `app/Platform/Countries` (catalog, sync, `GET /api/countries`), `database/data/countries`
(one file per country), `app/Platform/Support/LocaleResolver.php`, `HasTranslatedTexts`.

### A country is a data file

`database/data/countries/BD.php` holds the country's facts: name (per language), currency and
its decimals, date format, week start, weekend days, fiscal year start, phone format (dial,
trunk, national pattern), address lines, tax profile key, data residency region, languages
(default first), timezone, and payment gateways (platform billing and clients' merchant
accounts). BD, SA, IN, PK, NP, LK, AE, MY, GB and US are included; **everything except BD is a
PLACEHOLDER to be reviewed by an adviser**.

- `CountryCatalog` reads the files (checked when loaded: ISO codes, IANA timezone, day names…),
  so phone numbers and defaults work before anything is synced.
- `php artisan countries:sync` (also run by `CountriesSeeder`) mirrors them into the `countries`
  table and writes the country's **rule defaults** as platform-level country values, through the
  rule service (versioned and audited, reason "Country data file XX.php"): `attendance.weekend_days`,
  `accounting.fiscal_year_start`, `regional.week_start`, `regional.date_format`,
  `billing.payment_gateways`, `online_payments.gateways`. Only changed values are written, so it
  can run on every deploy. Clients override them like any rule.
- Organization country codes must be known countries (API validation).

**Adding a country** = add `XX.php`, run `php artisan countries:sync`. No code change.

### What comes from the country

An organization's language, timezone, currency and data region: its own value, else an
ancestor's, else **its country's**, else the platform default (`config/tenancy.php`). The
settings screen shows "From the country" for those.

### Languages

- Which language someone reads (`LocaleResolver`): what they picked on this screen (X-Locale)
  → their profile language → the organization's (own, inherited, or its country's) → platform
  default. Notifications go to each person in their own language.
- Only languages with texts are used (`tenancy.supported_locales`, now `en`, `bn`). Arabic:
  right-to-left support is ready (the page `dir` follows the language; layouts use logical CSS);
  the Arabic texts are not written yet. Turning it on = add `lang/ar`, `resources/js/locales/ar`,
  module `lang/ar`, and `ar` to `supported_locales`. A few draft files exist in `lang/ar`.
- Data labels in several languages use `spatie/laravel-translatable` (`HasTranslatedTexts`):
  organization, role and partner plan names, invoice line descriptions, country names.
  `$model->name` is the current language (falling back to English, then any);
  `texts('name')` gives all; `putTexts()` replaces the set. Forms show one field per supported
  language (`TranslatedFields`), so a new language needs no form change.

### Timezones

UTC in the database. Times on screen use the person's own timezone (`users.timezone`, set in My
account), else the organization's (own, inherited or its country's).

### Money

Integer minor units plus a currency code, never floats. `tests/Feature/Architecture/NoFloatMoneyTest`
fails the build on a float/double/decimal money column, a float cast or type in PHP, or
`parseFloat`/`toFixed` in the browser app.

### API

| method | path | who |
|---|---|---|
| GET | /api/countries | signed-in people (names in the reader's language) |
| PATCH | /api/me/account (`timezone`, `locale`) | the person |

### Future expansion (Phase 6-1)

- New country: a data file. New language: its translation files plus one config entry, or
  added at runtime in the language editor (LANG-1, below).
- Later in Phase 6: Arabic texts, exchange rates, bKash and Stripe drivers (6-2).

## Languages and wording in the database (LANG-1)

Code: `app/Platform/Localization`, module `multi_language` (permission
`multi_language.manage`, screen `/languages`), partner console `/partner/languages`.

The translation files stay the base (en, bn ship with the code, tested). On top of them,
any level can reword a text, and the platform can add a language without a deploy:

```
company wording → group → partner → platform → file of that language
→ the language's fallback file (database languages) → the key itself
```

- **Platform** (the house partner's owners): adds languages (draft → offered → off; only
  drafts can be removed), translates them, rewords any text for everyone.
- **Partner** (owners change, other staff look): rewords texts for all of its clients.
- **Group / company**: their own wording for their people (a branch reads its company's),
  only while `multi_language` is on there and the partner allows it (rule
  `i18n.allow_overrides`). Off: the texts stay stored, unused.

### Tables

| table | what |
|---|---|
| `languages` | database languages only: code (BCP 47), names, direction, fallback (a file language), status |
| `translation_overrides` | one text: level (`scope_type` platform/partner/organization, `scope_id`), locale, channel (`ui`/`server`), key, value, version |
| `translation_versions` | a counter per level, raised on every change (the cache hash) |

### Rules

| rule | levels | meaning |
|---|---|---|
| `i18n.allow_overrides` | platform, partner, plan | clients may use their own wording (default on) |
| `i18n.languages` | platform … company | language codes offered here; empty = every published one |
| `i18n.publish_min_percent` | platform | share translated before a language can be offered (80) |

### What is checked on every save

Only keys the code has (`TranslationCatalog`, read from the English files; plural forms of a
`…_other` key count too); plain text only (no tags; texts are also shown escaped everywhere);
only the placeholders of the original (`{name}` in the browser, `:name` on the server); at most
1000 characters; imports (≤ 5000 texts) are all or nothing. Every change is audited
(`i18n.text_saved`, `i18n.text_reset`, `i18n.texts_imported`, `i18n.language_*`).

### Speed

- The shell and `/api/me` carry `languages`, `locales` and `i18n: { hash, overlays }`. With no
  wording anywhere the hash is `0` and **the browser makes no extra request**.
- A file language with wording: one request, `GET /api/i18n/{hash}/{locale}` (only the reworded
  keys). A database language: per screen namespace, `GET /api/i18n/{hash}/{locale}/{namespace}`.
- While the hash in the address is current the answer is `private, max-age=31536000,
  immutable`; any change raises the level's version, so the hash (and address) changes.
- Server side, everything is read from the cache: a level's state (`i18n:v:{scope}`) and its
  texts per version (`i18n:blob:…`). Laravel's translator (`OverlayTranslator`) checks the
  wording of the current request's levels once per request, then the files.

### API

| method | path | who |
|---|---|---|
| GET | /api/i18n/{hash}/{locale}[/{namespace}] | anyone (the current context's wording) |
| GET | /api/organizations/{id}/languages | `multi_language.manage` |
| GET | /api/organizations/{id}/translations?locale&channel&namespace&filter&q&page | `multi_language.manage` |
| PUT | /api/organizations/{id}/translations `{locale, channel, key, value}` | `multi_language.manage` |
| POST | /api/organizations/{id}/translations/reset `{locale, channel, key}` | `multi_language.manage` |
| POST | /api/organizations/{id}/translations/import `{locale, channel, texts: [{key, value}]}` | `multi_language.manage` |
| GET | /api/organizations/{id}/translations/export?locale&channel | `multi_language.manage` |
| GET, PUT, POST | /api/partner/languages, /api/partner/translations… (`level`: partner, or platform for the house partner) | partner staff read, owners change |
| POST, PATCH, DELETE | /api/partner/languages[/{code}] | house partner owners |

### Commands

- `php artisan i18n:missing [locale] [--list]`: how much of each language is translated.
- `php artisan i18n:export {locale} [--dry-run]`: writes a language's platform texts into
  translation files next to the English ones, so a language made in the editor can ship with
  the code (then add it to `tenancy.supported_locales`).

### Future expansion (LANG-1)

- A new language, a partner's own terms, a school's "Grade" for "Class": data only, no code.
- Not yet: suggested translations by AI (with the `ai_assistant` module and consent), wording
  per branch or per person (deliberately left out: more cache variants, more confusion).

## Offline mode and secure sync (Phase 7-1: server)

Code: `app/Platform/Offline`, module `offline_mode` (permissions `offline_mode.use` to work
offline, `offline_mode.manage` for devices and held changes; screen `/offline`). The browser
side is below (Phase 7-2).

### What can be changed offline

A module declares its kinds in its manifest (`'sync_records' => [Provider::class]`); the
provider (`SyncableRecords`) says whether the kind is money, which permission each action
needs, which rules the device needs, applies one change as a fresh request (validation, policy,
tenant scope, **version check: a stale version is a conflict, never an overwrite**) and lists
what changed since a moment (deletions too). The pipeline never touches module tables. No
module has records yet: the tests use fixture notes and cash receipts.

### Devices and leases

- `POST /api/offline/devices` (inside the organization, `offline_mode.use`): registers this
  browser/app and returns a **signed lease**: person, organization, device, kinds, permissions,
  the rules offline work needs with a `rule_version`, and its end
  (`offline_mode.offline_lease_hours`, may differ per role or person). HMAC-SHA256 with
  versioned keys (`config/offline.php`, `OFFLINE_LEASE_KEY_V1`, derived from `APP_KEY` when
  unset) so keys rotate without breaking leases on devices.
- `POST /api/offline/devices/{id}/lease` renews it (online). My account lists the person's
  devices; they or an admin can remove one.

### POST /api/sync (in this order)

1. The person, their device (not removed), the organization (active) and `offline_mode` (on).
2. The lease: genuine, this device/person/organization, not withdrawn; expired = 401
   `lease_expired` (renew online, the changes stay on the device).
3. A known `op_id` returns its stored result: **applied once, however often sent**.
4. Each change must come from a lease this device got, made before that lease ended; it is
   applied as a fresh request (scope, permission, validation, version).
5. **Money is append-only** (no update/delete) and needs `offline_mode.allow_offline_payments`.
   A change made under a lease whose **sensitive rules** changed since is rejected
   (`rules_changed`).
6. From a **removed device, an ended membership, a lost permission, or with offline mode off**:
   every change is **held** (`sync_quarantine`, encrypted) and the answer is **410 with
   `wipe: true`**. Someone with `offline_mode.manage` applies (as their own fresh request) or
   discards each; undecided ones are discarded after `offline_mode.quarantine_days` (30,
   `offline:discard-expired` daily).
7. The answer: a result per change (applied / conflict with the server's record / rejected with
   a reason), what changed since the device's `cursor` per kind, a new cursor and a new lease.
   At most `offline_mode.sync_batch_max` (200) changes per sync.

The endpoint sits outside the organization middleware on purpose (a removed person must still
hand over their changes and be told to wipe); the device and its lease decide the organization.
Turning `offline_mode` off asks every device there to wipe and stops their leases at once.
Audit: `offline.device_registered`, `offline.device_revoked`, `offline.device_wiped`,
`offline.devices_wiped`, `offline.operation_quarantined`, `offline.quarantine_released`,
`offline.quarantine_discarded`, `offline.quarantine_expired`.

### API

| method | path | who |
|---|---|---|
| POST | /api/offline/devices, /api/offline/devices/{id}/lease | offline_mode.use (in the organization) |
| POST | /api/sync, /api/offline/devices/{id}/wiped | the device's person (30/min) |
| GET / DELETE | /api/me/devices[/{id}] | the person |
| GET | /api/organizations/{id}/offline | offline_mode.manage |
| POST | /api/organizations/{id}/offline/devices/{device}/revoke | offline_mode.manage |
| POST | /api/organizations/{id}/offline/held/{held}/release\|discard | offline_mode.manage |

### Future expansion (Phase 7-1)

- A new kind of offline work (attendance marking, stock counts, cash receipts): the owning
  module adds a provider and one manifest line. No sync code changes.
- Open: notifying admins about held changes (Phase 9 alerts),
  maker-checker for releasing held money if a client wants it (a rule).

## Offline mode in the browser (Phase 7-2)

Code: `resources/js/lib/offline` (loaded only on a browser set up for it or where the person
may work offline), `public/sw.js`, header `layouts/OfflineIndicator.vue`, My account → Offline
devices → **This browser**.

- **Encrypted at rest.** One IndexedDB database per person and organization
  (`os-offline:{user}:{org}`). Every value (records, queue, lease, cursor, outcomes) is sealed
  with AES-GCM 256, a fresh IV each time, under a **non-extractable** Web Crypto key kept in the
  same database: script can use it but never read it out. Only ids and kinds stay readable.
- **Queue.** `enqueueOffline(kind, action, data, recordId, baseVersion)` stores the change with
  its `op_id` and `lease_id`, in order. The device refuses early what the server would refuse
  (lease ended, kind not in the lease, money update/delete, offline payments off, missing
  permission) using `kind_info` in the lease; the server still decides everything again.
- **Sync** when the connection returns, every 5 minutes, or from the header button: batches of
  `offline_mode.sync_batch_max`; applied changes leave the queue; conflicts and rejections are
  kept for the person ("Changes that were not applied") until dismissed; server changes and
  deletions are applied; the lease is renewed (once on a 401 `renew_lease`). A dropped
  connection keeps every change and its `op_id`, so resending is safe.
- **Wipe.** A 410 wipe order deletes the database and tells the server (`/wiped`). **Signing out
  wipes every offline database of this browser**; with unsynced changes the person is warned
  first but never blocked (shared computers must be cleared).
- **Service worker** keeps only the app itself (page shell network-first, hashed `/build` files,
  fonts, brand images cache-first) so the app opens offline. It never caches `/api`, session,
  payment or export routes: data offline lives only in the encrypted store. Registered in
  production builds only.

Tests: `tests/js/offline.test.js` (fake-indexeddb): nothing readable at rest, a key that cannot
be exported, separate stores, wipe on sign-out and on a wipe order, queue order and op ids kept
across a dropped connection, early refusals, outcomes, deltas with deletions, batches, lease
renewal.

### Future expansion (Phase 7-2)

- A module screen that works offline calls `offlineRecords(kind)` / `enqueueOffline(...)`; no
  change to the offline library. A native app would reuse the same API and lease.
- Open: resolving a conflict side by side (the server record is already in the outcome).

## Two-step sign-in and passkeys (Phase 8-1)

Code: `app/Platform/Identity` (services `TwoFactorService`, `PasskeyService`, `TwoFactorLogin`,
`SessionSignIn`, `StepUp`, `TwoFactorRequirement`, `MfaResets`), screens My account → **Security**,
the second step on the sign-in page, "Confirm it is you" dialog, members list (resets).
Libraries: `pragmarx/google2fa` (TOTP), `bacon/bacon-qr-code` (QR as SVG),
`web-auth/webauthn-lib` (passkeys), `@simplewebauthn/browser` (loaded only when a passkey is used).

### Second steps

- **Authenticator app (TOTP, RFC 6238).** Secret encrypted at rest, never logged or returned
  after set-up; a code is valid for its 30-second step ± one, and **never twice** (last step
  kept, claimed with a conditional update). The app shows the brand of the address.
- **Passkeys (WebAuthn).** User verification required (fingerprint, face, PIN), discoverable
  (sign in with no email and no password), "none" attestation. Bound to the **exact address**:
  origin and rp_id are the request's own, so a partner's domain keeps its own passkeys. Each
  ceremony has a single-use challenge in the session (2 minutes). Only the public key is kept.
- **Recovery codes.** Ten, shown once (copy / download), stored as SHA-256; each works once;
  made with the first second step or on request.

### Signing in

Every way in goes through `SessionSignIn`: password, API token login, sign-up, invitation,
**password reset** (a reset never skips the second step) and portal join. With a second step the
answer is `{ two_factor: { methods, expires_at } }` and nobody is signed in yet: the waiting
sign-in lives 5 minutes and 5 wrong tries (then the password again). API clients get a
64-character second-step token (hash in cache) and send `POST /api/auth/two-factor`. Audit:
`auth.password_accepted`, `auth.login` (with the method), `auth.second_step_failed`.

### Required by the organization (rules)

| rule | default | levels | |
|---|---|---|---|
| `identity.mfa_required` | false | platform … department, **role** | staff; sensitive |
| `identity.mfa_required_portal` | false | platform … company | portal people; sensitive |
| `identity.mfa_required_partner_staff` | **true** | platform only | partner console and support access |
| `identity.mfa_grace_days` | 7 | platform … company | from the first time it was required |
| `identity.step_up_minutes` | 15 | platform … company | see step-up |

Checked in `ContextResolver` (contract `SignInRequirements`) on **every request** that enters a
context. Inside the grace period `/api/me` gives `user.two_factor.setup_due_at` (banner); after
it the context answers 403 `two_factor_required` while My account → Security stays open. The
security page shows where the requirement comes from (level, name, locked by). Offline sync and
partner API keys are not blocked (a device must still hand over its changes). The last second
step cannot be removed while the current organization requires one.

### Step-up (`two_factor.recent`)

For people with a second step, sensitive actions need one newer than `identity.step_up_minutes`:
member roles, roles, support approval, provider transfer, releasing held offline changes, payment
accounts (create/update/approve/enable), rule approvals (client and partner), partner API keys,
accepting a transfer, resets, and adding or removing second steps. The answer is 403
`step_up_required` with the methods; the app asks and resends. API clients send the code in
`X-Two-Factor-Code`.

### Admin reset (maker-checker)

`security.mfa_reset` (administrator template). One admin asks with a reason, **a different admin**
approves within 24 hours; the person never takes part. Approving removes the app, passkeys and
recovery codes and ends every session and token; the password stays. Only for people who work
nowhere else (no other account, no partner staff role): anyone else uses a recovery code or asks
the platform. Audit: `identity.mfa_reset_requested|approved|rejected`.

### API

| method | path | who |
|---|---|---|
| POST | /session/two-factor, /session/passkey/options, /session/passkey | signing in (throttle `two-factor`) |
| POST | /api/auth/two-factor | API clients, second step |
| GET | /api/me/security | the person |
| POST / DELETE | /api/me/security/totp, …/totp/confirm, …/recovery-codes | the person (+ step-up) |
| POST / PATCH / DELETE | /api/me/security/passkeys[/options][/{id}] | the person (+ step-up) |
| POST | /api/me/security/confirm[/options] | step-up |
| GET / POST | /api/organizations/{id}/mfa-resets, …/members/{m}/mfa-reset, …/mfa-resets/{r}/approve\|reject | security.mfa_reset |

Local development: passkeys need HTTPS except on `PASSKEY_INSECURE_HOSTS` (default `localhost`).

### Future expansion (Phase 8-1)

- A new country, sector or partner needs no code: requiring it is a rule at any level (per role
  too), partners get their own passkeys on their own domains automatically.
- Open: role-level rule values have no screen yet (Phase 4 tooling); resets for partner staff and
  people in several accounts (platform support); SMS as a second step (weaker, not planned);
  e-mailing the person when a reset is approved (Phase 9 alerts).

## Platform hardening (Phase 8-2)

Every Phase 8 layer with its evidence: [docs/security-checklist.md](docs/security-checklist.md).
Server-side guides: [least-privilege DB users](docs/ops/db-least-privilege.md),
[server hardening](docs/ops/server-hardening.md), [backups](docs/ops/backups.md).
Code: `app/Platform/Security`, settings: `config/security.php`.

### What the app enforces

- **Tenant id in the address, checked centrally.** `ResolveOrganization` (and `ResolvePartner`,
  `AuthenticatePartnerKey` for `{client}`) refuses an `{organization}` the context may not see
  with the same 404 as a missing one, before validation or the controller runs. A real
  organization of someone else is logged as `tenant.cross_access_attempt`.
- **General API limits** (per minute): 120 per person, 600 per organization, 300 per address
  (`SECURITY_API_*`, placeholders). The address limit (`ThrottleByAddress`) runs before sign-in,
  so requests with bad tokens are counted too. Each endpoint keeps its own stricter limit.
  Behind a load balancer set `TRUSTED_PROXIES`.
- **Headers on every response:** `nosniff`, `Referrer-Policy`, and HSTS on HTTPS
  (`SECURITY_HSTS_*`); pages also get the nonce CSP and `X-Frame-Options`.
- **Lists** are capped at 100 per page (`PerPage::from`).
- **Security log** (`storage/logs/security.log`, JSON lines): failed sign-ins, refused (403) and
  rate-limited (429) answers, cross-tenant attempts, and sensitive audit actions
  (`security.logged_actions`). Ids and codes only, never emails, passwords, codes or tokens.
- **Static checks** (`tests/Feature/Architecture/ApplicationSafetyTest`): strict form requests,
  no whole-request mass assignment, no raw SQL, no unescaped HTML, every model declares its
  fields, no uploads on the public disk, no debug output.
- **Public endpoints** are a reviewed list (`tests/Feature/Security/PublicRoutesTest`); a new one
  fails the tests until it is added there with its protection.

### Commands

| Command | Purpose |
|---------|---------|
| `php artisan security:check [--strict]` | run on every deploy; exit 1 in production while a setting is unsafe, with what to change |
| `php artisan backup:run [--no-prune] [--generate-key]` | encrypted, signed backup set (database + private files); nightly |
| `php artisan backup:restore-drill [--set=]` | restore into `BACKUP_DRILL_DATABASE` and check; monthly |

Backups need PHP's `sodium` extension (`ext-sodium` in composer.json) and the database's own dump
tools; on Laragon set `BACKUP_MYSQLDUMP` / `BACKUP_MYSQL` (see `.env.example`).

### CI

- `tests.yml`: Pint, Pest, and a real backup + restore drill on MySQL and PostgreSQL.
- `supply-chain.yml`: `composer audit`, `npm audit --audit-level=high`, CycloneDX SBOM (artifact),
  also weekly. `dependabot.yml`: weekly update pull requests.

### Future expansion (Phase 8-2)

- A new country, sector or partner needs no code: limits and headers are platform settings,
  partner domains get HSTS automatically, new endpoints are swept by the tests on their own.
- Open: alerts on security-log spikes and the incident playbook (Phase 9); an S3 disk with object
  lock for off-site backups (needs the bucket); AI prompt-injection tests with the first AI feature;
  measuring real traffic to replace the placeholder limits.

## Audit log (Phase 9-1)

### Core audit (always on, every plan)

`audit_logs` is append-only (the model refuses updates and deletes). Every change to money,
payroll, rules, modules, permissions, branding, domains and every sign-in is recorded, whether or
not `advanced_audit` is on (`tests/Feature/Audit/AuditCoreTest`). Each entry names who (person or
partner API key), where (organization, partner), what (action, target, old/new values, reason),
from where (IP, user agent, **offline device** `device_id`, **browser session** `session_id`).

The audit screen filters by period (days in the organization's time zone), area (`rule`) or
exact action (`rule.changed`), and person: `GET /api/organizations/{id}/audit-log?from=&to=&action=&actor=`.

External store: `AUDIT_SHIP_DRIVER=log` writes every entry once, in order, as a JSON line to
`storage/logs/audit.log` (`php artisan audit:ship`, every minute, 60 s lag so no entry is
skipped); the log collector carries it to write-once storage. A new store = a new
`AuditShipper` driver.

### advanced_audit module (business, enterprise)

| Endpoint | Permission | What |
|----------|------------|------|
| GET …/audit-log/report?from=&to= | advanced_audit.view | per day, top actions, most active people, support visits, money entries (≤ 92 days) |
| GET / POST …/audit-log/exports | advanced_audit.export | CSV of a period (≤ 366 days, 200 000 rows), built in the background, one at a time |
| GET …/audit-log/exports/{id}/link | advanced_audit.export | a signed link for 5 minutes; the file stays for `exports.retention_days` |

CSV cells that a spreadsheet would run as a formula are prefixed with `'`; the file starts with a
UTF-8 BOM so Bangla shows correctly. Requests and downloads are audited.

Retention rules (sensitive: a second person approves; country-specific):

| Rule | Default | Limits |
|------|---------|--------|
| `advanced_audit.retention_days` | empty = keep for ever | ≥ 365 days |
| `advanced_audit.money_retention_days` | empty = keep for ever | ≥ 2922 days (8 years, placeholder for the adviser) |

`php artisan audit:prune` (nightly) removes entries past these, only where the module is on,
per company (with its units) or group (its own entries); entries without an organization are
never removed; each removal is recorded as `audit.pruned` with counts. Money actions:
`config/audit.php` `money_actions`.

### Future expansion (Phase 9-1)

- A new country sets its legal retention as a country value of the two rules; a new module's
  money actions are one line in `money_actions`; a partner can lock retention for all clients.
  No code change.
- Open: a hash chain for tamper evidence across entries; a SIEM driver (HTTP) for shipping.

## Alerts, health and incident response (Phase 9-2)

Code: `app/Platform/Monitoring`, settings: `config/monitoring.php`, playbook:
[docs/incident-playbook.md](docs/incident-playbook.md).

### Security alerts

Every security-log event (`SecurityEventRecorded`) is counted per alert kind and group in the
cache; at the threshold one `security_alerts` row is raised and delivered by a queued job. While
it stays open (within its cooldown, not acknowledged) repeats only count up; nobody is told twice,
and a channel that was down is retried without resending the others.

| Kind | Watches | Default (placeholder) | Told |
|------|---------|-----------------------|------|
| brute_force_account | failed sign-ins / second steps of one account | 10 in 10 min | operators, the person (every address) |
| brute_force_address | failed sign-ins from one address | 30 in 10 min | operators |
| access_denied_spike, rate_limited_spike | 403 / 429 per person or address | 50 / 100 in 5 min | operators |
| cross_tenant_attempt | another tenant's id in an address | every one | operators |
| data_export | data or audit log export requested | every one | operators, the organization |
| module_switched_off, sensitive_rule_changed, mfa_reset | module off or purge, approved sensitive rule, sign-in reset | every one | operators, the organization |
| partner_api_key_created | new partner API key | every one | operators, the partner's owners |
| restore_drill_failed | the monthly restore drill failed | every one | operators |

Operators: `MONITORING_MAIL_TO`, `MONITORING_SLACK_WEBHOOK`, `MONITORING_SMS_TO`, each with a
minimum severity. Organizations: the people who may read its audit log (`security.alert`,
partner-branded, fixed wording, bn/en). `php artisan security:alerts [--all] [--ack=id --note=]`.

### Health

`php artisan health:report [--json]` and `GET /internal/health` (`Authorization: Bearer
$HEALTH_TOKEN`; 404 without it; 503 while a check fails): database, cache, queue depth, failed
jobs (24 h), rejected offline changes (24 h), rule cache hit rate, last backup, last restore
drill, scheduler heartbeat (`monitor:heartbeat` every minute), open alerts. Counts and times only.

### Incident response

`php artisan security:revoke --user=<email|id> | --organization=<id> | --partner=<id|slug>
--reason="…" [--force]`: API tokens, browser sessions, partner API keys and offline devices, at
once; audited as `security.access_revoked`.

### Future expansion (Phase 9-2)

- A new alert is a config entry (events, group, threshold, who is told); a new channel is one
  method; partners and countries need no code.
- Open: an operator console (UI) for alerts and health; per-partner alert recipients; tuning the
  placeholder thresholds with real traffic.

## Tenant databases (Phase 10-2)

Code: `app/Platform/Tenancy/Databases`, settings: `config/tenant_databases.php`.

A client tree (a group, or a stand-alone company, with everything below it) keeps its **business
data** in one database: the main one (default, shared with every other client), a **dedicated**
one, or its region's (**regional**). **Platform data** (organizations, people, memberships, roles,
modules, rules, plans, billing, audit, identity) always stays in the main database. We did not use
stancl/tenancy: it assumes one tenant per request picked by domain, while here group admins,
partner consoles and platform jobs work across a whole tree against shared platform tables.

- A business table's migration goes in `database/migrations/tenant` or
  `Modules/<Module>/database/migrations/tenant`: it must have `organization_id` and no foreign key
  to a platform table (no cross-database keys). Its model uses `BelongsToOrganization` **and**
  `UsesTenantDatabase`. `tests/Feature/Architecture/TenantDatabaseTablesTest` enforces all of this;
  module models with `BelongsToOrganization` must use `UsesTenantDatabase`.
- `tenant_placements` (main database) names the database of a tree; no row = main database. Only
  a name is stored; how to reach it comes from the environment:

  ```dotenv
  TENANT_DATABASES=eu1,acme
  TENANT_DB_EU1_URL=pgsql://user:secret@10.0.0.5:5432/erp_eu1
  TENANT_DB_ACME_DATABASE=erp_acme        # anything not given is copied from DB_*
  TENANT_REGION_DATABASES=eu=eu1          # new clients in region "eu" start in eu1
  ```

- The database is chosen per query: code forced by system code (`TenantDatabases::within()`,
  `Model::inTenantOf($org)`), else the signed-in context's tree, else the record's own
  organization. Placements are read fresh per request and job, never from a shared cache; a name
  that is not configured stops with an error instead of falling back to another database.
- Across databases the organization scope filters by the context's visible organization ids
  (it cannot join `organizations` there). Moving a unit into a tree kept in another database is
  refused (`move_cross_database`).
- While a tree's data moves (`status = moving`), reading works and every change answers 503
  `data_moving` with a bn/en message.
- Offline sync (Phase 7) applies each change in a transaction on the client's database and stores
  a marker (`offline_applied_operations`, a tenant table) next to the business records. The
  platform's `sync_operations` answer is written afterwards in the main database; if it is lost,
  a resent change finds the marker and is not applied twice. While the data moves, a change's
  result is `retry_later` (`data_moving`): nothing is stored, the device keeps it, and the rest of
  the sync (changes, new lease) goes on. Releasing a held change then answers 503 and keeps it held.
- Backups dump every tenant database into its own encrypted file in the same set
  (`tenant-<name>.sql.gz.enc`); the restore drill restores each into `<drill database>_<name>` and
  never into a live one. The health report checks each tenant database (`tenant_databases`) and
  warns about a move unfinished after 120 minutes.

Commands (operators; every change audited as `tenant_database.*`, visible in the client's audit
log):

```bash
php artisan tenants:migrate [--database=eu1]      # tenant tables in dedicated / regional databases
php artisan tenants:place <root-org-id> acme --reason="Large client"   # only while it has no business data
php artisan tenants:place <root-org-id> --shared --reason="…"
php artisan tenants:list
```

### Future expansion (Phase 10-2)

- A new sector, country or partner needs no code: a region database is two environment lines.
- A new module adds its business tables under `database/migrations/tenant` and they follow their
  clients automatically (move tool, backups, health).
- Today no real module has business tables yet; the test fixture `TenantNote` proves the
  mechanism. `merchant_accounts` and `mfa_resets` stay in the main database (they are referenced
  by, or reference, platform tables).
- Open: per-region platform data (people and audit of a region's clients stay in the main
  database for now).

## Moving clients, replicas and analytics (Phase 10-3)

### Moving a client to another database

```bash
php artisan tenants:migrate --database=acme            # the target has the tenant tables
php artisan tenants:move <root-org-id> acme --reason="Large client"   # or --shared to go back
php artisan tenants:purge-source <root-org-id> --confirm=<root-org-id> --reason="…"   # after retention
```

1. The client is marked `moving`: reading works, changes answer 503 `data_moving` (bn/en), offline
   changes wait on the device (`retry_later`).
2. After `TENANT_MOVE_SETTLE_SECONDS` (5), every tenant table's rows of the tree are copied in id
   order, then both sides are compared per table: row count and a SHA-256 checksum computed in PHP
   over every column (the same on MySQL and PostgreSQL).
3. Only when every table matches does the placement switch. Otherwise the copy is deleted, the
   client stays where it was and `tenant_database.move_failed` names the tables that differed.
4. The old rows stay in the old database for `TENANT_MOVE_RETAIN_DAYS` (30, placeholder), then
   `tenants:purge-source` removes them (typed confirmation). Moving back to that database replaces
   the stale copy with the current data.

Every move is a `tenant_moves` row with its report, and `tenant_database.*` audit entries the
client sees in its own log. Both databases must be the same server kind (MySQL or PostgreSQL).
`tests/Feature/Tenancy/TenantIsolationTest` runs the whole isolation suite on a group moved this way.

### Read replicas and the reporting database

- `DB_READ_HOST=10.0.0.8,10.0.0.9`: reads go to replicas, writes to `DB_HOST`; `sticky` reads a
  request's own writes back from the primary.
- `DB_REPORTING_HOST` (+ optional `_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD`): audit reports,
  audit exports and the analytics export read there (`ReportingDatabase`); without it, the main
  database. The audit screen itself always reads the primary. Health: `reporting_database`.

### Analytics store

`ANALYTICS_DRIVER=none|jsonl|clickhouse`. `analytics:export` runs every 15 minutes and queues
`ExportAnalytics`, which sends new rows in order with a cursor (`analytics_cursors`), so nothing is
skipped or sent twice; a refused batch keeps the cursor where it was. Dataset `audit_events`:
`id, created_at, partner_id, organization_id, action, area, target_type`, nothing about people,
addresses, values or reasons.

ClickHouse: `ANALYTICS_CLICKHOUSE_URL`, `_DATABASE` (onesolution), `_USERNAME`, `_PASSWORD`; create
the table once, e.g.

```sql
CREATE TABLE onesolution.audit_events (
  id String, created_at DateTime, partner_id Nullable(String), organization_id Nullable(String),
  action LowCardinality(String), area LowCardinality(String), target_type Nullable(String)
) ENGINE = ReplacingMergeTree ORDER BY (created_at, id);
```

### Future expansion (Phase 10-3)

- A new analytics dataset is one method in `AnalyticsExport` and one table in the store; a new
  store is one `AnalyticsSink` class. Partners, countries and sectors need no code.
- Open: business-module datasets (none have tables yet); replicas for tenant databases
  (`TENANT_DB_<NAME>_…` has no read split yet); an operator screen for moves.

## Independent validation and release (Phase 11)

### Coverage of the critical paths

CI job `coverage` (pcov) runs the suite and `scripts/coverage-gate.php` against
`tests/critical-coverage.php`: at least 90% of the statements of auth (`Identity`), `Tenancy`,
`Rules`, `Access`, offline sync (`Offline`), `Billing` and `Payments`, and **every** statement of
22 decision classes (scopes, context, policies, access, rule resolution and validation, two-step
sign-in, the sync pipeline, money). Code left out of coverage needs a reason on the next line
(`ApplicationSafetyTest`); today three spots: dates the API validates first, two payment races.
Locally (pcov installed, off by default):

```bash
php -d pcov.enabled=1 -d pcov.directory=app vendor/bin/pest --coverage-clover storage/clover.xml
php scripts/coverage-gate.php storage/clover.xml
```

### Security test and release

- [docs/security/pentest-scope.md](docs/security/pentest-scope.md): scope, accounts, rules of
  engagement, reporting, written authorization. Testing only by an authorized party, on staging.
- [docs/security/endpoints.md](docs/security/endpoints.md): every endpoint and how it is
  protected, generated by `php artisan security:endpoints --write` (a test fails when it is stale;
  PublicRoutesTest keeps the list of public endpoints reviewed).
- `php artisan db:seed --class=PentestWorldSeeder`: two separate partner worlds with one account
  per role for the testers (staging only; refuses production; passwords printed once).
- [docs/security/findings.json](docs/security/findings.json): the remediation tracker (ids,
  severity, status, neutral titles; fixed findings name their commit and an existing regression
  test).
- `php artisan release:check [--production]`: refuses a release while a critical or high finding is
  open, the tracker is malformed, migrations are pending, a tenant database misses its tables, or
  the last backup or restore drill is not good; with `--production` also unsafe server settings.
- [docs/release-checklist.md](docs/release-checklist.md) and
  [docs/rollback-plan.md](docs/rollback-plan.md).
- Laravel's generic `/storage/{path}` route is off (`serve => false` on the local disk): private
  files leave only through the app's own signed, audited downloads.

### Future expansion (Phase 11)

- A new module's decision classes go into `tests/critical-coverage.php` (payroll calculations
  first, once the module has code).
- Open: the staging host, the testing party and dates; the test itself.

## HRM module (business module 1: HRM-1 backend, HRM-2 screens)

Code: `Modules/Hrm` (the first business module with its own tables, routes and services; platform
code is never changed for it, it only uses platform contracts). Turn it on per level like any
module (`module:hrm` on every route; 403 while off, data kept).

- **Positions** (`hrm_positions`): titles in every supported language, code, grade, active flag;
  a unit sees its own and those of the units above.
- **Employees** (`hrm_employees`): work in a company, branch or department (never a group);
  code from `hrm.employee_code_format` (`{UNIT} {YYYY} {YY} {SEQ:n}`, running number per company
  and year under a row lock); probation from `hrm.probation_days`; kinds of employment and
  required details from rules. National and tax ids are **encrypted**, masked (`••••1234`) in
  every answer; the full values only from `GET …/employees/{id}/sensitive` (`hrm.view_sensitive`,
  recent second step, audited). A keyed hash finds a national id already used in the company.
  Never deleted: employment ends with an exit.
- **Employment steps** (`POST …/employees/{id}/steps/{confirm|transfer|promote|notice|exit|rehire}`,
  with `base_version`): each writes an append-only `hrm_employment_events` row, an audit entry and
  `Modules\Hrm\Events\EmploymentChanged` (`hrm.employee.*`, ids and dates only) for other modules.
  Notice and exit need `hrm.exit`; the notice period is `hrm.notice_period_days`. A transfer stays
  inside the company (`BelongsToOrganization::reassigning()`, the only way a record changes unit).
- **Documents** (`hrm_documents`): kinds `hrm.document_types`, size `hrm.document_max_kb` (plan
  level and above), PDF/JPG/PNG/WebP by content, private disk, opened through a 5-minute signed
  link (`/files/hrm/…`), every add, open and removal audited.
- **Portal**: `hrm.employee` subject with relation `self`: an employee sees their name, code,
  position, unit, dates, status and contact, never ids or documents.
- **Data export**: `HrmExporter` (`module.exporters`) adds positions, employees (with ids: the
  client owns them), history and the document list to the client's export.
- Tables are tenant tables (`Modules/Hrm/database/migrations/tenant`), so HRM data follows a
  client into a dedicated or regional database and through `tenants:move`.

Permissions: `hrm.view`, `hrm.manage`, `hrm.view_sensitive`, `hrm.exit` (the `hr_officer` template
has `hrm.*`). Audit texts: `Modules/Hrm/lang/*/audit.php` (any module can name its actions this way).

### Screens (HRM-2)

Code: `Modules/Hrm/resources/js` (routes, pages, components, `locales/{en,bn}/hrm.json`).

- **Employees** (`/hrm`): search by name or code, filter by status, paged list with status badges.
- **Hire someone** (`/hrm/new`): three steps (person, job, check and save). Required details,
  kinds of employment, the national id's name and the probation end come from
  `GET …/hrm/form-options?unit_id=` — rule values of the chosen unit, each with where it comes
  from (`self`, `inherited` from a level, or the `default`) and the `can` flags for that unit.
- **Employee** (`/hrm/employees/{id}`): overview, personal details (edited with `base_version`;
  masked ids revealed only with `hrm.view_sensitive`), history timeline, documents (upload within
  the rule's size, open through the signed link, removal confirmed). Only the steps the status
  and permissions allow are offered; the server checks again.
- **Positions** (`/hrm/positions`): add and edit titles in English and Bangla, hide unused ones.

Every screen has loading, empty and error states, works at phone width and in Bangla, and is
its own lazy chunk. The shell is not changed by a module (see "Module screens" below).

### Extra fields and imports (HRM-3a)

- **Extra fields** (`hrm_custom_fields`, values in `hrm_employees.custom`): set up at a company,
  branch or department (`hrm.configure`), filled in for that unit and the units below. Kinds:
  text, number (kept as a decimal string), date, choice (labels in every language), yes/no.
  Key and type never change; choices already offered stay; fields are switched off, never
  removed (values stay). How many are in use per company: rule `hrm.custom_fields_max`.
  Hiring, editing and importing check values the same way (`Services\CustomFields`).
  `GET …/hrm/custom-fields?unit_id=&all=1`, `POST`, `PATCH …/custom-fields/{id}` (with
  `base_version`); form-options lists the unit's fields with where they come from.
- **CSV import** (`hrm_imports`, `hrm_import_rows`, needs `hrm.manage`): `GET …/imports/columns`
  (template columns, extra fields as `custom_<key>`), `POST …/imports` (file: checked row by row,
  nothing hired), `GET …/imports/{id}` (problems per row and column), `POST …/{id}/start`
  (`skip_invalid` when some rows have problems), `DELETE …/{id}` (cancel). The file is never
  stored; row details are encrypted and wiped when the import ends; `hrm:prune-imports` (daily)
  cancels imports left unstarted for 7 days. UTF-8 (with or without BOM), comma, semicolon or
  tab; dates `YYYY-MM-DD` or `DD/MM/YYYY`; units, positions and managers by code; cells starting
  with `=` or `@` refused. Limits: rules `hrm.import_max_rows` (500) and `hrm.import_max_kb`
  (1024). One running import per company. `Jobs\ImportEmployees` runs as the starter, checks
  `hrm.manage` again, skips while HRM is off, and never hires a row twice.
- Problem messages are written in the uploader's language when the file is checked.

Rule cache: resolved rule maps are keyed by a fingerprint of the rules defined in code too, so
a deploy that adds a rule or changes a default never reads a map cached before it.

### Reporting lines, org chart and document expiry (HRM-3b)

- Reporting lines stay a tree: a manager change that would close a loop is refused with the
  loop spelled out ("A → C → B → A"), and a line may only be `hrm.max_reporting_depth`
  managers long (20). New hires and imports cannot make loops (nobody reports to them yet), so
  the check matters on edits and rehires; it runs in the same validation for all of them
  (`Services\ReportingLines`).
- `GET …/hrm/org-chart[?manager_id=]` (hrm.view): one level at a time, employed people only,
  list fields only, up to 200 per level with the total. The top is everyone with no manager
  inside the units the reader sees. Screen: `/hrm/org-chart` (sidebar "Org chart").
- Document expiry: rule `hrm.document_expiry_alert_days` ([30, 7, 1]; empty = off).
  `hrm:document-expiry` (daily 01:30 UTC, every client database) sends one message per
  company to the people holding `hrm.manage`, listing each document due at a stage it has
  not been reported at yet, and once more when it has expired (`hrm_document_alerts`,
  stage = days or -1). Skips companies where HRM is off. Only the person's name, the
  document title and the date are sent.
- The same documents show in the HRM dashboard ("Documents to renew", also on the overview),
  in the bell for people who manage employees, and as badges (icon + words) on the employee's
  documents.
- Modules may now declare their own notifications in the manifest (`notifications`, keys
  "{module}.{name}"); wording lives in `{module}::notifications` and partners can reword them
  like the platform's.

### Future expansion (HRM)

- A new country or sector needs no code: kinds of employment, required details, the id
  document, probation and notice are rules (country-specific where it matters).
- A sector's own employee details (MPO number for schools, licence for clinics) are extra
  fields an organization sets up itself; no code change.
- Reminder days, line length and who is told are rules or permissions: a country or partner
  changes them as data. Salary and bank details belong to Payroll.

## Accounting module (business module 2: ACC-1 general ledger backend)

Code: `Modules/Accounting`. Books are kept per **company** (or personal workspace); branches and
departments are **cost centres** on journal lines. `module:accounting` on every route; 403 while
off, data kept. All tables are tenant tables (`database/migrations/tenant`), so the books follow
a client into a dedicated or regional database.

- **Setup** (`GET|POST …/accounting/setup`, `accounting.manage`): copies a chart template
  (`database/data/charts/{general,school,factory,retail}.php`, names in en/bn, sector templates
  extend `general`) into the company's own accounts, maps posting accounts, adds the first fiscal
  year. The template is the rule `accounting.chart_template` (sector packages set it). Once.
- **Accounts** (`acc_accounts`): code, translated name, type (asset, liability, equity, income,
  expense), group headings (`is_group`). Sub-accounts take their group's type. Type and kind are
  fixed once an account has entries or sub-accounts; archive needs a zero balance, no posting
  mapping and no active sub-accounts. Never deleted.
- **Fiscal years and periods** (`acc_fiscal_years`, `acc_periods`): 12 monthly periods from
  `accounting.fiscal_year_start` (country value, 07-01 for BD); later years follow without gaps.
  `POST …/periods/{id}/close|reopen` (`accounting.close`; reopen needs a reason; refused while
  entries in the period wait for approval).
- **Journals** (`acc_journals`, `acc_journal_lines`): integer minor units + currency (the
  company's). `draft → submit → posted`, or `→ pending_approval → approve|reject|withdraw` when
  the total is above `accounting.journal_approval_above` (an amount in another currency always
  waits). The writer or sender never approves (`own_journal`), and `accounting.post` /
  `accounting.approve` are a separation-of-duties pair. People date entries inside
  `allow_backdated_entries_days` / `allow_future_entries_days`; every posting needs an open
  period. Posted journals never change (model guard): `reverse` writes a new journal with sides
  swapped. Numbers are given at posting from `accounting.journal_number_format`
  (`{YYYY} {YY} {FY} {SEQ:n}`, one sequence per fiscal year, row-locked), so they have no gaps.
  `accounting.require_cost_centre` makes a branch or department compulsory on every line.
- **Period totals** (`acc_balances`): per account, period and cost centre, written in the posting
  transaction (the period row is locked first). Reports read whole periods from here and the cut
  days of a period from the lines; sums are made in PHP (no raw SQL, same on MySQL and PostgreSQL).
- **Reports** (`GET …/reports/{trial-balance|ledger|profit-loss|balance-sheet}`, own limiter
  `accounting-reports`): `as_of` or `from`/`to`, optional `cost_centre_id` (its units included).
  Until year-end closing (ACC-4) the balance sheet shows profit to date as `earnings_to_date`.
- **Other modules post through `Modules\Accounting\Services\Ledger::post()`** only: a
  `LedgerEntry` with `op_id` (same entry twice = one journal), source module/type/id and lines on
  **posting keys** the module declares in its manifest (`ledger_accounts`, e.g.
  `payroll.salary_expense` with its account type). Each company maps keys to its own accounts
  (`GET|PUT …/posting-accounts/{key}`); no account is hardcoded. Call it from a queued job (no
  tenant context) or at the company; a branch-level context cannot write the company's books.
  `accounting.journal.posted` (`JournalPosted`, ids only) follows every posting.
- **Data export**: `AccountingExporter` adds accounts, years, periods, journals, lines and
  posting accounts to the client's export.

Permissions: `accounting.view`, `accounting.post`, `accounting.approve`, `accounting.manage`,
`accounting.close` (templates: `accountant` posts and manages, `finance_approver` approves and
closes). Owners hold neither side of the post/approve pair: they give these to people.
Rules: `fiscal_year_start`, `journal_approval_above`, `allow_backdated_entries_days`,
`allow_future_entries_days`, `journal_number_format`, `chart_template`, `require_cost_centre`.

```bash
php vendor/bin/pest tests/Feature/Accounting
```

### Screens (ACC-2)

Code: `Modules/Accounting/resources/js` (routes, pages, `components/BooksGate.vue`, `lib.js`,
`locales/{en,bn}/accounting.json`). Sidebar section **Finance**.

- **Journal entries** (`/accounting`): filters (status, dates, search), paged list with status
  badges; `/accounting/approvals` lists entries waiting for approval.
- **Journal form** (`/accounting/journals/new`, `…/:id/edit`): lines with account, debit,
  credit and branch/department; running totals and the difference; a new line offers the
  balancing amount; "Save draft" or "Save and send". Amounts are typed as text (Bangla digits,
  separators and ৳ accepted) and converted to minor units without floats (`parseAmount`).
- **Journal page**: lines, status, source, links between an entry and its reversal; buttons
  from the server's `can` (send, take back, approve with confirmation, reject and reverse with
  a reason, change or remove a draft); printable.
- **Chart of accounts**, **Reports** (trial balance, profit and loss, balance sheet, ledger;
  per branch/department; printable), **Set up the books**, **Fiscal years** (close/reopen with
  reason), **Posting accounts**. Every screen shows the setup offer while the books are not set
  up, and the server's message at a group, branch or department.
- **Dashboard** widgets: income and expenses this month (vs the same days last month, six-month
  trend), entries waiting for approval, latest entries; the bell counts entries an approver may
  approve (not their own). Quick action "New journal entry".
- **Personal workspaces**: `access.separation_of_duties` may now be set at plan level; the
  personal plans set it to `[]` (`database/seeders/data/rule-values.php`), so the one person of
  a workspace writes and posts entries. Upgrading to a business plan brings the pairs back.

### Receivables and payables (ACC-3a)

- **Parties** (`acc_parties`, `…/parties`): customers and vendors (or both), contact details,
  own payment terms; a CRM contact is linked by id only. Customers need `accounting.sell`,
  vendors `accounting.buy`.
- **Documents** (`acc_documents`, `acc_document_lines`, `…/documents`): invoices and credit notes
  (sales, income accounts), bills and vendor credits (purchases, expense or asset accounts).
  Lines: quantity as text with up to 3 decimals (stored in thousandths), unit price in minor
  units, amount = quantity × price rounded half up. Due date from the party's terms, else
  `accounting.payment_terms_days`. `submit` posts at once, or waits for approval above
  `journal_approval_above` (approved by someone else with `accounting.approve`; the approved
  document's journal posts without a second approval). Posting numbers it
  (`accounting.document_number_formats`, one sequence per kind and fiscal year) and writes a
  journal: receivable (posting key `accounting.receivable`) against the lines' accounts, or the
  lines against payable (`accounting.payable`); credits the other way round.
- **Settlements** (`acc_settlements`, `…/settlements`): money received (receipts) and paid
  (payments) into or out of an asset account, with `op_id` so a repeat is harmless, approval
  above the amount as for documents, and **allocations** (`acc_allocations`) to the party's open
  invoices or bills. Unless `accounting.allow_overpayment`, the whole amount must be allocated;
  otherwise the rest is an advance, allocated later (`…/settlements/{id}/allocate`). Credit notes
  and vendor credits are applied the same way (`…/documents/{id}/apply`).
- **Void** (`accounting.approve`): a settlement's journal is reversed and the documents it paid
  are owed again; a document can be voided only when nothing is set against it. Journals made by
  documents, settlements or other modules cannot be reversed on the journal page
  (`sourced_journal`): they are undone where they came from.
- **Reports**: `reports/aging?side=sales|purchases&as_of=` (open amounts by days overdue,
  columns from `accounting.aging_buckets`, less unused credits and advances; correct for any past
  day) and `reports/statement?side=&party_id=&from=&to=` (opening, running and closing balance).
- Separation of duties: `accounting.sell` and `accounting.buy` are each paired with
  `accounting.approve`.
- `php artisan accounting:map-postings`: after a release that adds posting keys, gives companies
  that already keep books the accounts their chart template names (only unmapped keys; audited;
  safe to run again). Run it on deploy of ACC-3.

### Sales and purchases screens (ACC-3b)

Sidebar (Finance): **Sales** (`/accounting/customers`, `/accounting/sales`, `/accounting/receipts`)
and **Purchases** (`/accounting/vendors`, `/accounting/purchases`, `/accounting/payments`). A
module may now have several menu entries: only its first gets the Dashboard and Settings links,
and the sidebar lights the entry with the longest matching link.

- **Customers / vendors**: list, add and change (`PartyDialog`), a party page with what it owes
  (or has paid ahead), its documents and a printable statement for any range.
- **Invoices / bills**: lists with status, overdue only and search (each row: what is still due
  and when); a form with description, quantity, unit price, account (income for sales, expense or
  asset for purchases) and branch, amounts worked out as the server does (`lineAmount`); a
  printable document with the company's name and logo; approve, reject, void (with a reason),
  apply a credit, record money against it.
- **Money received / paid**: a form that lists the party's open documents with "fill oldest
  first" (`AllocationPicker`, `autoAllocate`); one op id per form, so pressing twice records once;
  a record page with approve, reject, void and allocate later.
- **Approvals** (`/accounting/approvals`): journal entries, documents and money waiting for a
  second person, in tabs. **Reports**: an Aging tab (sales or purchases, any day).
- **Dashboard**: customers owe, overdue from customers, owed to vendors; the waiting count and the
  bell include documents and money.

### Customer portal and online payment (ACC-3c)

- **Portal subject `accounting.customer`** (relation `self`, `Modules\Accounting\Portal\CustomerSubjects`):
  the client links a portal member (guardian, shop customer, client firm) to one of its customers,
  as for any portal record. It implements the new optional `PortalSubjectPage`: the portal home
  opens the module's own screen (`/portal/invoices?customer=…`) instead of the plain details.
- **Portal API** (`/api/portal/accounting/invoices`, `…/{id}`, `…/{id}/pay`; `client_portal` and
  `accounting` on): only the linked customers' posted invoices and credit notes (never drafts,
  waiting, voided, other customers, accounts or journals; anything else is the same 404). Screens:
  `/portal/invoices` (what is still due, the list) and `/portal/invoices/:id` (printable, lines
  stacked on phones, "Pay online").
- **Online payment** (`payment_collectables`: `Modules\Accounting\Payments\InvoiceCollectable`,
  kind `accounting.invoice`): due = the invoice balance, only for the linked customer and while
  `client_portal.allow_online_payment` is on; the money goes to the company's own merchant account
  (`CollectPayment`). When the gateway confirms, `Settlements::recordOnline` writes a receipt into
  the posting key `accounting.online_collections` (template: 1130) without approval, set against
  the invoice as far as it is still due (the rest is an advance: the money was taken), with op id
  `payment-{id}` so a repeated notice is harmless. The payer returns to the invoice page.

### Tax / VAT (ACC-4a)

- **Tax codes** (`acc_tax_codes`, tenant table): code, translatable name, `rate_bp` (basis points,
  1500 = 15%), kind (`standard`, `reduced`, `zero`, `exempt`), side (`both`, `sales`, `purchases`),
  on/off, version. Setting up the books copies the codes of the company's country tax profile
  (`database/data/tax/{profile}.php`; Bangladesh: `bd_vat.php`, 15 / 10 / 7.5 / 5 / zero / exempt).
  **These rates are placeholders: the company's tax adviser must review them.** People with
  `accounting.tax` add, rename, change or switch off codes (screen `/accounting/tax-codes`).
  `php artisan accounting:seed-tax-codes` adds missing country codes to books set up earlier and
  never changes codes a company edited (safe to run again).
- **On invoices, bills and notes**: each line may carry a tax code of its side. The line keeps
  the code, the rate it was written with and its tax (`tax_code_id`, `tax_rate_bp`, `tax_minor`);
  the document keeps `net_minor`, `tax_minor`, `total_minor` and `prices_include_tax`.
  Rule `accounting.prices_include_tax` (default off) decides whether prices are before VAT or
  include it. Integer maths only, rounding half up per line: on top `tax = round(net × r / 10000)`;
  inside `net = round(gross × 10000 / (10000 + r))`, `tax = gross − net`.
- **Posting**: sales VAT (output) goes to posting key `accounting.tax_output` (template 2130),
  purchase VAT (input) to `accounting.tax_input` (template 1170, "VAT receivable"), one line per
  code; credit and debit notes reverse it. Books set up before ACC-4a have no 1170: map
  `tax_input` on the posting accounts page (or add the account, then run
  `accounting:map-postings`).
- **VAT report** (`reports/vat?from=&to=`, tab "VAT" on Reports): per code, taxable sales and
  output VAT, taxable purchases and input VAT, then VAT payable (or refundable). The export
  includes the dataset `tax_codes`.
- Not yet: VDS / TDS (withholding at source), supplementary duty chains and the official return
  form (Mushak 9.1); they come as their own rules and reports.

### Opening balances and year-end closing (ACC-4b)

- **Opening balances** (`acc_openings` + `acc_opening_lines`, one set per company; screen
  `/accounting/opening`, under Accounting settings). Account lines carry a debit or a credit;
  what customers still owe and what vendors are still owed is entered per old invoice or bill
  (reference, invoice date, due date: default the party's terms), never on the receivable or
  payable account, so aging, statements and receipts work from day one. Whatever does not
  balance goes to posting key `accounting.opening_balance` (template 3300), shown before posting.
  `GET/PUT/DELETE …/opening`, `POST …/opening/{submit|withdraw|approve|reject}`: writing needs
  `accounting.manage`, approving `accounting.approve`; above `accounting.journal_approval_above`
  a second person approves, never the writer. Posting makes one journal (source
  `accounting/opening`, dated the opening date; the backdating window does not apply, an open
  period does) and an opening invoice or bill (`is_opening`, numbers `OB-00001`…) per customer or
  vendor item. Posted openings never change; opening documents cannot be voided (credit notes
  correct them).
- **Closing a year** (`POST …/fiscal-years/{id}/close` with `base_version`, `accounting.close`):
  years close in order; every month must be closed first (rule
  `accounting.year_close_requires_all_periods`, default on; off = the open months are closed
  now, refusing while entries in them wait for approval). One entry dated the year's last day
  moves each income and expense balance (per branch or department) into posting key
  `accounting.retained_earnings` (template 3200). It sits in the year's **closing period**
  (`acc_periods.is_closing`, number 13, one day, always closed): profit and loss and the
  dashboard leave it out, the trial balance, balance sheet and ledgers include it. The next year
  is added when missing. Months of a closed year cannot be reopened.
- **Reopening a year**: `POST …/fiscal-years/{id}/reopen` with a reason makes a request
  (`acc_year_reopen_requests`); another person with `accounting.close` approves
  (`POST …/reopen-requests/{id}/approve`) or rejects it (`…/reject`, optional note; the person
  who asked may take it back this way). Rule `accounting.year_reopen_needs_second_person`
  (sensitive, default on; off for personal plans) decides. Approval reverses the closing entry in
  the same closing period; months stay closed until someone reopens the one they need. Only the
  latest closed year reopens. Requests ring in the bell for the other closers; pending openings
  for approvers.
- Audit: `accounting.opening_*`, `accounting.year_closed`, `…year_reopen_requested`,
  `…year_reopen_rejected`, `…year_reopened` (with the reason). The data export adds
  `openings`, `opening_lines` and `year_reopen_requests`.

### Bank matching and reconciliation (ACC-4c)

- **Statements** (`acc_bank_lines`, signed amounts: money in above zero): any usable asset account
  (cash, bank, bKash/Nagad wallet). Screen `/accounting/bank` (menu "Bank matching").
  `POST …/bank/accounts/{account}/import` takes a CSV file plus which columns hold the date,
  description, reference and either one signed amount or money in / money out, and the date
  format; the choice is remembered per account (`acc_bank_formats`). The file is read in memory
  and never stored (UTF-8, BOM, `,` `;` or tab, size and rows limited by rules
  `accounting.bank_import_max_kb` / `accounting.bank_import_max_rows`, 6 imports a minute per
  person). All or nothing: wrong rows are listed (up to 10). Rows where nothing moved (balance
  rows) and rows dated on or before the last finished reconciliation are skipped; a fingerprint
  per line keeps the same file from adding anything twice. Amounts are parsed with string
  arithmetic (`BankStatements::amount`: Bangla digits, thousands separators, `Tk`/`৳`,
  `(100.00)` or `-100` for money out).
- **Matching** (`acc_bank_matches`; posted journal lines never change): a statement line is
  matched to one or more posted lines of the same account whose debit minus credit adds up to it
  exactly; a journal line matches at most one statement line. The desk
  (`GET …/bank/accounts/{account}`) proposes a pair when exactly one book line has the same
  amount within `accounting.bank_match_days` (default 3) and fits no other line;
  `…/auto-match` takes them all. A line the books lack (charges, interest) becomes a journal
  entry from `POST …/bank-lines/{id}/entry` (the other account and words; dated the statement
  day, so the backdating window does not apply; approval rules do), matched at once when posted.
  `…/match`, `…/unmatch`, `DELETE …/bank-lines/{id}` (only unmatched, unreconciled lines).
- **Reconciliation** (`acc_reconciliations`): statement day and balance (the first time also the
  balance before its first line; later the previous statement balance). It finishes when every
  statement line up to that day is matched and opening + lines = statement balance; the books'
  balance and the entries the bank has not shown yet are shown alongside
  (`GET …/reconciliation?statement_date=&statement_balance_minor=` previews). Finishing locks
  those lines; only the latest reconciliation of an account reopens, with a reason.
- Permission `accounting.reconcile` (accountant template); writing an entry also needs
  `accounting.post`. Dashboard widget "Statement lines to match". Audit:
  `accounting.bank_imported`, `bank_matched`, `bank_unmatched`, `bank_line_deleted`,
  `reconciled`, `reconciliation_reopened` (reason). Export adds `bank_lines`, `bank_matches`,
  `reconciliations`.
- Not yet: direct bank feeds (APIs), several currencies (with 6-2), splitting one book line over
  several statement lines.

### Future expansion (Accounting)

- A new sector is a new chart file (or none: `general`) and a sector package rule; a new
  country is its fiscal year start and its tax profile file (`database/data/tax/*.php`) with its
  own codes and rates. No code change.
- A partner sets or locks approval amounts, date windows and numbering for all its clients
  through the rule engine.
- Next: ACC-2 screens and dashboard widgets, ACC-3 receivables and payables, ACC-4 tax, bank
  reconciliation and year-end closing (posting key `accounting.retained_earnings`),
  `multi_currency` with exchange rates.

## Attendance module (business module 3: ATT-1 backend)

Requires HRM. Employees are HRM's and are only read through HRM's public service
`Modules\Hrm\Directory\EmployeeDirectory` (`find`, `forUser`, `inUnits`, `many`, returning
`EmployeeRecord`); Attendance never touches `hrm_*` tables. HR links an employee to the login they
use with `PUT /api/organizations/{org}/hrm/employees/{id}/login` (`hrm.manage`; an active staff or
portal member of the company, one employee per login; audited `hrm.login_linked`).

- **Tables** (tenant, `organization_id` = the company, `unit_id` = the employee's branch or
  department): `att_shifts` (start/end in minutes after midnight; end ≤ start = night shift;
  unpaid break), `att_holidays` (company-wide or for a unit and below), `att_rosters` (shift from a
  day; a new roster closes the previous one), `att_punches` (UTC instants, append-only, voided with
  a reason, `op_id` unique), `att_days` (worked out), `att_corrections`.
- **Days** (`Services\Days` + pure `Services\DayCalculator`): a day belongs to the shift rostered
  for it; punches from `attendance.early_punch_minutes` (240) before the shift start until the same
  time the next day count, so a night shift is one day dated when it started. First punch = in,
  last = out. Worked = out − in, less the break. Late beyond `attendance.late_grace_minutes` (10);
  half day beyond `attendance.half_day_after_minutes` (240); over time beyond the shift's expected
  minutes once at least `attendance.overtime_min_minutes` (30). No punch: `absent` once the shift
  is over, `pending` before; in without out after the shift: `incomplete`. Weekends
  (`attendance.weekend_days` at the unit) and holidays are days off (time worked there is over
  time). No days before joining or after leaving. Days are recalculated whenever a punch,
  correction or read touches them, and `attendance:close-days` (daily 01:40 UTC) works out every
  rostered employee's yesterday (company timezone) so absences appear.
- **API** (`/api/organizations/{org}/attendance`, `module:attendance`, permission checked at the
  employee's unit): `shifts` (GET; POST/PATCH `attendance.manage` at the company), `holidays`
  (`?year`; POST/DELETE, a unit's own at that unit), `rosters` (`?employee_id`; POST
  `{employee_ids, shift_id|null, from}`), `days?from&to[&employee_id]` (≤ 62 days, 50 employees a
  page), `punches` (GET `?employee_id&from&to`; POST by HR with a note, local time, audited;
  `punches/{id}/void` with a reason), `corrections` (GET `?status`; POST for someone,
  `attendance.manage`; `corrections/{id}/{approve|reject}` with `attendance.correct`, never the
  person who asked or whose day it is). Self-service through the linked login: `me`, `me/days?month`,
  `me/corrections`, `me/punch` (`attendance.punch`, rule `attendance.self_punch`, 6 a minute, a
  second tap within a minute or a repeated `op_id` is the same punch; `attendance.geo_fence_required`
  refuses until location checks arrive in ATT-3). Corrections go back at most
  `attendance.correction_max_days` (31, sensitive). Portal: `GET /api/portal/attendance/days?month`
  for portal members linked to `hrm.employee` records.
- **For Payroll**: `Modules\Attendance\Services\AttendanceSummary::forPeriod($company, $employeeIds,
  $from, $to)` — per employee, days by status, attended days, worked, late and over-time minutes.
- Dashboard: "Checked in today", "Corrections to decide" (also in the bell for deciders). Audit:
  `attendance.shift_*`, `holiday_*`, `roster_assigned`, `punch_written`, `punch_voided`,
  `correction_*`. Data export: `shifts`, `holidays`, `rosters`, `punches`, `days`, `corrections`.
- Leave is a later module.

### Workplaces, attendance machines, offline (ATT-3)

- **Location check** (rule `attendance.geo_fence_required` at the unit): workplaces
  (`att_locations`: a unit's point in millionths of a degree, integers, and a radius; a unit's
  workplaces count for the units below it; screen `/attendance/locations`, typed or "use where this
  device is now"; `GET/POST/PATCH …/attendance/locations`, `attendance.manage` at the workplace's
  unit). A self check-in then sends `latitude_micro`, `longitude_micro`, `accuracy_m`; it is refused
  when missing (`location_needed`), vaguer than `attendance.geo_max_accuracy_m` (100), when the unit
  has no active workplace (`no_workplaces`), or outside every radius (`outside_workplace`, telling
  how far). The location is asked for and kept only then (`att_punches.latitude_micro`,
  `longitude_micro`, `accuracy_m`, `distance_m`, `location_id`); the API never returns the point,
  only the distance and accuracy. `attendance:forget-locations` (daily 02:10 UTC) forgets points
  older than `attendance.location_retention_days` (90, sensitive); distances stay.
  `Support\GeoDistance` measures great-circle metres from integer points (no float columns).
  New workplaces default to `attendance.geo_radius_m` (200).
- **Attendance machine files** (`POST …/attendance/device-import`, `attendance.manage` at the unit,
  6 a minute): a CSV with the employee code and the time (one column, or a date and a time column),
  local time in one of the offered formats; the choice is remembered (`att_device_formats`,
  `GET …/device-format`). Punches get source `device` and an `op_id` from employee and time, so a
  file brought in twice adds nothing. Codes not of the unit (and below) and days the person was not
  employed are skipped and reported; a time that cannot be read stops the file. Each touched day is
  worked out once afterwards. Limits: `attendance.device_import_max_rows` / `…_max_kb`. HRM's
  directory gained `byCodes()`.
- **Offline** (with Offline mode on): manifest `sync_records` → `Offline\PunchSync` (kind
  `attendance.punch`, create only, `attendance.punch` permission): the phone keeps the tap with the
  time it happened (and the location when required) and sends it through `/api/sync`; it counts if
  no older than `attendance.offline_max_age_hours` (72) and not in the future; source `offline`;
  the same op_id is the same punch. "My attendance" keeps a tap offline when there is no network and
  shows how many wait to be sent.
- Audit: `attendance.location_added`, `location_updated`, `device_imported`. Export adds
  `locations` and the punch location fields.

### Screens (ATT-2)

- `/attendance/me` (menu "My attendance", `attendance.punch`; quick action "Check in"): one large
  check in / check out button (an `op_id` per tap), today, recent punches, the days of a month and
  "Fix a day" (a correction with in/out times; an out time before the in time is the next
  morning). Without a linked login it says to ask HR. Checking in needs `attendance.punch` where
  the person signed in (their HRM unit may sit below that membership).
- `/attendance` (Today): the day at the current unit and below — counts per status to filter by,
  each person's status, in, out and time worked; a row opens a side panel with the punches, where
  people with `attendance.manage` write a punch from a register (note required), void one with a
  reason, or ask a correction on the person's behalf.
- `/attendance/month`: employees × days with a short mark and colour per status (legend spells
  each out), days at work per person; a cell opens the same panel.
- `/attendance/corrections`: waiting / approved / rejected; approve or reject (optional note) only
  where the server says the reader may decide.
- Settings pages: `/attendance/shifts` (times as HH:MM, night shifts marked), `/attendance/holidays`
  (by year; a branch or department may add its own), `/attendance/rosters` (choose people, a shift
  or "no fixed hours", and the day it starts).
- Portal: `/portal/attendance` — a portal member's own days by month.
- HRM employee page, Overview: "Login" with link / change / unlink (`hrm.manage`); the list comes
  from `GET …/hrm/employees/{id}/logins?search=` (active staff and portal members of the company,
  not linked to another employee; name and email only). The employee detail includes `login`.

### Future expansion (Attendance)

- A new sector or country changes rules only (weekend days, grace, over-time minimum) and its
  holiday list; shifts and rosters are each company's own data. No code change.

## Payroll module (business module 4: PAY-1 backend, PAY-2 screens)

Requires HRM and Attendance; posts to Accounting where the company keeps books (optional).
Employees come only through `Modules\Hrm\Directory\EmployeeDirectory`, days and minutes only
through `Modules\Attendance\Services\AttendanceSummary`, the books only through
`Modules\Accounting\Services\Ledger`.

- **Tables** (tenant, `organization_id` = the company): `pay_components` (earning or deduction,
  taxable, prorated), `pay_structures` + `pay_structure_items` (fixed amount, or basis points of
  the basic), `pay_salaries` (basic and structure from a day; a new one closes the previous; history
  kept), `pay_payment_details` (bank, mobile or cash; account number encrypted, always shown masked,
  never in the audit log), `pay_runs` (one per company and month), `pay_run_approvals` (level, a
  different person each), `pay_slips` + `pay_slip_lines` (frozen at approval; names kept as they
  were), `pay_adjustments` (one-offs in a draft run).
- **Calculating** (`Services\PayCalculator`, pure, integer minor units, half up): every employee
  employed in the month gets a slip from the salary in force at the end of their time in it. Prorated
  items are paid for employed days less absences and half of half days (rule
  `payroll.deduct_absence`): amount × payable half-days ÷ (2 × days in the month). Over time =
  minutes × base (`payroll.overtime_base`: basic or gross) ÷ (`payroll.monthly_hours` × 60) ×
  `payroll.overtime_multiplier`. Late minutes are deducted at the basic's minute rate when
  `payroll.late_deduction` is on. Tax at source: twelve times the month's taxable earnings through
  `payroll.tax_slabs` (cumulative "up to" bands; **placeholders for a tax adviser**), a twelfth a
  month. A slip without a salary or with net pay below zero has a `problem` and blocks sending.
- **Run steps** (`/api/organizations/{org}/payroll/runs…`, at the company): `POST runs {period}`,
  `…/calculate`, `…/adjustments` (adding or removing one clears the calculation), `…/submit`
  (`payroll.run`), `…/approve` and `…/reject` with a reason (`payroll.approve`; never the person who
  opened or sent it; `payroll.salary_approval_levels` different people), `…/pay {paid_on}`,
  `DELETE runs/{id}` (drafts). At the last approval the run is posted (op `payroll-run-{id}`, dated
  the month's last day): each branch's or department's earnings to `payroll.salary_expense`
  against `payroll.salaries_payable`, `payroll.tax_payable` and `payroll.deductions_payable`;
  paying posts salaries payable against `payroll.payment_account` (general chart: 5200, 2120, 2130,
  2140, 1120). Event `payroll.run.approved` (ids only). An approved run never changes; a mistake is
  put right next month.
- **Setup and salaries**: `components`, `structures` (GET with `payroll.view`; POST/PATCH with
  `payroll.run`), `employees/{id}` (salary history and masked payment details),
  `POST employees/{id}/salary`, `PUT employees/{id}/payment` (at the employee's unit).
- **Own payslips**: `GET …/payroll/me/slips[/{id}]` through the login HR linked — approved and paid
  runs only.
- Audit: `payroll.component_*`, `structure_*`, `salary_set`, `payment_details_set`, `run_*`,
  `adjustment_*`. Data export: components, structures, items, salaries, payment details (in full,
  the client's own data), runs, approvals, slips, lines, adjustments.
- Payroll complete through PAY-3b; later: leave encashment from a Leave module, yearly PF interest, gratuity accrual.

### Screens, payslips and bank file (PAY-2)

- `/payroll` (menu "Payroll runs"): months newest first with status and net pay; "Start a month"
  suggests the month after the last one.
- `/payroll/runs/{id}`: totals, each person's slip (problems first, with what to do; phones get a
  list, wider screens a table), one-off additions and deductions (draft only), and only the steps
  the server says this reader may take: calculate, send for approval, approve / send back with a
  reason (approval levels shown as "1 of 2"), mark paid on a day, delete a draft. A version
  conflict reloads the run.
- **Bank file** (approved or paid runs): `GET …/runs/{id}/bank-file` with `payroll.run` at the
  company, a recent second step (`two_factor.recent`) and 5 a minute per person; full account
  numbers, `Cache-Control: no-store`; audited as `payroll.bank_file_taken` (period, lines, how many
  without an account — never a number). The browser writes the CSV itself (UTF-8 BOM for Excel,
  headings in the reader's language, a cell starting with `= + - @` gets a leading `'`) and never
  keeps it.
- `/payroll/runs/{run}/slips/{slip}`, `/payroll/me/slips/{id}`, `/portal/payslips/{id}`: one
  printable payslip on the partner's branding and the company's name (`company` in the slip),
  earnings, deductions and tax line by line, days employed / absent, over time, net pay.
- `/payroll/employees` (`GET …/payroll/employees?search=`, `payroll.view` at the unit and below):
  basic salary today, structure and payment method — never an account number; people without a
  salary are counted at the top. `/payroll/employees/{id}`: current salary, history, "New salary"
  (from a day; the earlier one ends the day before) and where pay goes. Leaving the account number
  empty keeps the one on file (the screen only ever has it masked).
- Settings pages: `/payroll/components`, `/payroll/structures` (each item a fixed amount or a
  percentage of the basic; percentages become basis points, amounts minor units, Bangla digits
  accepted). `meta.currency` comes with the employee and structure reads.
- `/payroll/me` (menu "My payslips") and, in the client's portal, `/portal/payslips`
  (`GET /api/portal/payroll/slips[/{id}]`, Client portal and Payroll on): the member's own
  employees' approved slips only.
- **Portal pages** (platform): a module may declare `portal_pages` in its manifest
  (`[{subject: 'hrm.employee', label, route: '/portal/…'}]`); a portal record then lists those
  screens while the module is on (`pages` in `GET /api/portal/records/{id}`). Payroll offers
  "My payslips" and Attendance "My attendance" for an employee record.
- Dashboard: "Last payroll" (net pay of the latest approved month) and "Waiting for approval";
  the bell tells approvers about runs they did not open, send or already approve.
- A company whose books were set up before Payroll was on must choose the five payroll posting
  accounts (Accounting > Posting accounts) before the first approval; the approval says so.

### Loans, advances and festival bonuses (PAY-3a)

- **Loans and advances** (`pay_loans`, `pay_loan_installments`): asked for by payroll staff
  (`POST …/payroll/loans`: employee, kind loan/advance, principal, instalments, first month,
  paid-out day), limited by rules `payroll.loan_max_installments` and
  `payroll.loan_max_basic_multiple` (months of basic; 0 = no limit). Approved or rejected (reason)
  by someone else with `payroll.approve`, or withdrawn while waiting (`…/loans/{id}/{approve|reject|cancel}`).
  Approval pays it out: `payroll.employee_loans` against `payroll.payment_account` (general chart
  1160 / 1120). Each instalment is the principal over the months rounded up; the last one takes
  what is left.
- **Recovery**: calculating a draft month plans each running loan's instalment (slip line `LOAN`,
  status `planned`, never more than what other drafts left); approving the month recovers it and
  credits `payroll.employee_loans` instead of other deductions payable; a loan paid back closes.
  Calculating again or deleting the draft drops its planned rows. A month can be held back with a
  reason (`POST …/loans/{id}/skips`, `DELETE …/skips/{period}`) only while that month's payroll
  is a draft; the draft then needs calculating again. `GET …/loans/{id}` gives the schedule
  (recovered, planned, held back, due).
- **Festival bonuses** (`pay_bonus_runs`, `pay_bonus_lines`): `POST …/payroll/bonuses` (title per
  language, the day it is for, a share of the basic or rule `payroll.bonus_percent_of_basic`).
  Calculating gives everyone employed that day a line: nothing for service under
  `payroll.bonus_min_service_months` (BD 12, placeholder), no salary, or left out by hand
  (`PATCH …/bonuses/{id}/lines/{line}`); an amount set by hand counts even with short service and
  stays when calculated again. Tax at source (rule `payroll.bonus_taxable`) is the extra yearly
  tax the bonus brings on top of twelve months of regular taxable pay. Sent, approved by someone
  who neither opened nor sent it (one level), paid; posted as `payroll.bonus_expense` per unit
  against salaries and tax payable. Bank file `GET …/bonuses/{id}/bank-file` (same protection as
  a month's).
- **Own view**: `GET …/payroll/me/{loans|bonuses[/{line}]}` and
  `/api/portal/payroll/{loans|bonuses[/{line}]}`; "My payslips" lists bonuses and loans with what
  is left; a bonus slip prints on the company's branding.
- Screens: `/payroll/loans`, `/payroll/loans/{id}`, `/payroll/bonuses`, `/payroll/bonuses/{id}`,
  `/payroll/me/bonuses/{line}`, `/portal/bonuses/{line}`. The bell tells approvers about loans and
  bonuses waiting. Audit: `payroll.loan_*`, `payroll.bonus_*`. Data export adds loans, loan
  instalments, bonus runs and lines.
- After deploy: migrate, `rules:sync`, `accounting:map-postings` (maps the two new posting keys in
  books set up earlier), `db:seed --class=RulesSeeder` for the BD bonus values.

### Provident fund and final settlements (PAY-3b)

- **Provident fund** (rules `payroll.pf_enabled`, `pf_employee_percent`, `pf_employer_percent`, at
  the employee's unit): each slip takes the employee's share of the basic paid (`PF_EMPLOYEE`,
  a deduction) and shows the company's (`PF_EMPLOYER`, kind `employer`: not paid out, not in
  net). Approving the month writes both into `pay_pf_entries` (append-only; contributions
  positive, withdrawals and forfeits negative) and posts the company share as
  `payroll.pf_employer_expense` and both shares as `payroll.pf_payable` (general chart 2160 /
  5200). `GET …/payroll/fund` lists balances; `GET …/me/fund` and `/api/portal/payroll/fund` the
  person's own with movements. A "PF" pay component from before keeps working; do not use both.
- **Final settlements** (`pay_settlements`, `pay_settlement_lines`, one per employee): for someone
  whose HRM exit day is set. `GET …/payroll/settlements` gives `meta.due` (left within a year,
  no settlement; the bell tells payroll staff). Opening works out, each with its `basis`:
  gratuity (`payroll.gratuity_min_years`, `payroll.gratuity_days_per_year`; days of the basic at
  the last day per whole year, a month = 30 days), the fund (own share in full, the company's as
  far as `payroll.pf_vesting` allows after the years served, the rest shown as kept back), and
  each loan still owed. Lines by hand (`POST …/settlements/{id}/lines`: notice pay, leave
  encashment, something to recover) stay when calculated again; taxable ones carry tax as the
  extra yearly tax on top of regular pay. A settlement that leaves the person owing cannot be
  sent. Approval (someone who neither opened nor sent it) checks the fund and loans have not
  changed, posts it (gratuity and lines by hand as expenses, the fund paid out, the kept-back
  share back off the company's expense, loans and deductions, tax, net payable), closes the
  loans (drafts that planned them need calculating again) and writes the fund's withdrawal and
  forfeit. Paid like a salary; bank file with the same protection.
- Someone who left sees their settlement and fund in the client's portal (their staff login no
  longer maps to them once HR records the exit).
- Screens: `/payroll/settlements`, `/payroll/settlements/{id}` (+ `/print`), `/payroll/fund`,
  `/payroll/me/settlements/{id}`, `/portal/settlements/{id}`; "My payslips" shows the fund and
  settlements. BD values (placeholders for an adviser): gratuity from 5 years, 30 days a year;
  company share half after 3 years, all after 5.
- After deploy: migrate, `rules:sync`, `accounting:map-postings`, `db:seed --class=RulesSeeder`.
  Books set up earlier have no 2160: add "Provident fund payable" and choose it under Accounting >
  Posting accounts before the first month with the fund is approved.

### Future expansion (Payroll)

- A new country sets its rules (over-time base and multiplier, monthly hours, tax slabs); a new
  sector or company builds its own components and structures. A new bank format is a different
  column order of the same data (a later per-company template). A partner's payslip carries its
  own branding. No code change.

## Inventory module (business module 5: INV-1 backend, INV-2 screens)

No other module needed; posts to Accounting where the company keeps books (optional). Other modules
(POS, later Factory) use only `Modules\Inventory\Services\Stock` (items to sell, on hand, `take()`
stock out for a record or back with negative quantities — once per record, cost returned, nothing
posted) and the events `inventory.stock.low` / `inventory.moved` (ids only).

- **Tables** (tenant, `organization_id` = the company): `inv_units` (decimals 0–3),
  `inv_categories`, `inv_items` (SKU and barcode unique per company; stock or non-stock; batches;
  sale price; reorder level), `inv_warehouses` (each belongs to a branch or department),
  `inv_batches` + `inv_batch_stock`, `inv_moves` (append-only, signed quantity in thousandths and
  value in minor units, source module/type/id), `inv_balances` (per item and warehouse; method fixed
  when it starts), `inv_layers` (receipts left, oldest first), `inv_documents` + lines,
  `inv_counts` + lines, `inv_sequences`.
- **Ledger** (`Services\StockLedger`, the only way stock changes): in at the given or current cost;
  out at weighted average or FIFO (rule `inventory.valuation_method`, per balance); taking all that
  is left takes all the value left; below zero only where `inventory.allow_negative_stock`, at the
  last known cost. Batch items need a batch coming in; going out takes the batch expiring first
  (FEFO) unless one is named.
- **Documents** (`/api/organizations/{org}/inventory/documents`, `inventory.manage` at the
  warehouse's unit): receipt (cost per unit; posted stock against `inventory.grni`, goods received
  not billed), issue (to `inventory.cogs`), adjustment (a reason; above
  `inventory.adjustment_approval_above`, a money rule, empty = never, it waits for someone else with
  `inventory.approve`; against `inventory.adjustment`), transfer (dispatch → in transit → receive at
  the other warehouse with what arrived; the rest is a loss against `inventory.adjustment`).
  Numbers per kind and year from rule `inventory.number_prefixes` (GRN-2026-00001). `op_id` makes
  creating safe to retry. General chart: 1150 / 2115 (new) / 5100 / 5900; factory 1151 / 5110;
  retail adjustments 5150.
- **Counts** (`…/inventory/counts`): one open per warehouse; expected quantities taken at the start;
  counted entered (items not expected can be added); someone else approves and each difference is
  posted as a `count` move at today's cost.
- **Reads**: `units|categories|warehouses|items` (+ POST/PATCH with `inventory.manage` at the
  company), `items/{id}` (stock per warehouse, batches, last moves), `lookup?code=` (barcode or
  SKU), `stock?warehouse_id&low=1`, `moves`, `expiring` (rule `inventory.expiry_alert_days`).
  A unit sees its warehouses and those below.
- Dashboard: stock value, items to reorder; bell: low stock, expiring batches, adjustments and counts
  to approve. Permissions `inventory.view|manage|approve` (manage and approve kept apart); new role
  template "Store keeper"; finance approver gets `inventory.approve`. Audit `inventory.*`; data
  export adds the module's tables.
- **Screens** (`Modules/Inventory/resources/js`): Stock (value, filter, low only), Items + item
  dialog, Item (per warehouse, batches, moves), Receipts and issues list, one document (form with a
  barcode scan box: scanning again adds one; steps post / dispatch / receive with what arrived /
  approve / send back / cancel), Counts + one count (scan to find, counted vs expected), Expiring,
  and settings pages for warehouses, units and categories.
- **Accounting**: `accounting:map-postings` now also adds a template account a newer release added
  (e.g. 2115, 2160) to books set up before it, under its group when the code is free.
- After deploy: migrate, `rules:sync`, `access:sync`, `accounting:map-postings`.

### Supplier bills, reports and labels (INV-3)

- **Bill from a receipt** (`POST …/inventory/documents/{id}/bill {base_version, party_id, issue_date?}`,
  `inventory.manage` at the warehouse's unit and Accounting's `accounting.buy` at the company): a
  posted goods receipt becomes the supplier's **draft** bill through Accounting's public
  `Modules\Accounting\Services\Bills` (lines at the receipt's costs on `inventory.grni`, cost centre
  the warehouse's unit), once per receipt (`inv_documents.bill_id`, audit `inventory.document_billed`).
  A module marks such a posting key `cleared_by: bills` in its manifest; only those liabilities may
  sit on bill lines, every other liability is still refused. Accounting off or not set up: no
  button, 403.
- **Reports** (`GET …/inventory/reports/{valuation|reorder|slow|ledger}`, `inventory.view`, the unit's
  warehouses or one, rate limited `inventory-reports` 30/min): value on a day (today's balances less
  the later moves), what to reorder (the item's reorder quantity, else up to twice its level), slow
  movers (`days`, nothing sold, issued or sent on), one item's ledger (`item_id`, `from`, `to`; opening,
  running balance, closing; at most 3,000 moves). Totals are added up in PHP, not SQL. Screens save
  each as CSV (`resources/js/lib/csv.js`: byte order mark, formula-like cells kept as text).
- **Labels** (`/inventory/labels`, also from an item): shop name, item, price and barcode drawn as SVG
  (EAN-13 when the barcode is a valid one, else Code 128 of the barcode or SKU), on an A4 sheet (3 × 8)
  or a 50 × 30 mm label printer.
### Future expansion (Inventory)

- A new sector or country needs no code: units, categories and prices are each company's data;
  valuation, negative stock, approval limits, expiry warnings and number prefixes are rules. Serial
  numbers, landed costs and supplier bills matched to receipts are later steps.

## Point of sale module (business module 6: POS-1 backend, POS-2 screens)

Needs Inventory (`requires: inventory`); posts to Accounting where the company keeps books. Uses
only `Inventory\Services\Stock` (items, units, warehouse, `take()`) and
`Accounting\Services\TaxCodes::salesRates` + `Ledger::post`. Customers are walk-in (a name on the
receipt); CRM customers come later.

- **Tables** (tenant, `organization_id` = the company): `pos_registers` (counter: unit, the
  warehouse it sells from, payment methods cash/card/mobile), `pos_sessions` (shift: float,
  expected / counted / variance, totals; one open per register), `pos_sales` (sale or return,
  `op_id` unique, offline flag, review reason) + `pos_sale_lines` + `pos_payments`,
  `pos_sequences`. Money in minor units, quantities in thousandths.
- **Selling** (`POST /api/organizations/{org}/pos/sales`, `pos.sell`, throttled 60/min): prices
  from the item, VAT from the item's tax code (rule `pos.prices_include_tax`), discounts above
  `pos.max_discount_percent` need `pos.supervise`; change only from cash. Stock leaves the
  register's warehouse; posting Dr pos.cash|card|mobile / Cr pos.sales, pos.tax_output; Dr
  inventory.cogs / Cr inventory.stock. Numbers `R-{CODE}-2026-000001` (rule `pos.number_prefixes`).
- **Offline**: sync kind `pos.sale` (money, idempotent by `op_id`, rule `pos.offline_sales`).
  An offline sale is never lost: short stock, a closed shift or a discount over the limit are
  accepted and marked for review instead of rejected.
- **Returns** (`…/sales/{id}/return`, `pos.supervise`, within `pos.return_days`): chosen lines and
  quantities, proportional amounts, stock back at the original cost, posting reversed, cash out.
- **Shifts**: open with a float, close with the counted cash; a difference above
  `pos.cash_variance_allowed` (sensitive money rule) waits for a supervisor who did not open or
  close it, then posts to `pos.cash_variance`. Z report per shift.
- Charts: general 1110 / 1120 / 1130 / 4100 / 2130 / 5900; retail 1115 / 1135 / 5150.
  Permissions `pos.view|sell|supervise|manage`; role templates "Cashier" and "Shop supervisor";
  retail package and the starter plan include POS. Audit `pos.*`; data export adds the tables.
  Dashboard: today's takings; bell: shifts and sales to review.
- **Screens** (`Modules/Pos/resources/js`): Till (counter remembered, scan or tap, cart, discount,
  payment with quick cash and change, works offline), Receipt (80 mm print, returns), Sales and
  returns, Shifts + one shift (Z report, close, review), Counters (settings).
- After deploy: migrate, `rules:sync`, `access:sync`, `accounting:map-postings`.

### Takings, held carts and reprints (POS-3)

- **Takings** (`GET …/pos/reports?from&to&register_id`, `pos.supervise` or `pos.manage`, at most 366
  days, rate limited `pos-reports` 30/min): totals (net, average receipt, returns, VAT, discounts,
  margin before VAT), and by day and hour (the company's timezone), cashier, payment method (cash net
  of change) and item; returns count against them. Screen `/pos/reports` with periods and CSV.
- **Hold a cart** at the till and take it up later: kept on the device per counter (at most 5), items
  and quantities only (no customer name), prices refreshed from the catalogue on resume.
- **Reprint** any receipt from the sales list (opens it with `?print=1`).

### Demo shop (local only)

`php artisan db:seed --class=DemoStockSeeder` (also part of `migrate --seed` in `APP_ENV=local`; only
adds, stops when the shop exists) builds **Demo Super Shop** in the demo group: retail books, 30
items with Bangla and English names, barcodes, VAT and batches, three suppliers (two receipts billed,
two to bill), transfers to the Dhanmondi and Uttara floors (one on the way), an adjustment waiting for
approval, an open count, three counters with two weeks of shifts, sales and returns (one cash
difference to review) and a shift open today. Logins `shop.owner@`, `store@`, `cashier@`,
`supervisor@demo.test`, password `One@2002`.
### Future expansion (POS)

- A new sector, country or partner needs no code: methods, prices, VAT, limits, return days and
  number prefixes are data and rules. CRM customers and loyalty, gift cards, card terminals and
  bKash APIs are later steps behind the same payment methods.

## CRM module (business module 7: CRM-1 backend, CRM-2 screens, CRM-3 POS and demo)

Works alone (`requires: []`); uses Inventory items, Accounting VAT codes and invoices, and POS sales
when they are on, only through their public services (`Inventory\Services\Stock`,
`Accounting\Services\TaxCodes::salesRates|salesCodes`, the new `Accounting\Services\Customers`) and
the event `Modules\Pos\Events\SaleMade` (`pos.sale_made`: ids and amounts, no personal data).

- **Tables** (tenant, `organization_id` = the company, `unit_id` = the branch a record belongs to):
  `crm_contacts` (person or organization, phone in E.164 unique per company, email, tags, source,
  owner, SMS and email consent with its time, spent, purchases, points, `extra`), `crm_pipelines` +
  `crm_stages` (per company; the sector's pipeline from `database/data/pipelines.php` made on first
  use), `crm_deals`, `crm_activities` (calls, visits, notes; a task has a time and a person),
  `crm_quotes` + `crm_quote_lines` (estimates and quotations), `crm_fields` (the company's own fields),
  `crm_points` (append-only, once per source), `crm_sequences`.
- **Extra fields** (`/crm/fields`, `crm.manage`): text, long text, number (decimal string), money
  (minor units), date, choice (labels per language), yes/no; on contacts, deals, quotes and quote
  lines; needed or not, printed on quotes or not. Key and type fixed once made; switched off, never
  removed (old records keep values). Checked the same way in forms and imports.
- **Contacts** (`…/crm/contacts`): `crm.view` reads the unit's and below, `crm.edit` writes (an
  `op_id` makes creating safe to retry), duplicates by rule `crm.duplicate_match` (phone, or phone and
  email) refused naming the match. Removed on request (`crm.manage`): details cleared for good,
  amounts kept, the audit records why but never the details. CSV import (checked, then made;
  duplicates skipped; rate limited) and export (`crm.export`, rate limited, audited with the count).
  "Make a customer" creates the Accounting party once (`acc_parties.crm_contact_id`).
- **Deals** board per pipeline; moving to the lost stage needs a reason, to the won stage closes it,
  back to an open stage reopens it. **Follow-ups**: mine late / today / coming / done; `crm:remind`
  (every 5 minutes) mails each once rule `crm.follow_up_reminder_minutes` before (`crm.follow_up_due`).
- **Estimates and quotations**: lines from Inventory items or free text, discount, VAT inside or on
  top (rule `crm.quote_prices_include_tax`), valid `crm.quote_valid_days`, numbers by
  `crm.number_prefixes` (EST-/QT-2026-00001). Draft → sent (changing makes it a draft again) →
  accepted (its deal won; with `accounting.sell` and books kept, the customer and a **draft invoice**
  on posting key `crm.sales`: general chart 4100, school 4120) or declined (a reason); an estimate is
  turned into a quotation. Printed on A4 with the brand and the printed extra fields.
- **POS** (no CRM needed): a sale keeps `customer_phone` (E.164; Bangla digits read); `GET
  …/pos/customers?phone=` finds the customer (CRM contact with points when CRM is on, else the earlier
  sales here); the sales list searches by mobile number. With CRM on the sale's contact is found or
  added at the counter and earns points (`crm.loyalty_points_per_100`, 0 = off); a return takes its
  share back. Offline sales keep no customer details on the device (personal data).
- Permissions `crm.view|edit|manage|export`; role templates "Sales person" and "Sales manager";
  general and retail packages include CRM. Dashboard: open deals; bell: follow-ups due. Data export
  adds the tables.
- Demo (local only): `php artisan db:seed --class=DemoCrmSeeder` after `DemoStockSeeder`; login
  `sales@demo.test`, password `One@2002`.
- After deploy: migrate, `rules:sync`, `access:sync`, `accounting:map-postings`; schedule runs
  `crm:remind`.

### Future expansion (CRM)

- A new sector adds its pipeline to `database/data/pipelines.php`; a country its phone format in the
  country data; a company its own fields and stages on screen. No code change for any of them.
  Campaigns (bulk SMS or email to those who consented), points spent at the counter, web lead forms
  and quote e-mail with PDF are later steps.

## Education module (business module 8: EDU-1 backend, EDU-2a screens)

Code: `Modules/Education` (module `education`, sectors school, college, university, madrasa,
coaching). One module for every kind of institution: nothing about a kind of school or a country
is in the code. Records live in the client's database (tenant migration); `organization_id` is the
institution (company), `unit_id` the campus (branch).

### Structure (data, `/education/structure/{kind}`)

| kind | what |
|---|---|
| `units` | faculty / department / institute, any depth |
| `programs` | what students follow; `progression` year, semester or term; periods a year; total credits; what a level and a section are called there ("শ্রেণি"/"Semester", "শাখা"/"Batch") |
| `levels` | steps of a program; `next_level_id` is where promotion goes (none on the last) |
| `lists` | own lists: gender, relation, shift, medium, stream, category |
| `years`, `sessions` | academic years and the periods taught in them (a whole year, semesters or terms) |
| `sections` | a session's group of one level at a campus, capacity (rule default), class teacher (HRM employee) |
| `batches`, `subjects`, `curricula`, `curriculum_items`, `prerequisites` | intakes, subjects with credits (hundredths), each level's subjects per stream |

Everything is switched off rather than removed; references must be the institution's own and fit
(a section's session must be of its program's kind, a next level of the same program, sessions
inside their year, no prerequisite circles). Presets (`database/data/presets/*.php`:
`bd_school`, `bd_college`, `university`, `madrasa`, `coaching`) make what is missing and can be
applied again; a new country or kind of institution is a new data file.

### Students, guardians, admissions, enrollments

- A student gets a code by `education.student_code_format` (`{YYYY}{YY}{PROGRAM}{SEQ:n}`), is
  at a campus, in a program (and batch). Birth registration number encrypted, found by a keyed hash
  (the same number twice is refused). Date of birth, ids and sensitive own fields only with
  `education.view_sensitive` (read and write; opening them is audited; the audit never holds them).
- One phone, one guardian: siblings share their parents; one primary guardian per student.
- Applications: applied → test → offered → admitted / rejected / withdrawn; admitting makes the
  student, guardians and first enrollment. Numbers by `education.number_prefixes`.
- Enrollments: one per student and session, never rewritten (history stays); sections keep to
  their capacity; rolls by `education.roll_number_mode` (manual, name, admission order).
- A student leaves or graduates; nothing is deleted. Photos private, through 5-minute signed links.
- Own fields (`edu_fields`) on students, guardians and applications: required, shown in the
  portal, printed on documents, or sensitive.

### Who sees what

`education.view`, `.manage` (structure, fields, presets), `.admit`, `.edit_students`,
`.view_sensitive`, `.promote`, `.approve_promotion` (EDU-1b). A teacher (view only) sees the
students of the sections they are class teacher of (`education.teacher_scope` = own_sections; their
login linked to an HRM employee), or every student (`all`). Guardians and students in the portal:
subject `education.student` (relations guardian, self), only portal fields, never private details.

### Rules

`education.student_code_format`, `number_prefixes`, `section_capacity_default`, `roll_number_mode`,
`promotion_approval` (sensitive), `promotion_undo_days`, `max_repeats`, `teacher_scope`.

### Events (ids only)

`StudentAdmitted`, `StudentLeft`, `EnrollmentChanged`, for fees, attendance and exams.

### Promotion (EDU-1b, `/education/promotions`)

1. A list for a session's level (or one section) at a campus: every studying student, to be
   promoted to the next level (graduate on the last). One open list per student.
2. Decisions per student: promote, repeat (more often than `education.max_repeats` needs a
   reason), leave (reason) or graduate (last level only), and the section next session
   (`section_id` on the lines call places everyone at once).
3. Submitted: applied now, or with `education.promotion_approval` after another person
   approves (`education.approve_promotion`, never the maker), or sent back with a note.
4. Applying is all or nothing: this session's enrollments end (promoted, repeated, left,
   graduated), the next session's are made inside each section's capacity.
5. Undo within `education.promotion_undo_days`, while nothing moved on since.

### Students from a spreadsheet and from CRM

- `POST students/import` with the rows read on the screen: `commit: false` checks every row
  (errors per column, duplicates by birth registration number or name and guardian phone, section
  room), `commit: true` makes the good ones (a row is never made twice). Private columns need
  `education.view_sensitive`.
- A won deal of a pipeline listed in `education.crm_admission_pipelines` (default
  `["admissions"]`) becomes an application, once per deal (CRM event `DealWon`, contact read
  through `Customers::contact()`), with the contact as guardian and no place yet: the office
  places it before admitting.

### Screens (EDU-2a)

Menu "Education" (`education.view`): Overview, Students, Sections; under Settings: Programs,
classes and sessions, Own fields (`education.manage`). Header "New" menu: New student
(`education.admit`). Code: `Modules/Education/resources/js` (pages, dialogs, `lib.js` tested in
`tests/js/education.test.js`, texts in `locales/{en,bn}/education.json`).

- **Overview** (`GET education/overview?session_id=`): the open session by default, its sections
  with seats taken, students, students in no section, nearly full sections (90 %+), and, only for
  people who can act on them, applications and promotions waiting. Until the institution is set
  up, managers get two steps (pick a preset, make the year with its sessions); others are told
  who has to do it.
- **Students**: search by name, code or phone (Bangla digits too), filters status, program, class
  and "not in a section" (`students?unplaced=1`). New student in three steps (student, guardians,
  class and section; sent with an `op_id`); a server error opens the step it belongs to.
- **Student**: details (private ones only with `education.view_sensitive`), own fields, photo
  (checked for size here, kind and size on the server), where they study now, move to another
  section, mark as left or graduated (asked twice), guardians (add, change, remove; relation and
  switches are per student), classes over time.
- **Sections / Section**: sections of a session class by class, how full (bar, numbers and a word,
  never colour alone), class teacher; roster in roll order, number rolls by the rule, move one
  student.
- **Structure** and **Own fields**: every kind made and switched on/off in one form; presets can
  be applied again; field key and kind fixed once made; private fields never in the portal.
- `GET education/setup` also gives batches and the own fields forms ask for (private ones only
  for people allowed to see them).

### Screens (EDU-2b): admissions, promotion, import

Menu "Admissions" (`education.admit`) and "Promotion" (`education.promote`; approvers open
waiting lists from the overview tile). Header "New" menu: New application.

- **Admissions**: status tabs with counts (`meta.counts`), search by number, applicant name or a
  phone of the applicant or a guardian (`admissions?q=`, Bangla digits too; kept in
  `edu_admissions.search_text`, written with every change, filled for old rows by the migration).
  An application: next decisions with a note (not admitted / withdrawn asked twice), change while
  undecided (guardians only by people who may see private details, so national ids are never
  lost), **Admit** into a section with a seat (or later), then the student opens.
- **Promotion**: a list per class (or section) from a session to the next of the same kind; per
  student promote / repeat / leave / graduate (last class), next section and reason (needed to
  leave or to repeat beyond `education.max_repeats`), or everyone into one section. Hand in:
  applied at once, or with `education.promotion_approval` approved by someone else (never the
  maker) or sent back with a note; undo within `education.promotion_undo_days`. Lists show who
  made and approved them (`people`).
- **Import** (`/education/import`, `education.admit`): place, a CSV read in the browser (never
  stored; CRM's reader moved to `resources/js/lib/csv.js`), a sample file with the columns this
  reader may use (private ones only with `education.view_sensitive`, own student fields by key),
  every row checked by the server first, then the good rows admitted once.
- `GET education/setup` also gives `can.promote`, `can.approve_promotion` and the promotion rules.

### ID cards and certificates (EDU-3a backend)

- **Designs** (`/education/document-templates`, read with `education.manage` or
  `education.issue_documents`, changed with `education.manage`): kind `id_card`, `certificate`,
  `letter`; one language; page `id_card` (85.6 x 54 mm), `a4`, `a5`, portrait or landscape; items
  placed in tenths of a millimetre (whole numbers; text size in tenths of a point, line height in
  percent): `text` (with `{placeholders}`), `image`, `photo`, `qr`, `line`, `box`;
  questions asked when issuing (`inputs`, printed as `{input.key}`). `DocumentLayout` checks
  everything (unknown placeholders, items outside the page, foreign images are refused; unknown
  keys dropped). Draft -> active -> retired; changed in place with the version read.
- **Placeholders**: `student.*` (name, name_local, code, admission_no, gender, phone, date_of_birth*,
  birth_registration_no*, admitted_on, left_on, left_reason, status), `guardian.father|mother|
  primary|primary_phone`, `program`, `level`, `section`, `roll`, `session`, `batch`, `category`,
  `shift`, `medium`, `stream`, `institution.name`, `document.number|date|valid_until`,
  `field.<key>` (own student fields printed on documents) and `input.<key>`. (*) private: issuing or
  opening such a document needs `education.view_sensitive`. In Bangla, dates and rolls use Bangla
  digits.
- **Ready-made designs** (`database/data/documents/*.php`, added as drafts by
  `POST document-templates/presets/{key}/apply`): `bd_id_card`, `id_card_en`, `bd_testimonial`,
  `bd_transfer_certificate`, `bd_character_certificate`. A new country is a new data file.
- **Images** (`document-assets`): JPG/PNG/WebP up to 1 MB, stored privately, signed 30-minute
  links, switched off instead of deleted.
- **Issuing** (`POST documents`, `education.issue_documents`): an active design to up to 500
  students at once, all or nothing, safe to retry with `op_id`. Each document gets a number
  (`education.number_prefixes`: IDC/CRT/LTR) and a random 12-letter code, and keeps the design,
  values, a summary and the photo as at issue. ID cards are for studying students, valid by
  `education.id_card_valid_months` (0: to the end of their session); a second valid card needs
  `replace: true` (the old one is revoked). `POST documents/{id}/revoke` with a reason; nothing is
  deleted. Events `DocumentIssued`, `DocumentRevoked`.
- **QR check** (public, `GET /api/public/education/verify/{organization}/{code}`, 20 a minute and
  300 a day per address): valid / revoked / expired, the institution (name, logo), kind, number,
  dates, and of the student only what `education.verify_shows` allows (name, class). A wrong code,
  another institution or Education switched off get the same 404. `GET documents/{id}` gives the
  QR (SVG) for `/verify/{organization}/{code}` on the address it was opened at.

### ID card and certificate screens (EDU-3b)

- **ID cards and certificates** (menu, `education.issue_documents`): the register by kind,
  status and number; print one or many again; revoke with a reason.
- **Document designs** (settings, `education.manage`): ready-made designs to add, the designs here,
  images (upload, switch off). **Designer** (full screen, wide screens only; smaller ones show the
  design): add text, image, photo, QR, line, box; drag to move, the corner to resize, arrow keys for
  1 mm (Shift: 0.1 mm); properties in mm and pt; values inserted from a list (private ones marked);
  questions asked when issuing; preview with a real student; save with the version read; make
  active or retire.
- **Issuing**: from a student's Documents tab (any active design) or a section ("Issue ID cards" for
  everyone); a student with a valid card is asked about once (replace revokes the old one); then
  straight to printing.
- **Printing** (`/education/documents/print?ids=…`): real size in millimetres (`DocumentCanvas`);
  ID cards 10 to an A4 sheet (5 mm margin, 3 mm gap, dashed cut lines), certificates one to a page;
  revoked ones marked. The browser prints or saves a PDF (choose "Actual size", no margins).
- **QR check page** `/verify/{organization}/{code}`: public, outside the app shell (module routes
  with `meta.outside`), the institution's name and logo, valid / revoked / expired in words, icon
  and colour.

### Future expansion (Education)

- EDU-4 course registration; then fees, exams, attendance; staff ID cards (HRM); a server PDF to send by email.
- A new country, a new kind of institution, own terms: a preset file, lists, own fields and
  wording (LANG-1). No code change.

## Browser app (frontend foundation)

Vue 3 + vue-router + Tailwind 4, built by Vite. Code: `resources/js`, page shell:
`resources/views/app.blade.php` (every non-API path). Screens for Phases 1–3: sign-in,
choose workspace, overview, organizations (tree, details, settings, members, move),
modules (on/off, lock, consent, data deletion), rules (editor, preview, history,
trace), approvals, and the partner console (clients, partner rules).

```bash
npm install
npm run dev      # Vite dev server (hot reload) next to `php artisan serve`
npm run build    # production build + bundle budget check
npm test         # Vitest unit/component tests (tests/js)
```

### Sign-in for the browser: cookie session, no tokens in the browser

| method | path | notes |
|---|---|---|
| POST | /session/login | `{email, password}` → session cookie + `contexts`; throttled, audited |
| POST | /session/context | `{organization_id}` or `{partner_id}` → membership checked, session id renewed, audited |
| POST | /session/logout | session invalidated |
| GET | /api/me | user, active context (re-verified), `contexts`, `can` flags, brand |

The chosen context is stored server-side in the session (`ContextSource`), bound to
the user and expiring after the rule `tenancy.token_ttl_minutes`; ids in the body,
query or headers are ignored, exactly as with tokens. Mobile apps and integrations keep
using `/api/auth/*` tokens; a token always wins over a session. Requests carry the
CSRF token (`XSRF-TOKEN` cookie → `X-XSRF-TOKEN` header) and `X-Locale` (bn/en).

### Security

- Page shell: nonce-based Content Security Policy (no inline code without the nonce,
  no third-party origins), `X-Frame-Options: DENY`, `nosniff`, strict referrer policy.
- No `v-html` / `innerHTML`, no browser storage except UI preferences (theme,
  language) — enforced by `tests/Feature/Frontend/FrontendSourceTest.php`.
- `can` flags from `/api/me` only shape the UI; every endpoint keeps its own checks.
- Brand values are validated server-side before they reach the page (hex color only).

### Design system

Tokens in `resources/css/app.css` (`--c-*` surfaces and text, `--brand*`). A partner
provides one primary color; lighter/darker shades and a readable text color are derived
at runtime, so white-label brands need no build. Light, dark and system themes; Inter
for Latin, Hind Siliguri for Bangla (Bengali range only, size-matched); CSS logical
properties only (RTL-ready, checked by a test). Reusable parts in `resources/js/components`.

### Translations

UI text: `resources/js/locales/{en,bn}/{namespace}.json`, loaded per screen. Counts and
numbers use the language's own digits. Server messages follow the user's language.
Data labels (rule names, categories, choices) come translated from the API; a module adds
`categories` and `{rule}.options` to its `lang/{locale}/rules.php`. The key sets of
`en` and `bn` must match (test).

### Performance

Every screen is a lazy chunk. Budget, checked on every build
(`scripts/check-bundle-size.js`): first-load JS ≤ 80 KB gzip, CSS ≤ 30 KB gzip
(currently about 58 KB and 18 KB).

### Module screens

A module keeps its screens in its own folder: `Modules/{Name}/resources/js/routes.js` exports
lazy routes (`meta: { context, ns, module }`) and `locales/{locale}/{ns}.json` its texts.
`resources/js/modules.js` and `lib/i18n.js` pick them up with `import.meta.glob`, and
`app.css` scans them for Tailwind classes, so adding a module needs no change to the shell.
The source checks in `FrontendSourceTest` (no `v-html`, logical CSS, matching `en`/`bn` keys)
cover module files too.

### Future expansion (frontend)

- New language: add `resources/js/locales/{locale}/*.json` and the locale in
  `config/tenancy.php`; RTL languages already work through logical properties.
- New partner brand: data (partner settings today, `partner_brands` in Phase 5B).
- New module screens: `routes.js` + locale files inside the module (see "Module screens");
  menu entries come from the manifest. No change to the shell.
- `can` comes from real permissions (Phase 4); the UI keeps reading `can`. Phase 7 adds IndexedDB and the sync queue behind `lib/http.js`.

## App shell: sidebar, header and "My look" (UI-1)

Sidebar and header of every screen (`resources/js/layouts`): `SidebarNav.vue`,
`AppHeader.vue` (search → command palette, `QuickCreateMenu`, `NotificationBell`,
`LanguageSwitcher`, `UserMenu`), put together by `AppShell.vue`.

- **Sidebar**: platform screens in two sections (workspace, administration), then module
  entries grouped by section (`menu.section`, default the module category; labels in
  `lang/{locale}/modules.php` → `sections`). Modules with `children` open as a disclosure;
  the page being viewed opens its module and marks the sub-page (longest matching link).
  Collapsible to icons on desktop (remembered per browser): names stay as `aria-label` and
  a tooltip, counts stay numbers.
- **Header**: "New" lists the modules' `quick_actions` the person may use (none in read-only
  or export-only organizations); the bell counts work waiting here; languages are shown by
  name, never by a flag.

### API

| method | path | notes |
|---|---|---|
| GET | /api/menu | `data`: entries with `section`, `section_label`, `icon`, `children` (filtered by permission); `quick_actions` |
| GET | /api/attention | bell items `{key, label, count, path, tone}` for the current organization; empty for support staff |
| PUT | /api/me/appearance | the person's own `template`, `accent`, `color_vision`, `contrast` (`null` clears); throttled |
| GET | /api/me | adds `appearance` (what applies here, with source and lock) |

Bell items come from `AttentionProvider` classes (`app/Platform/Attention`): the platform's
(rule changes to approve, support access requests) and those modules list under `attention`.
A provider checks its own permission and returns counts only, never names or amounts.

### Brand bands

A thin strip along the bottom edge of the header in the brand's colors (`band_colors`, up to
four, side by side) and, level with it, one under the sidebar's brand row (`side_band_color`). Brand data, edited on
the partner brand screen; empty = no band. The house brand uses its logo's colors
(`config/branding.php`: blue, red, green; white for the sidebar). Decoration only, so no
contrast check; colors are validated as hex like every brand color.

### Templates, colors and who decides

Rules (category "Look and feel"): `ui.shell_template` (`classic` dark sidebar, `light`) and
`ui.accent` (`brand` or one of eight fixed colors). Overridable at platform, partner, plan,
group, company and branch.

- A level that **locks** a rule decides for everyone below; a **constraint** (`allowed`)
  narrows the choices.
- Otherwise the person's own choice (`users.ui_preferences`, one per identity, so the same in
  every organization) wins; without one, the organization's value applies.
- **Readability is never decided by an organization**: colour vision (`standard`,
  `blue_orange` for red–green colour blindness) and contrast (`standard`, `high`) are always
  the person's own. Light/dark stays a per-device choice.

The look is applied as `data-shell`, `data-vision`, `data-contrast` on `<html>`
(`lib/appearance.js`); `app.css` maps them to tokens (`--side-*`, `--c-ok/warn/bad`, text and
line colors). The Blade shell applies the last look from this browser before first paint.
Every fixed color carries white text at 4.5:1 or more (tested); states always show an icon
and words as well as a color.

"My look" screen: `/account/appearance` (from the person menu).

### Future expansion (UI-1)

- New template (e.g. icon rail, top navigation): add its value to `ui.shell_template`, the
  CSS tokens and, if the layout differs, a component chosen by `appearance.template`. No
  server code beyond the rule's list.
- New module: menu `section`/`children`, `quick_actions` and `attention` in its manifest; the
  shell needs no change. A new section needs a label in `modules.sections`.
- A partner's default or locked look: rule values at the partner level, no code.
- New language: locale files only.

## Module dashboards and settings (UI-1b)

Every module has a dashboard (`/m/{module}`) and a settings page (`/m/{module}/settings`);
the sidebar adds both to every module entry (first and last). The registry refuses a
manifest without them:

```php
'dashboard' => ['widgets' => [
    // type: stat | bars | list; size: 1-3 columns; overview: also on the main overview.
    ['key' => 'headcount', 'label' => 'hrm::dashboard.headcount', 'type' => 'stat',
     'provider' => Headcount::class, 'permission' => 'hrm.view', 'overview' => true],
]],
'settings' => ['pages' => [
    ['key' => 'fields', 'label' => 'hrm::module.menu_fields', 'route' => '/hrm/fields', 'permission' => 'hrm.configure'],
]],
```

An empty widget list shows a "coming soon" dashboard; the settings page always lists the
module's rules (the rules editor limited to the module: source, lock, approval).

- A widget is a `DashboardWidget` (`app/Platform/Dashboard`) that builds its answer with
  `WidgetData::stat()` (value, change with tone good/bad/neutral, hint, trend series),
  `::bars()` or `::list()`. It reads the current organization and its units; the models'
  tenant scope limits that to what the person may see. Its permission must be one of the
  module's; it is checked on every call.
- The app draws them (`resources/js/components/dashboard`): changes always carry an arrow
  and a sign, trend lines have a hidden table for screen readers, each widget loads and
  fails on its own.
- Overview (`/`): the modules' `overview` widgets, "Needs your action" (the same items as
  the bell, `lib/attention.js`), quick actions, workspace numbers and settings.

| method | path | notes |
|---|---|---|
| GET | /api/dashboard | overview widgets of the modules that are on here (permission-filtered) |
| GET | /api/modules/{module}/dashboard | the module's widgets the person may see; 403 `module_disabled` when off |
| GET | /api/modules/{module}/dashboard/{widget} | one widget's data |
| GET | /api/modules/{module}/settings | setup pages (permission-filtered), rule count, can turn modules on/off |

HRM widgets: employees (6-month trend, change over 30 days), joined this month, on
probation (ending within 30 days), employees by position, recent changes.

### Future expansion (UI-1b)

- A new module adds `dashboard` and `settings` to its manifest and its widget classes; no
  change to the platform or the app. New widget types need a component and a type name.
- Sector, country or partner differences come from the module's rules and data, not from
  the dashboard code. Arranging or hiding widgets per person can come later.
