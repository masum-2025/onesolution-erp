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
- Allowed parent types and max depth: `config('tenancy.allowed_parents')`, `max_depth`
  (move to the rule engine in Phase 3).
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

Branch-level restriction inside a company arrives with Phase 4 permissions.
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
| GET/POST | /api/organizations | list visible / create child (owner) |
| GET/PATCH | /api/organizations/{id} | tree fields rejected with "use move" |
| GET | /api/organizations/{id}/settings | effective values + source |
| POST | /api/organizations/{id}/move | `{new_parent_id, reason}`, owner, throttled |
| GET/POST | /api/organizations/{id}/members | owner |
| PATCH | /api/organizations/{id}/members/{membershipId} | owner, not own membership |
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

### Future expansion

- New sector, country or partner: data only (`sector_key`, organization country fields,
  a `partners` row). No code change.
- New organization type or parent rule: `config/tenancy.php` now, rule data after Phase 3.
- Interim authorization (`owner` membership manages) is replaced by Phase 4 permissions;
  `role_id` is already on memberships.
- Maker-checker for moves and support access for partners: Phase 3 and Phase 5B.
