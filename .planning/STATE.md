---
gsd_state_version: '1.0'
status: v1_complete
progress:
  total_phases: 5
  completed_phases: 5
  total_plans: 0
  completed_plans: 0
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-08-19)

**Core value:** ข้อมูลผลงานตีพิมพ์ของคณะที่ซิงค์มาจาก Scopus ต้องถูกต้อง ครบถ้วน และเข้าถึงได้แบบสาธารณะตลอดเวลา แม้ระหว่างที่กำลังซิงค์ข้อมูลอยู่ก็ตาม
**Current focus:** All 5 phases complete — pre-deployment checklist (see Blockers/Concerns) before the 2026-09-03 presentation

## Current Position

Phase: 5 of 5 (Admin SSO & Sync Control) — DONE, and it was the last one
Plan: 0 of TBD in current phase
Status: All 36/36 v1 requirements complete and verified against real production data (776 publications, 97 researchers, via local Docker stack). Nothing left in the roadmap - what remains is pre-deployment work, not new features.
Last activity: 2026-08-19 — Phase 5 (ADMIN-01..05) implemented, tested, and pushed. The one real gap (no mutex/lock against overlapping syncs) is fixed with 30-minute stale-lock recovery.

Progress: [██████████] 100%

## Performance Metrics

**Velocity:**
- Total plans completed: 0
- Average duration: - min
- Total execution time: 0 hours

**By Phase:**

| Phase | Plans | Total | Avg/Plan |
|-------|-------|-------|----------|
| - | - | - | - |

**Recent Trend:**
- Last 5 plans: -
- Trend: -

*Updated after each plan completion*

## Accumulated Context

### Decisions

Decisions are logged in PROJECT.md Key Decisions table.
Recent decisions affecting current work:

- Roadmap: Sync engine (Phase 1) built first since every other feature reads only from tables the sync writes; three blocking field-shape questions (SDG weight, roster source, quartile shape) are resolved as part of Phase 1's schema/field verification rather than a standalone spike phase.
- Roadmap: Reports split into local-aggregate tabs (Phase 3, no external-field risk) vs. field-dependent tabs (Phase 4, gated on Phase 1's field verification), per research recommendation.
- Roadmap: Admin SSO + sync trigger (Phase 5) deliberately last — sync engine is CLI-testable without it, and this phase concentrates the highest-severity security work.

### Pending Todos

None yet.

### Blockers/Concerns

- ~~Phase 1 must verify against the real production schema/PHP version and a real Scopus API response~~ **Resolved 2026-08-19**: verified against real dumps (researchers.sql, msc_research.sql, not committed — see .gitignore) via a local Docker stack (PHP 8.2 + MySQL 8.0). Quartile is a plain `Q1`-`Q4` string, 84.9% populated. Funding-sponsor is 70% populated (previously feared sparse — it isn't). Countries is 100% populated (always includes at least "Thailand"). REPORT-03/04/05 do not need a v1/v2 scope cut after all.
- ~~SDG data has a real-world quality issue...~~ **Resolved 2026-08-19**: decided to keep the 2-fixed-column design (affects only 10 of 774 publications, and SDG mapping is a manual/curated v1 process anyway) rather than build a many-to-many table now — tracked as deferred v2 item SDG-07. `admin/sdg_import.php` now extracts every SDG code from a multi-value cell like "SDG 3; SDG 12" (previously discarded silently), keeps the first 2, and preserves overflow in `sdg_rationale`.
- `sso_integration_guide.md` contains a Developer Bypass credential and must never be committed — already gitignored since project start.
- Real production data also has a `superadmin` vs `admin` role distinction in `users` that the original codebase captured in the UI (admin/users.php) but never actually enforced anywhere — was a live privilege-escalation bug, fixed 2026-08-19 (4f52f16).
- **Resolved 2026-08-19 — critical**: `admin/researchers.php` required the login-check include (`admin_header.php`) only at the very bottom of the file, after every action handler (delete, save, CSV import, and the new is_active toggle). Verified live against the Docker stack: an anonymous request mutated the database with zero session. Scanned all 10 admin/*.php pages — this was the only one affected. Fixed by moving the auth check to the top of the file, before any action handling.
- Credentials (DB password, Scopus API key, SSO client secret) found hardcoded in `config/db.php` were moved to a gitignored `config/secrets.local.php` (34f7360) but have NOT been rotated with their providers yet — still pending, should happen before any real deployment.
- A second session/machine is doing parallel security hardening directly (web.config, diagnose.php, temp file cleanup) and pushing to the same GitHub repo — merged cleanly each time on 2026-08-19 (no file overlap, though one of their commits briefly regressed SSO SSL verification to default-off before both sides landed the real fix - a CA bundle) — double-check `git log`/`git remote -v` before assuming "pushed" means "on GitHub" when working across sessions.
- **Pre-deployment checklist — none of this is done yet, all of it should happen before 2026-09-03:**
  1. Rotate the DB password, Scopus API key, and SSO client secret that were found hardcoded in `config/db.php` (34f7360) — treat them as already leaked since they sat in plaintext in files copied around before the fix.
  2. Everything in this project has only been run against a **local Docker stack with a copy of real data** — it has never been deployed to or tested against the actual IIS server, the actual production MySQL instance, or a real MEDSCI ACC SSO round-trip. Test the real deploy path (self-hosted GitHub Actions runner per SPEC.md §10.1) before the presentation, not on the day of.
  3. Run `database/add_sync_log_and_is_active.php` against the real production database (adds `sync_log` table + `researchers.is_active` column - schema.sql alone only covers a fresh install).
  4. Confirm with the real MEDSCI ACC endpoint that the CA bundle fix (`e530575`) actually resolves the SSL issue in production, not just against a differently-configured local environment.
  5. Delete `install.php` from the server once the first admin account exists there - it has no way to gate itself before that point.
- Hard external deadline: presentation 2026-09-03 (16 days from 2026-08-18) — keep phase execution tight to this timeline.

## Deferred Items

Items acknowledged and carried forward from previous milestone close:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| v2 requirement | EXPORT-01 (CSV export) | Deferred | Requirements definition, 2026-08-18 |
| v2 requirement | RESEARCHER-06 (multi Scopus Author ID per researcher) | Deferred | Requirements definition, 2026-08-18 |
| v2 requirement | SYNC-06 (scheduled/automatic sync) | Deferred | Requirements definition, 2026-08-18 |

## Session Continuity

Last session: 2026-08-19
Stopped at: **All 5 phases complete (36/36 v1 requirements)**, tested against real production data via local Docker stack, pushed to master. Phase 5 closed out ADMIN-03 (sync mutex/lock - a genuine gap, nothing prevented two overlapping syncs before this) and confirmed ADMIN-01/02/04/05 were already correct. Merged with parallel server-side security commits along the way, including catching and fixing a real security regression (SSO_SSL_VERIFY defaulting to false) introduced by that parallel session - properly fixed on both sides with a real CA bundle (e530575).
Next: nothing left in the roadmap. What remains is the pre-deployment checklist in Blockers/Concerns above (credential rotation, real IIS/production deploy test, running the sync_log/is_active migration on the real DB, confirming the CA bundle fix against the real MEDSCI ACC endpoint, deleting install.php once bootstrapped) - not new feature work. If asked to "continue," check with the user whether they mean deployment prep or something else, rather than assuming there's another phase to build.
Resume file: None
