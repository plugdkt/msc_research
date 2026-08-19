---
gsd_state_version: '1.0'
status: in_progress
progress:
  total_phases: 5
  completed_phases: 3
  total_plans: 0
  completed_plans: 0
  percent: 60
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-08-19)

**Core value:** ข้อมูลผลงานตีพิมพ์ของคณะที่ซิงค์มาจาก Scopus ต้องถูกต้อง ครบถ้วน และเข้าถึงได้แบบสาธารณะตลอดเวลา แม้ระหว่างที่กำลังซิงค์ข้อมูลอยู่ก็ตาม
**Current focus:** Phase 4 — SDG Mapping & Extended Reports

## Current Position

Phase: 4 of 5 (SDG Mapping & Extended Reports)
Plan: 0 of TBD in current phase
Status: Phases 1-3 complete and verified against real production data (776 publications, 97 researchers, via local Docker stack)
Last activity: 2026-08-19 — Phase 3 (RESEARCHER-01..05, REPORT-01/02/07) implemented, tested, and pushed; merged with parallel server-side security work (web.config, diagnose.php, temp file cleanup, no conflicts)

Progress: [██████░░░░] 60%

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
- A second session/machine is doing parallel security hardening directly (web.config, diagnose.php, temp file cleanup) and pushing to the same GitHub repo — merged cleanly once on 2026-08-19 (no file overlap), but double-check `git log`/`git remote -v` before assuming "pushed" means "on GitHub" when working across sessions.
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
Stopped at: Phases 1-3 implemented, tested against real production data via local Docker stack, and pushed to master (merged with parallel server-side security commits along the way). SDG schema decision made (keep 2-column design, SDG-07 deferred to v2) and admin/sdg_import.php's multi-value-cell handling fixed accordingly. Next: Phase 4 proper (SDG statistics tab plus REPORT-03/04/05 - international collaboration, funding sources, author roles) - no longer blocked on a schema decision.
Resume file: None
