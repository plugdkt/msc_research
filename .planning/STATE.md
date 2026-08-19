---
gsd_state_version: '1.0'
status: in_progress
progress:
  total_phases: 5
  completed_phases: 2
  total_plans: 0
  completed_plans: 0
  percent: 40
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-08-19)

**Core value:** ข้อมูลผลงานตีพิมพ์ของคณะที่ซิงค์มาจาก Scopus ต้องถูกต้อง ครบถ้วน และเข้าถึงได้แบบสาธารณะตลอดเวลา แม้ระหว่างที่กำลังซิงค์ข้อมูลอยู่ก็ตาม
**Current focus:** Phase 3 — Researcher Directory & Local-Aggregate Reports

## Current Position

Phase: 3 of 5 (Researcher Directory & Local-Aggregate Reports)
Plan: 0 of TBD in current phase
Status: Phase 1 and Phase 2 complete and verified against real production data (776 publications, 97 researchers, via local Docker stack)
Last activity: 2026-08-19 — Phase 1 (SYNC-01..05) and Phase 2 (DASH-01..06, SEARCH-01..03) implemented, tested, and pushed

Progress: [████░░░░░░] 40%

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
- SDG data has a real-world quality issue: some existing values are non-standard (e.g. literal "ไม่ทราบ" = "unknown", or multiple codes crammed into one column like "SDG 3; SDG 12"). Normalization fixed for the single-value case (4f52f16); the multi-value case surfaces a genuine schema gap — some publications match 3+ SDGs and the current 2-fixed-column design can't represent that. Needs a decision before/during Phase 4: accept the 2-max cap as a product simplification, or add a proper `publication_sdgs` many-to-many table.
- `sso_integration_guide.md` contains a Developer Bypass credential and must never be committed — already gitignored since project start.
- Real production data also has a `superadmin` vs `admin` role distinction in `users` that the original codebase captured in the UI (admin/users.php) but never actually enforced anywhere — was a live privilege-escalation bug, fixed 2026-08-19 (4f52f16).
- Credentials (DB password, Scopus API key, SSO client secret) found hardcoded in `config/db.php` were moved to a gitignored `config/secrets.local.php` (34f7360) but have NOT been rotated with their providers yet — still pending, should happen before any real deployment.
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
Stopped at: Phase 1 (e3fc4d4, 4f52f16) and Phase 2 (6024c18) implemented, tested against real production data via local Docker stack, and pushed to master. Next: Phase 3 (Researcher Directory & Local-Aggregate Reports) — note researchers_list.php and reports.php already exist from the pre-existing codebase, same pattern as Phase 2 (verify against requirements, fix real gaps, rather than rewrite from scratch).
Resume file: None
