# Security checklist (Phase 8)

Every layer of the Phase 8 plan, with the evidence that it holds: an automated
test (Pest `tests/Feature/...`, Vitest `tests/js/...`) or a file you can check.
"Operator" items are server settings the code cannot enforce by itself;
`php artisan security:check` verifies the ones it can see.

Status: **done** = built and tested; **operator** = documented, checked on the
server; **later** = planned in a named phase.

| # | Layer | Status | Evidence |
|---|-------|--------|----------|
| 1 | Identity | done | `Identity/TwoFactorTest` (TOTP, recovery codes, passkeys bound to the address, replay refused, step-up, required by rule); `Tenancy/AuthContextTest` (same answer for wrong password and unknown email, throttled, expired tokens); `Identity/AccountTest` (session regeneration, sign out everywhere, ended sessions refused); `Security/HeadersAndLimitsTest` (CSRF); `security:check` (secure, encrypted, HttpOnly, SameSite cookies) |
| 2 | Authorization | done | `Access/AuthorizationTest` (RBAC, branch limits, separation of duties); `Modules/EnforcementTest` (module off = 403, jobs and schedules skip); `Rules/*`; `Security/CrossTenantRouteSweepTest` (every endpoint with an organization or client in its address, every method, another tenant's id = 404 before validation, nothing written); `Tenancy/TenantIsolationTest`, `Tenancy/PartnerIsolationTest`, `Frontend/SessionAuthTest` |
| 3 | API | done | `Security/HeadersAndLimitsTest` (limits per person, per organization and per address; the address limit counts requests with bad tokens too; pages capped at 100); `Security/PublicRoutesTest` (sign-in or API key everywhere except a reviewed list; every public write is limited); `Architecture/ApplicationSafetyTest` (every form request rejects unknown fields, every list uses `PerPage`); export limits: `DataExport/*` (5 per hour, one at a time) |
| 4 | Database | done + operator | `Tenancy/TenantIsolationTest`, `Architecture/TenantModelsTest`, the route sweep; encrypted backups: `Security/BackupTest`; least-privilege user: [ops/db-least-privilege.md](ops/db-least-privilege.md), checked by `security:check` (`db_not_admin_user`, `db_password`) |
| 5 | Application | done | `Architecture/ApplicationSafetyTest`: no raw SQL, no `{!! !!}`/`v-html`, no whole-request mass assignment, every model declares its fields, no uploads on the public disk, no debug output; `Frontend/AppShellTest` (nonce CSP, escaped brand data); `Frontend/FrontendSourceTest`; CSRF: `Security/HeadersAndLimitsTest`; uploads: type and size in `UploadBrandAssetRequest`, `ClientBrandRequest`, stored on the private disk and served by controllers |
| 6 | Device | done | `Identity/AccountTest` (active sessions list, sign one out for good); `Offline/SyncTest` (trusted devices, revoke, wipe); My account > Devices and Security screens |
| 7 | Offline data | done | `tests/js/offline.test.js` (AES-GCM store, non-extractable key, one store per person and organization, wiped on sign-out); `Frontend/FrontendSourceTest` (browser storage only through the offline store) |
| 8 | Sync | done | `Offline/SyncTest` (op ids applied once, stale version = conflict, forged or expired leases refused, stale rules refused, revoked people's payments held) |
| 9 | Infrastructure | done + operator | `Security/HeadersAndLimitsTest` (HSTS on HTTPS only, nosniff and referrer policy on every response); `Frontend/AppShellTest` (CSP, framing); `Security/SecurityCheckTest` (`security:check` fails a production deploy on `APP_DEBUG=true`, plain HTTP, admin DB user, missing keys…); firewall, private DB/Redis, TLS: [ops/server-hardening.md](ops/server-hardening.md) |
| 10 | Monitoring | done (log, audit) + later (alerts, Phase 9-2) | `Audit/AuditCoreTest` (core audit always on, device and session named, shipping to an external store); `Audit/AdvancedAuditTest` (reports, exports, retention); `Security/SecurityLogTest` (failed sign-ins, refused requests with their code, rule and module changes; no emails, passwords, codes or tokens); `Security/CrossTenantRouteSweepTest` (cross-tenant attempts logged); JSON channel `security` in `config/logging.php`; alerts on spikes: Phase 9 |
| 11 | Recovery | done + operator | `Security/BackupTest` (encrypted and signed sets, changed or cut files refused, key rotation, retention, restore drill into a separate database, never the app's own); CI job "Backup and restore drill" runs the real `mysqldump`/`pg_dump` on MySQL and PostgreSQL; schedule in `routes/console.php`; off-site write-once storage: [ops/backups.md](ops/backups.md) |
| 12 | Future readiness | done + later (AI) | `.github/workflows/supply-chain.yml` (`composer audit`, `npm audit`, CycloneDX SBOM, weekly); `.github/dependabot.yml`; key versioning: backups (`BACKUP_KEY_V*`, `BackupTest`), app encryption (`APP_PREVIOUS_KEYS`); AI prompt injection and data scope: no AI feature has logic yet; tests come with the first one (rule `ai_assistant.data_scope` and consent records already exist) |

## Acceptance criteria

- [x] Every layer has at least one automated test or verifiable config (table above).
- [x] Cross-tenant suite passes for every model and endpoint:
  `Architecture/TenantModelsTest` (every tenant model is scoped),
  `Security/CrossTenantRouteSweepTest` (every endpoint that names an organization
  or client, found automatically, so new endpoints are covered without editing it).

## Known limits (honest list)

- The route sweep proves that another tenant's **organization or client id in
  the address** is refused. Ids of other records (an invoice, a role) are
  checked by each controller's scoped lookup and by `BelongsToOrganization`;
  those are covered by each feature's own isolation tests.
- The general API limits (120 per person, 600 per organization, 300 per
  address, per minute) are placeholders until real traffic is measured.
- Alerts (email, Slack, SMS) on security events arrive in Phase 9; until then
  the security log is for the log collector.
- Encrypted, write-once off-site storage needs an S3-compatible bucket with
  object lock; the default `backups` disk is local and `security:check` says so.
- PostgreSQL has never run locally; it runs only in CI.
