---
paths:
  - 'database/migrations/**'
---

# Migrations

## Skip FK constraints on high-volume/audit-log tables
Decided case-by-case, not a blanket policy: profile_refresh_attempts (one row per job attempt, unbounded growth) has profile_id as a plain foreignUlid() column with no ->constrained()/cascadeOnDelete(). Reasoning: this is the table most likely to need partitioning/archival/separate storage at scale, which a DB-level FK constraint would get in the way of; there's also no profile-deletion code path today, and an audit trail arguably shouldn't cascade-delete with the thing it audited anyway. Ordinary relational tables (like profiles' own relationships, if any get added) should still use real FK constraints by default - this exception is specifically for high-write-volume, append-only log-style tables.

## No down() methods
Migrations only implement up(). A rollback that's already run against real data is rarely safe - it can silently discard data written under the new schema, so "undo the change" and "restore the old shape" stop being the same operation the moment any row has been touched. Fix a bad migration by writing a new, corrective migration (roll forward), not by running a down() path. See the README's "A Few Deliberate Choices" for the full explanation of this.
