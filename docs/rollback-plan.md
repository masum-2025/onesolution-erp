# Rollback plan

Phase 11. What to do when a release goes wrong. Decide within the first hour; the person who
released decides together with one more operator.

## 1. Decide: roll back or fix forward

Roll back when clients cannot sign in or work, data is shown to the wrong tenant, money is
recorded wrongly, or `health:report` keeps failing. A tenant-isolation or money problem always
means: put the platform in maintenance first (`php artisan down`), then decide.

## 2. Code only (the release had no migrations)

1. Deploy the previous release commit (the rollback point in the release record).
2. `php artisan config:cache && php artisan route:cache && php artisan view:cache`.
3. `php artisan queue:restart`; `php artisan up`.
4. `php artisan health:report`.

## 3. The release had additive migrations (new tables or columns)

The previous code ignores new tables and columns, so:

1. Deploy the previous release commit (step 2 above). Leave the new tables in place.
2. Do **not** run `migrate:rollback` on production unless the migration is known to be safe to
   reverse; additive tables can stay until the fix is released.

## 4. The release changed or removed data

1. `php artisan down`.
2. Restore the backup taken right before the release (`backup:run` in the release checklist):
   restore into a new database first, check it (`backup:restore-drill` shows what is inside),
   then point the application at it. Tenant databases (`tenant-<name>.sql.gz.enc`) are restored
   the same way, each into its own database.
3. Deploy the previous release commit; `php artisan up`.
4. Changes made between the backup and the rollback are lost: list them from the audit log
   (`created_at` after the backup) and tell the affected clients.

## 5. A client move went wrong

`tenants:move` switches only after verification, so a failed move leaves the client where it was.
If a completed move must be undone: `php artisan tenants:move <root> --shared` (or the old
database) while the old copy is still retained; never run `tenants:purge-source` until the
client has worked on the new database for the whole retention time.

## 6. Afterwards

- Write down what happened (docs/incident-playbook.md, the post-incident note).
- A finding from the incident goes into `docs/security/findings.json` with a regression test.
