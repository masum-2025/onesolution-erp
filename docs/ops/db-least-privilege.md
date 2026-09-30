# Database users with least privilege

The app never connects as `root` / `postgres`. Two users:

| User | Used by | May |
|------|---------|-----|
| `erp_app` | the web app, queue workers, scheduler (`DB_USERNAME`) | read and write rows; no schema changes, no other databases |
| `erp_migrate` | deploys only (`php artisan migrate`, run with its own env) | change the schema of this database |

A third, read-only user `erp_backup` runs `backup:run` if backups run on a
separate host. `security:check` fails in production while `DB_USERNAME` is an
admin user or has no password.

## MySQL 8

```sql
CREATE USER 'erp_app'@'10.0.%' IDENTIFIED BY '<long random password>';
GRANT SELECT, INSERT, UPDATE, DELETE ON onesolution_erp.* TO 'erp_app'@'10.0.%';

CREATE USER 'erp_migrate'@'10.0.%' IDENTIFIED BY '<another password>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
  ON onesolution_erp.* TO 'erp_migrate'@'10.0.%';

-- Backups (mysqldump --single-transaction, with routines and triggers).
CREATE USER 'erp_backup'@'10.0.%' IDENTIFIED BY '<another password>';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON onesolution_erp.* TO 'erp_backup'@'10.0.%';
GRANT PROCESS ON *.* TO 'erp_backup'@'10.0.%';

-- Require TLS from the app servers when the network is shared.
ALTER USER 'erp_app'@'10.0.%' REQUIRE SSL;
```

`10.0.%` stands for the private network of the app servers; never `%`.

## PostgreSQL 16

```sql
CREATE ROLE erp_migrate LOGIN PASSWORD '<password>';
CREATE ROLE erp_app LOGIN PASSWORD '<password>';
CREATE ROLE erp_backup LOGIN PASSWORD '<password>';

ALTER DATABASE onesolution_erp OWNER TO erp_migrate;
REVOKE ALL ON DATABASE onesolution_erp FROM PUBLIC;
GRANT CONNECT ON DATABASE onesolution_erp TO erp_app, erp_backup;
REVOKE CREATE ON SCHEMA public FROM PUBLIC;
GRANT USAGE ON SCHEMA public TO erp_app, erp_backup;

-- Tables and sequences created by migrations later get the same rights.
ALTER DEFAULT PRIVILEGES FOR ROLE erp_migrate IN SCHEMA public
  GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO erp_app;
ALTER DEFAULT PRIVILEGES FOR ROLE erp_migrate IN SCHEMA public
  GRANT USAGE, SELECT ON SEQUENCES TO erp_app;
ALTER DEFAULT PRIVILEGES FOR ROLE erp_migrate IN SCHEMA public
  GRANT SELECT ON TABLES TO erp_backup;
```

In `pg_hba.conf`, allow these roles only from the app servers' addresses with
`scram-sha-256` (and `hostssl` when the network is shared).

## Local development

Laragon's `root` without a password is fine on a developer machine;
`security:check` lists it for information only outside production.
