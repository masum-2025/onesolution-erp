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
    'menu' => [['key' => 'payroll', 'label' => 'payroll::module.menu', 'route' => '/payroll', 'order' => 30]],
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

- New country: a data file. New language: its translation files plus one config entry.
- Later in Phase 6: Arabic texts, exchange rates, bKash and Stripe drivers (6-2).

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
(currently about 51 KB and 17 KB).

### Future expansion (frontend)

- New language: add `resources/js/locales/{locale}/*.json` and the locale in
  `config/tenancy.php`; RTL languages already work through logical properties.
- New partner brand: data (partner settings today, `partner_brands` in Phase 5B).
- New module screens: a lazy route + a locale namespace; menu entries come from the
  manifest. No change to the shell.
- `can` comes from real permissions (Phase 4); the UI keeps reading `can`. Phase 7 adds IndexedDB and the sync queue behind `lib/http.js`.
