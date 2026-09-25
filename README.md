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

- New sector, country or partner: data only (`sector_key`, organization country fields,
  a `partners` row). No code change.
- New parent rule for a partner: set `tenancy.allowed_parents` at partner level (data).
- Authorization is permission-based since Phase 4 (roles live in `membership_roles`).
- Maker-checker for moves and support access for partners: Phase 3 and Phase 5B.

## Module system (Phase 2)

Code: `app/Platform/Modules`. Module folders: `Modules/{Name}` (nwidart/laravel-modules).
Config: `config/platform_modules.php`, interim plan catalog `config/plans.php`.

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

1. Available: plan (`organizations.plan_key`, inherited, default `starter`) and effective
   sector allow it; modules with `requires_consent` need an active consent at the
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

- New module: add a folder with a manifest; no core change. New plan: add its key to
  `config/plans.php` (Phase 5: the plans table). New sector: manifest data only.
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
