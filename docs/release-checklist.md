# Release checklist

Phase 11. For the person releasing a build to production. Every box is ticked, or the release
waits. Rollback: [rollback-plan.md](rollback-plan.md).

## Before the release day

- [ ] CI is green on the release commit: `mysql`, `pgsql`, `coverage`, `frontend`,
      `audit and SBOM` (GitHub Actions).
- [ ] No open critical or high finding in `docs/security/findings.json`; every fixed critical
      and high finding was retested by the testers.
- [ ] Every fixed finding has its regression test (the `ReleaseReadinessTest` passes in CI).
- [ ] Release notes written: what changes for clients, partners and operators; new settings
      (`.env`) and new scheduled commands.
- [ ] Rollback point decided: the commit to return to and whether this release has migrations
      that change or drop data (see the rollback plan).

## On the server, before switching

- [ ] A fresh backup: `php artisan backup:run`.
- [ ] A restore drill of that backup passed in the last 35 days, or now:
      `php artisan backup:restore-drill`.
- [ ] `php artisan migrate --force` and `php artisan tenants:migrate`.
- [ ] `php artisan rules:sync`, `php artisan access:sync`, `php artisan packaging:sync`,
      `php artisan legal:sync` (catalogs from the code).
- [ ] `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
- [ ] `php artisan release:check --production`: every row ok (findings, migrations, tenant
      databases, backup, restore drill, server settings).

## Switching

- [ ] Maintenance message on if the release has long migrations (`php artisan down --secret=…`).
- [ ] Restart the queue workers (`php artisan queue:restart`) and the scheduler.
- [ ] `php artisan up`.

## After the release (first hour)

- [ ] `php artisan health:report`: no `fail`.
- [ ] Sign in as a test account on the platform address and on one partner domain.
- [ ] `php artisan security:alerts`: no new high alerts from the release itself.
- [ ] Failed jobs and the error log are quiet.
- [ ] Release recorded: commit, time, who released, and the rollback point.
