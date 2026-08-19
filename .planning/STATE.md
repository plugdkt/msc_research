---
gsd_state_version: '1.0'
status: v1_complete_v2_in_progress
progress:
  total_phases: 6
  completed_phases: 5
  total_plans: 0
  completed_plans: 0
  percent: 100
---

# Project State

## Project Reference

See: .planning/PROJECT.md (updated 2026-08-19)

**Core value:** ข้อมูลผลงานตีพิมพ์ของคณะที่ซิงค์มาจาก Scopus ต้องถูกต้อง ครบถ้วน และเข้าถึงได้แบบสาธารณะตลอดเวลา แม้ระหว่างที่กำลังซิงค์ข้อมูลอยู่ก็ตาม
**Current focus:** v1 (36/36 requirements) is live in production. v2 Phase 6 (In-House SDG Key Phrases Integration) is in progress — SDG-06a/b done and tested, SDG-06c/d deferred (see Blockers/Concerns).

## Current Position

Phase: 6 of 6 (In-House SDG Key Phrases Integration, v2) — in progress
Plan: 0 of TBD in current phase
Status: SDG-06a (dictionary bundling) and SDG-06b (per-publication Suggest SDGs) implemented and verified end-to-end against the local Docker stack, the real Scopus API, and real production publication data. Not yet committed/pushed to git.
Last activity: 2026-08-19 — Added `fetch_scopus_abstract_details()`/`_with_retry()` and `score_publication_sdgs()` to `includes/functions.php`; new `admin/suggest_sdgs.php` AJAX endpoint; new "Suggest SDGs" UI in `admin/publications.php`. Live-tested: real Scopus Abstract Retrieval API call fetched real abstract+keywords for a real publication (DOI `10.34172/jech.2022.3`), scoring correctly surfaced SDG 2 (Zero Hunger, score 4.92) as the top match, admin "ใช้เป็น SDG หลัก" button populated the form, and the existing Save flow persisted it correctly to the database (then reverted the test row back to null afterward).

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
- **Pre-deployment checklist — ALL 5 ITEMS COMPLETED & VERIFIED ON PRODUCTION SERVER (2026-08-19):**
  1. [x] **Rotate Credentials**: Rotated and separated into `config/secrets.local.php` (gitignored).
  2. [x] **Production IIS Deployment**: Tested and running live on the faculty IIS server (`C:\inetpub\wwwroot\msc_researchV2`) with PHP 8.2.14 and production MySQL.
  3. [x] **Database Migrations**: Ran `database/add_sync_log_and_is_active.php` and `database/normalize_invalid_sdg_values.php` on live DB (`sync_log` created, `is_active` added).
  4. [x] **CA Bundle & SSO Verification**: Live tested against MEDSCI ACC (`verify.php`) with `CURLOPT_SSL_VERIFYPEER = true` and local CA bundle (`cacert.pem`) — 100% verified.
  5. [x] **Delete `install.php`**: Removed from production server and git repository (`57aed62`).
- Hard external deadline: presentation 2026-09-03 (16 days from 2026-08-18) — Ready for presentation.

## Deferred Items

Items acknowledged and carried forward from previous milestone close:

| Category | Item | Status | Deferred At |
|----------|------|--------|-------------|
| v2 requirement | EXPORT-01 (CSV export) | Deferred | Requirements definition, 2026-08-18 |
| v2 requirement | RESEARCHER-06 (multi Scopus Author ID per researcher) | Deferred | Requirements definition, 2026-08-18 |
| v2 requirement | SYNC-06 (scheduled/automatic sync) | Deferred | Requirements definition, 2026-08-18 |

## Session Continuity

Last session: 2026-08-19
Stopped at: v1 confirmed live in production. Started v2 Phase 6 (SDG-06a/b implemented and tested against the local Docker stack + real Scopus API + real data - see Current Position above). Not yet committed or pushed - per this project's established pattern, every push needs the user's explicit go-ahead in chat first.
Next: 1) get the user's push confirmation for the Phase 6 SDG-06a/b work (functions.php additions, admin/suggest_sdgs.php, admin/publications.php UI, database/add_keywords_column.php migration, schema.sql, data/sdg_data.json). 2) SDG-06c (true batch classify-all) needs a CLI/cron-triggered backfill script, not an inline admin action - scope that as a follow-up if the user wants it. 3) SDG-06d needs the real public URL for `msc_sdgs` from the user before adding a cross-link. Do NOT modify anything under `msc_sdgs/` itself - its two known vulnerabilities (SSL verify disabled, hardcoded API key) were flagged to the user only; a separate server-side session owns fixing those.
Resume file: None
