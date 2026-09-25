# CLAUDE.md — One Solutions Platform

Read this file fully before every task. These rules apply to ALL code in this
repository. Never lower the standard to finish faster.

## 1. What we are building

A multi-tenant, multi-organization, multi-sector, multi-country SaaS ERP platform
by One Solutions, sold in three ways:

- **B2B direct:** companies and groups buy from One Solutions.
- **B2B white-label:** partners (resellers, agencies, consultants) rebrand it
  for their own clients.
- **B2C:** individuals sign up and pay by themselves (self-serve), AND the end
  customers of our B2B clients (parents, students, employees, patients, shop
  customers) use client-branded portals (B2B2C).

- Backend: Laravel 13, PHP 8.3+, REST JSON API, Sanctum
- Database: MySQL 8 today, must stay portable to PostgreSQL
- Cache/Queue: Redis + Horizon
- Frontend: small JS bundle, lazy-loaded modules, IndexedDB offline cache
- Modules: nwidart/laravel-modules, one folder per module

### Hierarchy (used for tenancy, modules, rules, permissions, branding)

```
Platform
 └─ Partner            (white-label reseller; direct clients use the "house" partner)
     └─ Plan
         └─ Group      (group of companies)
             └─ Company  (sector, country, currency, locale set here)
                 └─ Branch
                     └─ Department
                         └─ Role
                             └─ User
```

A parent can SET a value, CONSTRAIN children (min/max/allowed), or LOCK it.
Children only override inside what the parent allows.

B2C fits the same hierarchy, with no special code paths:
- A self-serve individual = house partner -> B2C plan -> an organization of type
  `personal` with one owner. Upgrading to a team or company is a type change,
  not a data migration.
- A B2B client's end customer (parent, employee, patient) is a user with a
  limited "portal" membership in that client's organization, seeing only their
  own records.
- One person = one identity (one login) with many memberships. Data from one
  membership is never visible from another.

## 2. How to work on every task (mandatory order)

1. **Business logic first.** State in a few lines:
   - who uses this (partner, group, company, branch, user) and which roles
   - which sector(s), plan(s) and module it belongs to
   - what can differ by country, language or partner
2. **Technology logic second.**
   - module ownership and dependencies (never touch another module's tables)
   - which values must be rules (Rule engine), not hardcoded
   - on/off behaviour when the module is disabled at any level
   - impact on offline sync, white-label branding, multi-currency, multi-language
3. **Security review** (section 4) for this specific change.
4. **User-friendliness review** (section 5).
5. **Show the plan** (file tree, tables/columns, endpoints, rules, events,
   open questions) and WAIT for "go" before writing code, unless the change
   is a trivial fix.
6. **Write code + tests.** Then report what was built, how to test, what is left.

If a requirement is unclear or conflicts with these rules, ask. Never silently
skip or simplify a requirement.

## 3. Non-negotiable architecture rules

1. Nothing sector-, country-, partner- or customer-specific is hardcoded.
   Business numbers come from `Rules::get('module.rule_key')`.
2. Every module declares in its manifest: key, name (translatable), requires[],
   sectors[], plans[], permissions[], rules[], menu, events.
3. Modules talk to each other only through events, contracts (interfaces) or a
   module's public service class.
4. Every module can be turned ON/OFF per hierarchy level, respecting
   dependencies. Enforce in routes (`module:{key}` middleware), menus, jobs and
   scheduled tasks. Turning off never deletes data.
5. Primary keys: ULID. Timestamps: UTC. Money: integer minor units + currency
   code, never float.
6. No DB-vendor-specific SQL. Eloquent / Query Builder only; unavoidable raw SQL
   goes behind an interface with MySQL and PostgreSQL implementations.
7. Branding (name, logo, colors, emails, domains) is runtime data per partner,
   never hardcoded and never a separate build.
8. UI text through translation keys; data labels through translatable fields;
   CSS uses logical properties (RTL-ready).
9. Offline-capable writes carry `op_id` (idempotency) and `base_version`
   (optimistic concurrency). Money records are append-only.

## 4. Security checklist (apply to every change)

- [ ] Tenant scope: model uses `BelongsToOrganization`; org/partner IDs come
      from the authenticated context, never from the request body
- [ ] Partner isolation: a partner never reaches another partner's clients;
      client business data only via audited, time-limited support access
- [ ] Authorization: Policy checks permission + organization scope + module
      enabled; hiding UI is never the only protection
- [ ] Validation: Form Request for every input; reject unknown fields;
      `$fillable` set; no `$request->all()` into models
- [ ] Output: escaped by default; no `{!! !!}` with user data; CSP respected
- [ ] Files: type/size checked, stored outside public, served via signed URLs
- [ ] Rate limits on auth, exports, bulk and public endpoints
- [ ] Audit log for changes to money, payroll, rules, modules, permissions,
      branding, domains, and logins
- [ ] No secrets, tokens or personal data in logs, IndexedDB or error messages
- [ ] Sensitive rule changes use maker-checker approval
- [ ] B2C / public endpoints: bot protection on signup and login, email/phone
      verification, abuse limits per IP and device, safe account recovery
- [ ] Portal users (parents, employees, customers) see only records linked to
      them, enforced by policy tests

## 5. User-friendliness checklist

- Works in Bangla and English from day one; other languages by adding files
- Clear, specific error messages that tell the user what to do next
- Mobile-first layouts; usable on slow networks; small, lazy-loaded bundles
- Loading, empty and error states for every screen
- Destructive actions need confirmation; offer undo where possible
- Show where a setting comes from (inherited from company, locked by group)

## 6. Code conventions

- Controllers thin; logic in Actions/Services inside the module
- DTOs or Form Requests for input; API Resources for output
- Events for cross-module side effects; queued listeners for slow work
- Config and rules over conditionals: no `if ($sector === 'school')` in code
- Name things by business meaning (`AdmitStudent`, not `ProcessData`)

## 7. Definition of done

- [ ] Migrations reversible; seeders for rules, permissions, menu
- [ ] Pest tests: happy path, validation, permission denied, module disabled
      (403), rule hierarchy behaviour, and TENANT + PARTNER ISOLATION
- [ ] Runs on MySQL and PostgreSQL in CI
- [ ] Translations added (bn, en)
- [ ] README section for the module/feature updated
- [ ] Short note on future expansion: what would change for a new sector,
      country or partner, and confirmation that no code change is needed for it
