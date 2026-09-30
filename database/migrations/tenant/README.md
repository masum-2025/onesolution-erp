# Tenant migrations

Tables in this folder (and in `Modules/*/database/migrations/tenant`) hold
**business data that follows its client** (Phase 10). They are created in the
main database by the normal `php artisan migrate`, and in every dedicated or
regional database by `php artisan tenants:migrate`.

Rules (checked by `tests/Feature/Architecture/TenantDatabaseTablesTest.php`):

- every table has an `organization_id` column (the move tool copies a client's
  rows by it);
- no foreign key to a platform table (organizations, users, ...): those live
  only in the main database. Foreign keys between tenant tables are fine;
- the model uses both `BelongsToOrganization` and `UsesTenantDatabase`.
