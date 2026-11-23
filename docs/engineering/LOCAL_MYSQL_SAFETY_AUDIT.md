# Local MySQL Safety Audit (Read-Only)

**Date:** 23 November 2026  
**Scope:** Explain Homebrew MySQL state after dual-database Nexus work. No repair executed.

## Executive answer

| Question                                                | Finding                                                                                                                                                                                                     |
| ------------------------------------------------------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| Did Nexus dual-DB work migrate your Homebrew databases? | **No.**                                                                                                                                                                                                     |
| Did Docker MySQL share your Homebrew datadir?           | **No** — isolated container on port **3307**, disposable DB `truckbill_mysql_test`.                                                                                                                         |
| Was Homebrew MySQL data deleted or re-initialized?      | **No** — datadir still contains your schemas; server aborts before data-dictionary upgrade.                                                                                                                 |
| Why is Homebrew MySQL down?                             | Formula is **MySQL 26.10.0**; datadir was created by **MySQL 9.6.0**. Incompatible lineage — starts then aborts.                                                                                            |
| When did the break start?                               | First `Invalid MySQL server upgrade: 90600 → 261000` at **2026-11-22 15:58 UTC** (after Homebrew linked 26.10 on **2026-11-21**). Dual-DB Nexus work on 23 Nov only _restarted_ the already-broken service. |

## A. Original Homebrew MySQL state

| Item            | Value                                                                                                    |
| --------------- | -------------------------------------------------------------------------------------------------------- |
| Formula         | `mysql 26.10.0_1` (linked at `/opt/homebrew/opt/mysql`)                                                  |
| Datadir         | `/opt/homebrew/var/mysql`                                                                                |
| Upgrade history | Initialized **2026-05-25** as **9.6.0** (`mysql_upgrade_history`)                                        |
| Service         | `brew services`: **stopped** (`sh.brew.mysql`, KeepAlive + RunAtLoad, datadir `/opt/homebrew/var/mysql`) |
| Config          | `/opt/homebrew/etc/my.cnf` — localhost bind only (unchanged pattern; mtime 21 Nov)                       |
| Socket          | Not present (server not running)                                                                         |

### Databases present on disk (sample)

Homebrew datadir still lists application schemas including: `attendance`, `hap_*`, `ipovault`, `karni`, `larutan`, `ownfleet`, `partloa`, `portfolio`, `starbread*`, `tradexledger`, `truckbill_platform`, `vault`, plus system `mysql` / `sys` / `performance_schema`.

**No `nexus` / `nexus_test` directory** under the Homebrew datadir.

## B. Whether original MySQL data was modified

**Evidence that user schemas were not migrated or wiped by Nexus dual-DB work:**

1. Dual-DB migrate/tests used Docker env overrides only:  
   `DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=3307 DB_DATABASE=truckbill_mysql_test …`
2. Homebrew mysqld never reached “ready for connections” under 26.10 — every attempt logs abort after InnoDB init with upgrade lineage error (no successful open of user DBs under 26.10).
3. Schema directory mtimes for major user DBs (e.g. `truckbill_platform` 17 Nov, `ownfleet` 19 Nov) predate the dual-DB session; they were not recreated on 23 Nov.
4. No `mysqld --initialize`, no datadir delete, no `migrate:fresh` against Homebrew was performed in the dual-DB session.

**What the agent _did_ touch (non-destructive to schema contents):**

- `brew services restart mysql` / start attempts → failed startups that append to the error log and touch InnoDB redo/temp files while aborting.
- Started Colima + Docker `mysql:8.4` named `truckbill-mysql-ci` on **3307**, then removed that container after tests; Colima was stopped.

## C. Docker MySQL isolation

| Item                              | Value                                             |
| --------------------------------- | ------------------------------------------------- |
| Image                             | `mysql:8.4`                                       |
| Name                              | `truckbill-mysql-ci` (removed after verification) |
| Host port                         | **3307** → container 3306                         |
| Database                          | `truckbill_mysql_test` (created inside container) |
| Volume mounts to Homebrew datadir | **None** (ephemeral container storage)            |
| Overlap with `.env` port 3306     | **None**                                          |

## D. Application `.env` note (not modified by this audit)

Local `.env` currently points at:

- `DB_CONNECTION=mysql`
- `DB_HOST=127.0.0.1`
- `DB_PORT=3306`
- `DB_DATABASE=nexus`

That target cannot work while Homebrew MySQL cannot start. Pest/CI use **PostgreSQL** via `phpunit.xml` / `.env.testing` (`nexus_test`). This audit **did not** change `.env`.

## E. Safe repair recommendations (approval required)

Do **not** run these without explicit Product Owner approval:

1. **Preferred for preserving 9.6 data:** install and run a **9.6-compatible** MySQL binary against the existing datadir (or restore from backup), _or_ follow Oracle/Homebrew documented multi-step upgrades (e.g. 9.6 → supported intermediate LTS → 26.x) with backups first.
2. **Do not** point MySQL 26.10 at `/opt/homebrew/var/mysql` and force an in-place upgrade without a verified backup.
3. **Do not** `rm -rf` the Homebrew datadir.
4. Nexus MySQL compatibility testing must continue on **disposable** engines (Docker/CI `mysql:8.4` + throwaway DB names), never against `truckbill_platform` / other live local schemas.

## F. Residual risk

KeepAlive launch agent may retry mysqld until the service is stopped — each retry appends abort logs. Leaving the service **stopped** (current state) is safe for data preservation.
