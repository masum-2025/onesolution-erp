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

- **Clients:** a new client is a group with its first company (sector package applied)
  and an owner who already has an account. Suspending blocks every sign-in, tokens too.
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
- Open: client sub-brands and partner API keys (5B-5); moving a single company out of a
  group; e-signature providers for DPAs.

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
