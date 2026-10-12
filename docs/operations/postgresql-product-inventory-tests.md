# PostgreSQL product inventory verification

Run this suite only against a disposable local PostgreSQL database named `shiny_style_pg_test`. The dedicated PHPUnit configuration never reads `DB_URL`, and the test base verifies the configured connection and `current_database()` before Laravel's database test traits can migrate or reset tables. The normal `phpunit.xml` and SQLite test configuration are unchanged.

## Requirements

- The PHP CLI version required by this project, with `pdo_pgsql` enabled.
- A local PostgreSQL server listening on `127.0.0.1:5432`.
- A PostgreSQL role permitted to create and alter objects in `shiny_style_pg_test`.
- The database must contain no data that needs to be preserved. Laravel's `RefreshDatabase` can rebuild its schema.

Check PHP before creating the test database:

```powershell
php -v
php -m | Select-String pdo_pgsql
Test-NetConnection 127.0.0.1 -Port 5432
```

Create the local test role and database from `psql` as a PostgreSQL administrator. Set the role password interactively with `\password`, not in a shell command or this repository:

```sql
CREATE ROLE shiny_style_test LOGIN;
CREATE DATABASE shiny_style_pg_test OWNER shiny_style_test;
```

Then run the isolated inventory suite. The script prompts for the role password as a secure value, sets connection variables only in its own process, and removes the password environment variable after execution:

```powershell
.\tests\run-postgres-inventory.ps1 -Username shiny_style_test
```

The runner invokes PHPUnit directly with `phpunit.pgsql.xml`; it does not use `phpunit.xml`, `.env`, `.env.testing`, a Heroku URL, or the default SQLite database. `Tests\TestCase` verifies the database driver, loopback host, empty database URL, explicit test marker, configured database name, and live PostgreSQL `current_database()` before test traits can run migrations. Do not add a password to the XML or commit a local environment file.

The runner executes each test file in its own PHPUnit process. The schema test creates the current schema on PostgreSQL; the upgrade test deliberately rolls back only the latest inventory migration, seeds representative pre-transfer history, then reapplies that migration. The runner includes:

- PostgreSQL catalog integrity index behavior.
- PostgreSQL server/version and `pg_indexes` checks for default, combination, conversion idempotency, transfer idempotency, and reversal uniqueness indexes.
- A populated pre-transfer schema upgrade, checking historical order option snapshots and relationships, unique indexes, and unknown conversion baselines remaining ineligible.
- Independent PHP worker processes for conversion/order/source-edit races, concurrent idempotent and conflicting conversions, competing return transfers, transfer/correction/receipt reversal races, reversal/reservation races, and duplicate transfer reversals.
- Conversion, return correction, transfer, reversal, snapshots, and historical-baseline feature tests.

The test output reports the actual PostgreSQL server version. Keep the full output as the run record. A successful SQLite run is not a PostgreSQL result.

Workers use separate PHP processes and PostgreSQL connections. Each pair waits at file barriers, then the parent releases the first worker while holding a row lock, releases the second, confirms PostgreSQL reports it waiting on a lock, and finally releases the first. Each wait is bounded; the test terminates remaining workers and removes its temporary barrier directory in `finally` if a worker fails.

The database guard runs in the test process before database test traits can perform migrations or resets, and in every worker before it performs an operation. It is conditional on the `pgsql` connection, so ordinary SQLite tests keep their existing setup.
