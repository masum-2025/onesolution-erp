# Backups and the restore drill

## What runs

| When | Command | What it does |
|------|---------|--------------|
| every night 01:30 UTC | `php artisan backup:run` | dumps the database with its own tool (`mysqldump` / `pg_dump`), archives the private files (not temporary exports), compresses, encrypts and uploads a new set, then removes sets beyond `BACKUP_KEEP` |
| 2nd of every month 04:00 UTC | `php artisan backup:restore-drill` | restores the newest set into the staging database `BACKUP_DRILL_DATABASE`, checks it, and stores a report next to the set |

Both write to the audit log (`backup.created`, `backup.restore_drill_passed` /
`_failed`) and the security log.

## A backup set

```
20261001T013000Z-ab12cd/
  database.sql.gz.enc   the database
  files.tar.gz.enc      private files (brand images, …)
  manifest.json         checksums, row count per table, key version, signature
  drill-*.json          restore drill reports
```

- Encrypted with libsodium secretstream (XChaCha20-Poly1305) in chunks: a
  changed, reordered or cut-off file is refused, never half-restored.
- The manifest is signed with the key, so its checksums and counts cannot be
  edited unnoticed.
- Sets are never overwritten. With an off-site bucket that has **object lock**
  (write once, read many) for at least `BACKUP_KEEP` days, nobody on the app
  server, including an attacker, can change or delete them early.

## Setting up production

1. `php artisan backup:run --generate-key` and put the output in `BACKUP_KEY_V1`.
   **Store a copy offline** (password manager of two people, or printed in a
   safe). Without the key no backup can be read.
2. Add an S3-compatible disk with object lock in `config/filesystems.php` and
   set `BACKUP_DISK` to it.
3. Create an empty staging database and set `BACKUP_DRILL_DATABASE` (optionally
   `BACKUP_DRILL_HOST`, `_USERNAME`, `_PASSWORD` for a separate server). The
   drill wipes it every time; it refuses to run against the app's own database.
4. Make sure `mysqldump`/`mysql` (or `pg_dump`/`psql`, same major version as
   the server) are installed, or set their paths (`BACKUP_MYSQLDUMP`, …).
5. `php artisan security:check` shows `backup_key`, `backup_offsite` and
   `restore_drill` as ok.

## Rotating the key

1. Generate a new key, set `BACKUP_KEY_V2` and `BACKUP_KEY_VERSION=v2`.
2. Keep `BACKUP_KEY_V1` as long as sets written with it exist (`BACKUP_KEEP`
   days), then remove it. Each set records which key wrote it.

## Restoring for real

1. Stop the queue workers and the scheduler; put the app in maintenance mode.
2. Run the drill against the target database first
   (`BACKUP_DRILL_DATABASE=<new database> php artisan backup:restore-drill --set=<id>`):
   it decrypts, checks and loads the set, and reports differences.
3. Point `DB_DATABASE` at the restored database, copy the files archive back
   if needed, run `php artisan migrate --force` (for sets older than the code),
   then `rules:sync`, `access:sync`, and bring the app back.
4. Write down what happened in the incident log (Phase 9 playbook).

## Local development

Laragon's tools are not on PATH; set `BACKUP_MYSQLDUMP` and `BACKUP_MYSQL` (see
`.env.example`). The drill never touches `onesolution_erp`; use a separate
database such as `onesolution_erp_restore_drill`.
