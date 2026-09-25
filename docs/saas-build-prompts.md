# Multi-Org SaaS Platform: Phase-by-Phase Build Prompts

> **ব্যবহারের নিয়ম (বাংলায়)**
> - প্রথমে "Master Context" অংশটা AI coding agent-কে (Claude Code বা অন্য) দিন। এটা প্রতিটি নতুন সেশনের শুরুতেও দেবেন।
> - তারপর একবারে একটা Phase দিন।
> - প্রতিটি Phase-এর শেষে "Acceptance criteria" মিলিয়ে দেখুন। সব পাস করলে তবেই পরের Phase-এ যান।
> - Prompt-গুলো ইংরেজিতে রাখা হয়েছে, কারণ coding agent ইংরেজিতে সবচেয়ে নির্ভুল কাজ করে।
> - আপনার প্রয়োজন অনুযায়ী `[ ]` চিহ্নিত জায়গাগুলো বদলে নিন।

---

## MASTER CONTEXT (give this first, every session)

```
You are a senior Laravel architect building a multi-tenant, multi-organization,
multi-sector, multi-country SaaS ERP platform sold B2B, including as a
WHITE-LABEL product: partners (resellers, agencies, consultants) rebrand it and
sell it to their own business clients. It is also sold B2C: individuals sign up
and pay by themselves, and B2B clients give their own customers (parents,
employees, patients, shop customers) branded self-service portals (B2B2C).
Work phase by phase. Do not start a new phase until I confirm the current one.

STACK
- Backend: Laravel [13.x], PHP [8.3+], REST API (JSON), Laravel Sanctum
- Database: MySQL 8 now; code MUST stay portable to PostgreSQL
- Cache/Queue: Redis, Laravel Horizon
- Frontend: small JS bundle, lazy-loaded modules, IndexedDB for offline cache
- Modules: nwidart/laravel-modules (one folder per module)

NON-NEGOTIABLE PRINCIPLES
1. Everything is configurable, nothing sector- or country-specific is hardcoded.
2. Every module owns its own RULES, registered by the module itself, editable at
   runtime, and resolved through the ORGANIZATION HIERARCHY.
3. Hierarchy: Platform -> Partner (white-label reseller) -> Plan -> Group ->
   Company -> Branch -> Department -> Role -> User.
   A parent can set a value, set bounds, or LOCK a rule; children may only
   override inside what the parent allows.
   Clients that buy directly from us belong to our own "house" partner, so
   every tenant always has exactly one partner.
4. Tenant isolation is enforced server-side on every query. Never trust
   tenant/org IDs sent by the client. Partner isolation is equally strict:
   a partner never sees another partner's clients, and a partner sees its own
   clients' business data only through audited, time-limited support access.
5. Every module can be turned ON/OFF per hierarchy level, respecting module
   dependencies. Turning off never deletes data.
6. Primary keys are ULIDs. All timestamps stored in UTC.
7. No DB-vendor-specific SQL. Use Eloquent / Query Builder only. If raw SQL is
   unavoidable, isolate it behind an interface with MySQL and PostgreSQL versions.
8. Modules never query another module's tables directly. They communicate via
   events, contracts (interfaces), or the module's public service class.
9. Every write to config, rules, modules, permissions, money, or payroll is
   audit-logged (who, when, old value, new value, reason).
10. Every phase ships with migrations, seeders, Pest tests, and a short README
    section. Tests must include tenant-isolation tests.

OUTPUT RULES
- Before coding a phase, show me: file tree, table list, and key decisions. Wait
  for my "go".
- After coding, list what was built, how to run tests, and any open questions.
- Never silently skip a requirement. If something is unclear, ask.
```

---

## PHASE 1: Organization Hierarchy and Tenancy Foundation

```
PHASE 1 GOAL: Build the organization hierarchy, membership, and tenant isolation
that every later phase depends on.

BUILD
0. Table `partners` (top tenant owner, above organizations)
   - id (ULID), name, slug, status, is_house (our own direct-sales partner),
     parent_partner_id (nullable, for sub-resellers later), billing_mode,
     settings (JSON)
   - Table `partner_users` (partner staff + role: owner, sales, support, billing)
   - Every organization row carries partner_id (set on create, never changed
     except by a platform-level, audited "transfer client" action).
1. Table `organizations`
   - id (ULID), parent_id (nullable, self-FK), type enum:
     group | company | branch | department
   - root_id (the top group, for fast scoping), path (materialized path, e.g.
     "/01H.../01J.../") and depth, for fast ancestor/descendant queries
   - sector_key (school, hospital, factory, retail, ...; nullable below company)
   - country_code (ISO 3166-1 alpha-2), default_locale, timezone, currency_code
   - region (data residency: bd, eu, us, ...), status, settings (JSON)
   - Companies inherit country/locale/timezone/currency from parent unless set.
2. Table `organization_user` (membership)
   - user_id, organization_id, role_id, is_primary, status, invited_by
   - A user can belong to many organizations with different roles.
3. Tenant context
   - Middleware `ResolveOrganization`: reads the active organization from the
     authenticated token (NOT from request body), verifies membership, and
     stores it in a singleton `CurrentContext` (user, organization, ancestors,
     company, group, locale, country, region).
   - Trait `BelongsToOrganization` with a global scope that restricts every query
     to the current company (and optionally its descendants when the user's role
     allows group-level visibility).
   - Automatically set organization_id on create; forbid changing it on update.
4. Group-level visibility
   - A group admin can read aggregated data from all companies under the group,
     never from other groups.
5. Services
   - `HierarchyService`: ancestors(), descendants(), isAncestorOf(), move()
     (with path rebuild, audited, blocked if it would create a cycle).

ACCEPTANCE CRITERIA
- [ ] Creating group -> company -> branch -> department works with correct paths.
- [ ] User of Company A cannot read, update, or delete Company B records, even by
      guessing IDs (IDOR tests on every model).
- [ ] Group admin sees data of its own companies only.
- [ ] Changing organization_id via API is rejected.
- [ ] Moving a branch to another company rebuilds paths and is audit-logged.
- [ ] Partner A staff cannot list, read, or guess any organization of Partner B.
- [ ] Partner staff cannot read client business data without support access.
```

---

## PHASE 2: Module System (Registry, Dependencies, ON/OFF by Hierarchy)

```
PHASE 2 GOAL: A module registry where each module declares itself, its
dependencies, and where it can be enabled; ON/OFF resolved through the hierarchy.

MODULES (initial)
Business: hrm, payroll, attendance, accounting, inventory, crm, factory_erp
Platform: offline_mode, multi_currency, multi_language, api_integration,
          external_integrations
AI:       document_ai, ai_assistant
Sustainability: energy_monitoring, carbon_management
Governance: advanced_audit, custom_reports

DEPENDENCIES
payroll -> hrm, attendance | attendance -> hrm | factory_erp -> inventory, accounting
multi_currency -> accounting | carbon_management -> energy_monitoring
external_integrations -> api_integration

BUILD
1. Each module ships a manifest (module.json or its ServiceProvider) declaring:
   key, name (translatable), version, requires[], sectors[] ('*' = all),
   plans[], permissions[], rules[] (Phase 3), menu items, events emitted,
   is_core (cannot be disabled).
2. `ModuleRegistry` loads manifests at boot (cached), validates that dependency
   graph has no cycles, and exposes: all(), get(key), dependenciesOf(key),
   dependentsOf(key).
3. Table `organization_modules`
   - organization_id, module_key, state enum: enabled | disabled | inherit,
     locked (bool: children cannot change), settings (JSON), changed_by,
     changed_at, reason
4. Resolution (`ModuleResolver::isEnabled($key, $context)`)
   - Available only if the plan includes it AND the sector allows it.
   - Walk from the most specific level upward; first non-"inherit" wins.
   - If any ancestor has it disabled AND locked, it is disabled below.
   - If any required dependency resolves to disabled, the module is disabled.
   - Cache the resolved map per organization in Redis; invalidate on change.
5. Toggle API
   - POST /api/modules/{key}/enable | /disable at a given organization level.
   - Enabling auto-enables missing dependencies (return the list in response).
   - Disabling also disables dependents (return list; require confirm=true).
   - Reject if a parent locked it, if plan/sector does not allow it, or if the
     user lacks `modules.manage` at that level.
6. Enforcement in THREE places
   - Route middleware `module:{key}` (403 with a clear message).
   - Menu/navigation API returns only enabled modules.
   - Queued jobs and scheduled tasks check the module before running.
7. Disabling never deletes data. Add a separate "purge module data" action with
   typed confirmation, a 7-day delay, and audit log.
8. Security side effects on disable (via events):
   - offline_mode off -> block /sync, send wipe flag on next device contact
   - api_integration off -> revoke all API tokens of that organization
   - external_integrations off -> stop webhooks, deactivate stored third-party keys
   - ai modules require explicit admin consent record before enable

ACCEPTANCE CRITERIA
- [ ] Enabling payroll auto-enables hrm and attendance.
- [ ] Disabling hrm disables attendance and payroll (after confirm).
- [ ] Group locks crm=off -> no company/branch under it can enable crm.
- [ ] Direct API call to a disabled module returns 403.
- [ ] Scheduled payroll job does not run for an org where payroll is off.
- [ ] Every toggle appears in the audit log with reason.
```

---

## PHASE 3: Per-Module Rule Engine with Hierarchy (core of the platform)

```
PHASE 3 GOAL: Every module has its own editable rule set. Rules are defined by
the module, stored as data, resolved through the organization hierarchy, and
editable at runtime without code changes.

CONCEPTS
- Rule DEFINITION: declared by a module (code). Describes key, type, default,
  validation schema, who may edit, at which levels it may be overridden.
- Rule VALUE: stored in DB at a hierarchy scope (platform, partner, plan, group,
  company, branch, department, role, user) with optional effective dates.
- Rule CONSTRAINT: a parent may restrict what children can set (min/max, allowed
  options subset, or full lock).

BUILD
1. Table `rule_definitions` (synced from module manifests on deploy)
   - key (e.g. "attendance.late_grace_minutes"), module_key, type enum:
     boolean | integer | decimal | string | enum | multi_enum | duration |
     money | time | date | json | table (for slabs, e.g. tax brackets)
   - schema (JSON Schema for validation), default_value (JSON)
   - label, description (translatable JSON)
   - overridable_levels (e.g. ["group","company","branch"])
   - edit_permission, requires_approval (bool: maker-checker),
     sensitive (bool: extra audit + approval), country_specific (bool)
   - group/category for UI, sort_order, deprecated_at
2. Table `rule_values`
   - id, rule_key, scope_type, scope_id, value (JSON)
   - mode enum: set | constrain | lock
     * set: this level's value
     * constrain: bounds for descendants (min, max, allowed[])
     * lock: fixed value, descendants cannot override
   - effective_from, effective_to (nullable), version (int)
   - status enum: active | pending_approval | rejected | superseded
   - created_by, approved_by, reason
3. Table `rule_value_history` (immutable): every change, old/new, who, why.
4. `RuleResolver::get(string $key, ?Context $ctx = null): ResolvedRule`
   Returns value + source level + source scope id + locked flag + constraints.
   Resolution algorithm:
     a. Start with definition default.
     b. Apply platform -> partner -> plan -> group -> company -> branch ->
        department -> role -> user, in order, using only rows active for "now"
        (or a given date, for payroll back-calculation).
     c. If a level has mode=lock, stop: descendants are ignored.
     d. Collect all "constrain" rows from ancestors; the final value must satisfy
        ALL of them, otherwise fall back to the nearest valid ancestor value and
        log a warning.
     e. Country-specific rules: pick the row matching the organization's country
        first, then the generic one.
   Also: getMany(prefix), explain(key) (full trace of every level, for the UI).
5. Caching: resolved rules per (organization, role, user) in Redis, tagged by
   organization path; invalidate descendants when a parent changes.
6. Writing rules (`RuleService::set`)
   - Validate against JSON Schema AND against every ancestor constraint.
   - Reject if an ancestor locked it or the level is not in overridable_levels.
   - If requires_approval: save as pending_approval; a different user with
     approval permission must approve (maker-checker). Self-approval forbidden.
   - Future-dated changes allowed (effective_from in the future).
   - Rollback: restore any previous version from history (new audited change).
7. How modules use rules
   - Modules call RuleResolver only through a facade, e.g.
     Rules::get('payroll.overtime_multiplier').
   - No module hardcodes a business number. Code review rule: magic numbers in
     module business logic are rejected.
8. Rule editor API + UI
   - For a selected org level, list rules grouped by module/category showing:
     effective value, where it comes from (inherited from X), lock icon,
     constraints, "Override", "Reset to inherited", "Lock for children",
     "Set bounds for children".
   - "Preview impact": which descendant organizations would change.
   - History tab with diff and rollback.
9. Seed example rules per module (make them real, not placeholders):
   hrm:        probation_days (int), notice_period_days (int),
               employee_code_format (string pattern)
   attendance: late_grace_minutes (int), weekend_days (multi_enum),
               half_day_after_minutes (int), geo_fence_required (bool)
   payroll:    overtime_multiplier (decimal), pay_cycle (enum: monthly|biweekly),
               tax_slabs (table, country_specific), salary_approval_levels (int)
   accounting: fiscal_year_start (date MM-DD), journal_approval_above (money),
               allow_backdated_entries_days (int)
   inventory:  valuation_method (enum: FIFO|weighted_average),
               allow_negative_stock (bool), low_stock_alert_percent (int)
   offline_mode: offline_lease_hours (int, per role),
               max_cached_records (int), allow_offline_payments (bool)
   ai_assistant: data_scope (enum: own_records|branch|company)
10. Offline integration: the resolved rule set (only rules the device needs) is
    included in the offline lease with a rule_version; the sync endpoint rejects
    operations evaluated with an outdated rule_version for sensitive rules.

ACCEPTANCE CRITERIA
- [ ] Group sets attendance.late_grace_minutes constrain max=15; a branch trying
      to set 30 is rejected with a clear message.
- [ ] Group locks inventory.valuation_method=FIFO; no descendant can change it.
- [ ] Branch override wins over company value when not locked/constrained.
- [ ] Reset-to-inherit removes the override and value falls back correctly.
- [ ] explain() shows the full resolution trace.
- [ ] Future-dated payroll rule applies only from its effective date; payroll
      for past months uses the rule valid at that time.
- [ ] Sensitive rule change needs approval by a second user.
- [ ] Cache invalidates for all descendants on a parent change.
- [ ] A new module can add rules purely via its manifest, no core code change.
```

---

## PHASE 4: Roles, Permissions, and Hierarchy-Aware Authorization

```
PHASE 4 GOAL: Role-based and hierarchy-based access control that also covers
modules and rules.

BUILD
1. spatie/laravel-permission with team = organization.
2. Permissions are declared by each module's manifest (e.g. payroll.view,
   payroll.run, payroll.approve, rules.edit.payroll).
3. Role templates per sector; organizations can clone and customize roles
   within limits set by their parent (a branch cannot grant a permission its
   company does not have).
4. Policies for every model: check permission + organization scope + module
   enabled.
5. Separation of duties: configurable pairs that cannot be held by the same
   user (e.g. payroll.run and payroll.approve) as a rule in Phase 3.

ACCEPTANCE CRITERIA
- [ ] Branch admin cannot grant permissions beyond the company's set.
- [ ] Hiding a button is never the only protection; API tests prove it.
- [ ] Separation-of-duties violations are blocked.
```

---

## PHASE 5: Plans, Sectors, and Feature Packaging

```
PHASE 5 GOAL: Sell sector packages and plans using the module + rule system.

BUILD
1. Table `plans`: key, name, price per currency, included modules, limits
   (users, branches, storage), default rule values (plan-level rule_values).
2. Table `sector_packages`: sector_key, recommended modules, default rules,
   default roles, demo data seeder.
3. Onboarding: when a company picks a sector + plan, apply package defaults as
   company-level rule values (not hardcoded), so they remain editable.
4. Usage limits enforced server-side with clear upgrade messages.

ACCEPTANCE CRITERIA
- [ ] New school company gets school modules, roles, and rules automatically.
- [ ] Downgrading a plan disables modules no longer included (data kept).
- [ ] Adding a new sector requires only data/config + optional new module.
```

---

## PHASE 5B: B2B White-Label and Partner Layer

```
PHASE 5B GOAL: Partners can rebrand the platform, run it on their own domain,
package and price it, and manage their own clients, without ever breaking
isolation or seeing what they should not.

BUILD
1. Branding (table `partner_brands`, one per partner; clients may get a
   sub-brand only if the partner allows it via a rule)
   - product name, logos (light/dark), favicon, primary/secondary colors
     (validated for contrast), fonts from an approved list, login page text,
     support email/phone, legal links (terms, privacy), footer text
   - PWA manifest and app name generated per brand
   - Frontend reads brand tokens at runtime (CSS variables); no per-partner
     builds or code forks
   - "Powered by" badge controlled by a partner-level rule (plan decides if
     it can be removed)
2. Domains (table `partner_domains`)
   - custom domain per partner (erp.partner.com) and optional per client
     (client.partner.com); tenant/brand resolved from the Host header
   - domain ownership verification via DNS TXT before activation
   - automatic TLS certificates (e.g. Caddy on-demand TLS or Let's Encrypt
     automation) with an allowlist of verified domains only
   - unknown Host header -> reject, never fall back to another tenant
3. Branded communication
   - email: per-partner sender domain with SPF, DKIM, DMARC verification before
     use; fallback to platform domain with partner name as display name
   - SMS sender ID and templates per partner (country rules apply)
   - all notification templates overridable at partner level, translatable
4. Partner console (separate area, separate permissions)
   - create and manage client organizations, assign plans, see usage and billing
   - enable/disable modules and set/lock rules at partner level for all clients
   - create partner-specific plans from wholesale plans (see billing)
   - no default access to client business data
5. Support access (break-glass)
   - partner or platform staff request access to one client org, with reason
   - client admin approves (or a rule allows auto-approval for severity levels)
   - access is time-limited (e.g. 2 hours), read-only by default, fully audited,
     and visible to the client in their audit log
6. Billing models (configurable per partner)
   - wholesale: we bill the partner per client/seat; partner bills its clients
     at its own price
   - revenue share: we bill clients directly under the partner brand and pay
     the partner a percentage
   - invoices, credit notes and payouts in the partner's currency; tables for
     wholesale_prices, partner_plans, partner_invoices, commissions
7. Limits and governance
   - platform-level rules cap what a partner can do (max clients, allowed
     modules, allowed countries, whether sub-resellers are allowed)
   - partner suspension: clients keep read access and data export for a grace
     period defined by contract; no data is deleted automatically
8. Data ownership and exit
   - clients own their data: full export (JSON/CSV + files) available to the
     client admin at any time
   - "transfer client to another partner or to house" flow, audited, with the
     client's consent
   - DPA / terms templates per partner stored and versioned
9. API
   - partner-scoped API keys to provision clients, users and plans (for the
     partner's own website or CRM); rate-limited and audited

ACCEPTANCE CRITERIA
- [ ] Visiting erp.partnerA.com shows only Partner A branding; an unknown or
      unverified domain is rejected.
- [ ] A custom domain cannot be activated without DNS verification.
- [ ] Emails for Partner A clients go out from Partner A's verified domain.
- [ ] Partner A console cannot list or reach Partner B clients (tests).
- [ ] Partner staff reading client data without approved support access -> 403.
- [ ] Support access expires automatically and appears in the client's audit log.
- [ ] Partner locks a rule (e.g. inventory.valuation_method) for all its clients;
      clients cannot override it.
- [ ] Wholesale and revenue-share invoices calculate correctly (tests with
      multiple currencies).
- [ ] Client can export all its data even when its partner is suspended.
```

---

## PHASE 5C: B2C (Self-Serve Individuals) and B2B2C Portals

```
PHASE 5C GOAL: Individuals can sign up, pay and use the product by themselves,
and B2B clients can give their own customers (parents, students, employees,
patients, shop customers) a branded self-service portal. Same hierarchy, same
security, no separate codebase.

BUSINESS MODEL
- B2C self-serve: freemium or free trial -> paid plan, monthly/yearly,
  local payment methods (bKash, Nagad, cards) per country rules
- B2B2C portals: included in or sold as an add-on to the client's plan
- Upgrade path: personal -> team -> company without losing data

BUILD
1. Identity
   - One global `users` identity; memberships link a user to organizations
     with a membership type: staff | portal | owner
   - Sign up with email or phone + OTP; optional Google/Apple login; passkeys
   - Verification required before any paid or data-sharing action
   - Account recovery that cannot be used for takeover (OTP + cooldown +
     notification to old channel)
2. Personal workspaces
   - Organization type `personal` under the house partner (or under a partner
     for white-label B2C), created automatically at signup
   - B2C plans with limits (records, storage, modules) driven by rules
   - "Upgrade to team/company": change type, invite members, keep all data
3. Self-serve billing
   - Checkout, subscriptions, trials, coupons, proration, dunning (failed
     payment retries + grace period), receipts/VAT invoices per country
   - Payment gateway drivers behind the PaymentGateway interface; webhooks
     verified by signature and idempotent
   - Never store card data; use gateway tokens only
4. Onboarding and UX
   - Guided first-run setup (sector, language, country), sample data option,
     in-app help, no training needed
   - Everything works on a phone first; Bangla and English from day one
5. B2B2C portals (per client organization, branded by partner/client)
   - Portal roles with minimal permissions: parent sees own children only,
     employee sees own payslips/attendance, customer sees own invoices/orders
   - Linking a portal user to records via invitation code or verified phone,
     approved by the client organization
   - Portal module on/off and portal rules (what fields are visible, whether
     online payment is allowed) through the normal module and rule system
6. Abuse and fraud protection (B2C is public, expect bots)
   - Bot protection (e.g. Turnstile/hCaptcha) on signup, login, OTP, password
     reset; OTP rate limits per phone, IP and device; SMS pumping protection
   - Disposable-email blocking rule, trial-abuse detection (same device/payment
     method creating many trials)
   - Suspicious activity alerts in Phase 9 monitoring
7. Consumer privacy
   - Clear consent records (terms, privacy, marketing opt-in) with version
   - Self-service: download my data, delete my account (with grace period),
     manage sessions and devices
   - Data retention rules per country, configurable through the rule engine
8. Scale and cost
   - B2C means many small tenants: shared DB only, strict indexes on
     (organization_id, ...), per-tenant quotas, cheap default storage tier
   - Support automation: help center, in-app tickets; human support by plan

ACCEPTANCE CRITERIA
- [ ] A new user signs up with phone OTP, gets a personal workspace, starts a
      trial, and pays with a local gateway, all without staff help.
- [ ] Failed payment moves the workspace through grace period to read-only,
      never deleting data.
- [ ] Upgrading personal -> company keeps all records and adds members.
- [ ] A parent portal user sees only their own children's records (tests try
      other children's IDs and fail).
- [ ] The same person as employee in Company A and as a personal B2C user
      cannot see one context's data from the other.
- [ ] OTP endpoint blocks bursts from one IP/phone; signup requires bot check.
- [ ] "Delete my account" removes personal data after the grace period, while
      records owned by a B2B client stay with that client.
```

---

## PHASE 6: Multi-Country and Multi-Language

```
PHASE 6 GOAL: Any country, any language, driven by configuration.

BUILD
1. Table `countries`: code, currency, decimal rules, date format, week start,
   weekend days default, fiscal year default, phone format, address format,
   tax profile key, data_residency_region, supported payment gateways.
2. Country values feed Phase 3 as defaults (country-specific rules).
3. Money: store amount as integer minor units + currency_code. Exchange rates
   table with date; never use float for money.
4. Payment gateways behind a `PaymentGateway` interface; drivers: bkash,
   sslcommerz, stripe, [more]. Enabled per country + organization.
5. Languages
   - UI strings: lang/{locale}.json, lazy-loaded on the frontend per module.
   - Data translations: spatie/laravel-translatable for names/labels.
   - Locale resolution: user -> organization -> country -> platform default.
   - RTL support via CSS logical properties; number/date via Intl.
6. Timezones: UTC in DB, convert for display using org/user timezone.

ACCEPTANCE CRITERIA
- [ ] Same code runs a Bangladesh school (BDT, bn) and a Saudi company
      (SAR, ar, RTL) correctly.
- [ ] Adding a new country is data-only.
- [ ] No float money anywhere (static check).
```

---

## PHASE 7: Offline Mode and Secure Sync

```
PHASE 7 GOAL: Offline-first for enabled organizations, secure by design.

BUILD
1. Client: IndexedDB with encrypted records (Web Crypto AES-GCM, non-extractable
   key), durable operation queue, navigator.storage.persist(), visible pending
   count, logout wipe.
2. Offline lease (signed by server): user, org, role, permissions, rule snapshot
   + rule_version, expires_at (from rule offline_lease_hours).
3. Tables: devices (trusted, last_seen, revoked_at), sync_operations
   (op_id unique, device_id, result), sync_quarantine.
4. POST /api/sync pipeline, in this order:
   a. revalidate token, device, user status, org status, module enabled
   b. verify lease signature and expiry
   c. idempotency: known op_id -> return stored result
   d. validation + policy + tenant scope, as a fresh request
   e. optimistic concurrency via version column; conflicts returned per record
   f. money/payment records are append-only, never overwritten
   g. ops from revoked users go to quarantine for admin review
   h. respond with results, delta since last sync (incl. soft deletes),
      new lease
5. Remote wipe: revoked device receives wipe flag and clears local data.

ACCEPTANCE CRITERIA
- [ ] Same op sent twice applies once.
- [ ] Stale version produces a conflict, not an overwrite.
- [ ] Expired lease blocks new offline writes.
- [ ] Revoked device is wiped on next contact.
- [ ] Payments created offline by a revoked user land in quarantine.
```

---

## PHASE 8: Security Hardening (12 layers)

```
PHASE 8 GOAL: Implement and test these layers. Produce a checklist with
evidence (test name or config file) for each.

1  Identity: MFA (TOTP), passkeys (WebAuthn), secure cookies, session regeneration
2  Authorization: RBAC + org-scope + module + rule checks (Phases 1-4)
3  API: rate limits per user/org/IP, strict schema validation, reject unknown
   fields, pagination caps, export limits
4  Database: least-privilege DB user, tenant isolation tests, encrypted backups
5  Application: SQLi (bindings only), XSS (escaping + CSP), CSRF (Sanctum SPA),
   mass assignment ($fillable), secure file upload outside public
6  Device: trusted devices list, revocation, active sessions screen
7  Offline data: local encryption, minimal cache (Phase 7)
8  Sync: idempotency, versioning, replay protection (Phase 7)
9  Infrastructure: firewall, private DB/Redis, APP_DEBUG=false, HTTPS + HSTS,
   security headers
10 Monitoring: centralized logs, alerts (failed logins, 403 spikes, cross-tenant
   attempts, large exports, rule/module changes)
11 Recovery: immutable/offline backups, monthly restore drill script
12 Future readiness: composer/npm audit in CI, Dependabot, SBOM, crypto config
   with key versioning, AI features tested for prompt injection and data scope

ACCEPTANCE CRITERIA
- [ ] Every layer has at least one automated test or verifiable config.
- [ ] Cross-tenant test suite passes for every model and endpoint.
```

---

## PHASE 9: Audit, Monitoring, and Incident Response

```
PHASE 9 GOAL: Know what happened, detect abuse, recover quickly.

BUILD
1. Unified audit log (append-only table, optionally shipped to external store):
   actor, organization, action, target, old/new, ip, device, reason.
2. Core audit (always on): money, payroll, rules, modules, permissions, logins.
   The advanced_audit module adds reports, exports, retention settings.
3. Alerts via queued notifications (email/Slack/SMS drivers).
4. Incident playbook as a markdown file in the repo: roles, steps, token
   revocation commands, tenant notification template.
5. Health dashboard: queue depth, failed jobs, sync errors, rule cache hit rate.

ACCEPTANCE CRITERIA
- [ ] Changing a payroll rule produces an audit entry even if advanced_audit is off.
- [ ] Simulated brute-force triggers an alert.
- [ ] Restore drill script restores a backup into a staging DB.
```

---

## PHASE 10: Database Portability and Scaling

```
PHASE 10 GOAL: Prove the system can move beyond a single MySQL.

BUILD
1. Run the full test suite on MySQL 8 AND PostgreSQL 16 in CI.
2. Tenancy strategies via stancl/tenancy (or equivalent), switchable per
   organization: shared DB (default) | dedicated DB per group | per-region DB.
3. Read replicas for reports; heavy analytics exported to a separate store
   ([ClickHouse or similar]) via queued jobs.
4. Tool to migrate one group from shared DB to a dedicated DB with zero data loss
   and a verification report.

ACCEPTANCE CRITERIA
- [ ] Green CI on both MySQL and PostgreSQL.
- [ ] A test group moved to its own DB still passes all isolation tests.
```

---

## PHASE 11: Independent Validation and Release

```
PHASE 11 GOAL: Release only when verified.

BUILD
1. Test coverage report; critical paths (auth, tenancy, rules, payroll, sync)
   must be fully covered.
2. Prepare a penetration-test scope document (endpoints, roles, test tenants).
   Testing is done only by an authorized party with written permission.
3. Remediation tracker: each finding -> fix -> regression test.
4. Release checklist: all critical/high findings closed, backups verified,
   rollback plan written.

ACCEPTANCE CRITERIA
- [ ] Zero open critical/high findings.
- [ ] Every fixed finding has a regression test.
```

---

## Adding a NEW module later (template prompt)

```
Create a new module "[module_key]" following the platform conventions:
- Manifest with: name (translatable), requires [..], sectors [..], plans [..],
  permissions [..], rules [..] with full definitions (type, schema, default,
  overridable_levels, requires_approval, sensitive), menu, events.
- Uses Rules::get() for every business number, no hardcoded values.
- All models use BelongsToOrganization, ULIDs, audit logging.
- Routes protected by module:[module_key] middleware and policies.
- Communicates with other modules only via events/contracts.
- Pest tests: tenant isolation, module-off 403, each rule's hierarchy behaviour.
Show me the manifest and rule list first, then wait for my "go".
```
