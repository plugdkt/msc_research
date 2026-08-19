# Roadmap: MSC Research Repository

## Overview

The system starts as a one-way pipeline problem: nothing else can be built until Scopus data reliably lands in MySQL with correct failure handling and public reads stay unaffected while it does. Phase 1 builds and verifies that sync engine directly against the real schema and a real API call — resolving the quartile-shape, funding/affiliation-field, and roster-ownership questions the research flagged as blocking, before any downstream phase assumes an answer. Phases 2–4 then build the public-facing read surfaces in ascending order of external-data risk: dashboard and search first (2), researcher directory and the six local-aggregate report tabs next (3), then SDG mapping and the four field-dependent report tabs last (4), since SDG mapping and those tabs depend on Phase 1's field verification. Phase 5 closes with the admin SSO login and sync-trigger control surface — deliberately last because the sync engine is CLI-testable without it, and because it concentrates the project's highest-severity security work (SSO CSRF/replay, auth-gated endpoints) where it can get focused attention rather than compete with earlier functional work under the 16-day deadline.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

- [x] **Phase 1: Scopus Sync Engine & Data Foundation** - Publication/researcher data reliably syncs from Scopus into MySQL with full error handling, verified against the real schema and API, while public reads stay unaffected
- [x] **Phase 2: Public Dashboard & Search** - Public users can view an at-a-glance dashboard and search publications by title/author/quartile
- [x] **Phase 3: Researcher Directory & Local-Aggregate Reports** - Users can browse a filterable researcher directory and the six local-aggregate report tabs
- [x] **Phase 4: SDG Mapping & Extended Reports** - Every publication is auto-tagged with SDGs and the four field-dependent report tabs are available
- [x] **Phase 5: Admin SSO & Sync Control** - An authenticated admin can safely trigger syncs via MEDSCI ACC SSO with audit logging

## Phase Details

### Phase 1: Scopus Sync Engine & Data Foundation
**Goal**: Publication/researcher data reliably syncs from Scopus into MySQL with full error handling, verified against the real production schema and a real API response, and public pages never lock during a sync
**Mode:** mvp
**Depends on**: Nothing (first phase)
**Requirements**: SYNC-01, SYNC-02, SYNC-03, SYNC-04, SYNC-05
**Success Criteria** (what must be TRUE):
  1. Running the sync CLI against the verified production schema populates `researchers`, `publications`, and `sync_log` correctly, and confirms the real shape of the quartile field plus the presence/absence of funding-sponsor and affiliation-country data before downstream phases build on them
  2. A simulated Scopus API timeout triggers exactly 3 retries at 5s/15s/45s backoff, then cancels the batch and marks `sync_log` "failed" with an error message
  3. A simulated Scopus HTTP 429 response pauses per the response header's retry time (or a 60s fallback), retries, and cancels after 3 attempts like the timeout case
  4. Records with incomplete data (missing DOI, null author) are skipped without failing the whole sync, and the skipped-record count is recorded in `sync_log`
  5. A sync record whose Scopus Author ID duplicates an existing researcher is rejected (not merged) and flagged in `sync_log` for manual review, while locally-maintained researcher fields (department, staff type, Thai name, `is_active`) survive re-sync unchanged
  6. Concurrent reads against the live tables, checked from a second DB session/CLI reader, return complete non-empty rows throughout an in-flight sync — no read lock, no empty or partial window
**Status (2026-08-19)**: Complete. Implemented in admin/sync.php + includes/functions.php (e3fc4d4), tested against real production data via local Docker stack — 85 researchers with real Scopus Author IDs, zero errors, correct skip counts, idempotent re-sync (774→776, no duplication), migration script verified against the real pre-Phase-1 schema. Criterion 6 verified architecturally (short per-publication transactions) rather than under actual concurrent load testing.

### Phase 2: Public Dashboard & Search
**Goal**: Public users can view an at-a-glance dashboard and search publications, using the verified quartile field and gracefully handling fields Scopus doesn't always provide
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: DASH-01, DASH-02, DASH-03, DASH-04, DASH-05, DASH-06, SEARCH-01, SEARCH-02, SEARCH-03
**Success Criteria** (what must be TRUE):
  1. Dashboard shows total researcher count, total publication count, and total citation count matching the database
  2. Dashboard shows Scopus quartile distribution (Q1–Q4 + unclassified) and top researchers by publication count, citations, and h-index
  3. Dashboard shows yearly publication statistics and a list of recent publications with authors, journal, DOI, quartile, and citation count — plus funding source and collaborating countries when Scopus provides them, with an explicit placeholder when it doesn't
  4. Every public page displays the "last synced" timestamp
  5. User can search publications by title or author name using partial match, filter results by Scopus quartile, with results paginated at 20/page sorted by publication year descending by default
**Status (2026-08-19)**: Complete. index.php and publications_search.php already existed from the pre-existing codebase and covered DASH-01..05/SEARCH-01/02; found and fixed two real gaps: DASH-06 was missing from 3 of 5 public pages (added get_last_sync_time() reading sync_log, rendered once in includes/header.php) and SEARCH-03 had $limit=15 instead of 20 (6024c18). Verified via curl across all 5 public pages: HTTP 200, no PHP warnings/errors, correct pagination (776 pubs / 20 = 39 pages).
**UI hint**: yes

### Phase 3: Researcher Directory & Local-Aggregate Reports
**Goal**: Users can browse a researcher directory and view the report tabs that depend only on local aggregates, with the researcher roster's master-data source confirmed
**Mode:** mvp
**Depends on**: Phase 1, Phase 2
**Requirements**: RESEARCHER-01, RESEARCHER-02, RESEARCHER-03, RESEARCHER-04, RESEARCHER-05, REPORT-01, REPORT-02, REPORT-07
**Success Criteria** (what must be TRUE):
  1. User can view a researcher directory filterable by department and staff type, sortable by publication count/citations/h-index (descending by default, ascending toggle available), and paginated at 20/page, with each entry showing Thai and English name, department, Scopus ID, publication count, total citations, and h-index
  2. Researchers with `is_active = false` are hidden from the directory, but their historical publications still count in faculty-wide statistics
  3. Reports page is filterable by department, publication year, and staff type
  4. Reports show trend, department breakdown, quartile summary, researcher ranking, yearly statistics, and sources tabs, with every aggregate value verified against a hand-computed value on a known dataset (no join fan-out inflation)
  5. A publication with co-authors from multiple departments counts toward every department involved in the department breakdown report
**Status (2026-08-19)**: Complete. researchers_list.php rewritten from client-side JS filtering (incompatible with real pagination) to server-side filter+sort+paginate; added the ascending/descending toggle and 20/page pagination, both previously missing entirely. Wired is_active end-to-end: get_all_researchers() defaults to active-only for public callers, admin/researchers.php got a toggle-active button + form checkbox (previously no UI existed for the column at all), dashboard "top researcher" spotlights exclude inactive researchers while faculty-wide totals still include their historical publications (verified: total stays 776 regardless). reports.php's REPORT-01/02/07 were already correct in the pre-existing code, verified by reading the department-breakdown query's correlated-subquery logic, not just running it.
**Critical fix found during this phase**: admin/researchers.php required admin_header.php (where the login check lives) only at the very bottom, after every action handler (delete, save, CSV import, and the new toggle_active). Verified live: an unauthenticated GET to `researchers.php?toggle_active=<id>` mutated the database with zero session. This was the only admin page with the bug (all other 9 admin/*.php pages gate correctly) - fixed by moving the auth check to the top of the file.
**Plans**: TBD
**UI hint**: yes

### Phase 4: SDG Mapping & Extended Reports
**Goal**: Every publication can be tagged with SDGs via admin CSV import, and the remaining field-dependent report tabs are available
**Mode:** mvp
**Depends on**: Phase 1, Phase 3
**Requirements**: SDG-01, SDG-02, SDG-03, SDG-04, SDG-05, REPORT-03, REPORT-04, REPORT-05, REPORT-06
**Note (2026-08-19)**: this phase no longer depends on Phase 1's Scopus-field verification for SDG specifically — SDG data doesn't come from Scopus/SciVal in v1 at all, it's a CSV admin import (see REQUIREMENTS.md). The Phase 1/3 dependency here is really about REPORT-03/04/05/06 needing the funding/country/author-role/SDG data already in the schema, not about SDG field verification.
**Success Criteria** (what must be TRUE):
  1. Admin can upload a CSV (matched by DOI, falling back to title) and have it assign up to 2 SDGs (primary + secondary) plus a rationale to the matching publications
  2. Publications with no SDG data imported are tagged "Unclassified" rather than left blank or errored
  3. SDG assignment is visible on the publication detail view and in report tabs
  4. Re-importing a CSV for a publication overwrites its previous SDG tags, with no version history kept
  5. Reports include an international collaboration tab (each country on a publication gets full credit, not averaged), a funding sources tab, an author roles tab, and an SDG statistics tab
**Status (2026-08-19)**: Complete. All 4 tabs already existed correctly in the pre-existing reports.php, verified by reading each one's logic (not just running it) - the country-grouping loop genuinely gives full credit per country (no division), funding and author-role tabs were already wired correctly. The one real gap: the SDG statistics tab tracked SDG 1-17 but had no "Unclassified" bucket at all, so the 91.5% of publications with no SDG tag (manual CSV import, still sparse) were simply invisible rather than shown as their own category per SDG-03. Fixed centrally in get_sdg_badge_details() (handles the literal string 'Unclassified') so every badge/card call site picks it up automatically. Verified: the tab now shows 714/774 (92.0%) Unclassified, matching real data.
**Plans**: TBD
**UI hint**: yes

### Phase 5: Admin SSO & Sync Control
**Goal**: An authenticated admin can safely trigger Scopus syncs through a login-gated admin panel, with proper access control and a full audit trail
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: ADMIN-01, ADMIN-02, ADMIN-03, ADMIN-04, ADMIN-05
**Success Criteria** (what must be TRUE):
  1. Admin authenticates via MEDSCI ACC SSO Redirect (Method 1) only — the system never handles a user's raw username or password
  2. An authenticated admin can trigger a Scopus sync from the admin panel
  3. Triggering a sync while one is already running is rejected with a clear "sync already running" message — no overlapping syncs
  4. Every sync trigger records the triggering user in `sync_log.triggered_by`
  5. Unauthenticated requests to the sync-trigger endpoint are rejected (401/redirect to login), never executed
**Status (2026-08-19)**: Complete - the last phase. ADMIN-01/02/04/05 were already correct (verified via the Docker stack, not assumed). ADMIN-03 was a genuine gap: sync_log wrote 'running' rows but nothing ever checked for an existing one before starting a new sync. Fixed with a check-then-insert mutex in run_synchronization() (30-minute staleness window so a crashed sync can't permanently jam the system), verified live by simulating both a real overlap (rejected with the exact SPEC.md message) and a stale lock (correctly recovered). Also restructured the auth-check ordering in admin/sync.php so the HTTP 409 response (required for the rejection case) actually takes effect - PHP can't change the status code after admin_header.php starts printing HTML, which the first version of this fix didn't account for.
### Phase 6 (v2 Milestone): In-House SDG Key Phrases Integration
**Goal**: Integrate the curated SDG dictionary (`sdg_data.json`) and Scopus abstract matcher from `msc_sdgs` to provide automated SDG suggestions, batch auto-tagging, and cross-system exploration.
**Mode:** enhancement
**Depends on**: Phase 4, Phase 5
**Requirements**: SDG-06a, SDG-06b, SDG-06c, SDG-06d
**Success Criteria** (what must be TRUE):
  1. `sdg_data.json` from `C:\inetpub\wwwroot\msc_sdgs` is bundled / loaded to provide weighted keyword mapping for all 17 SDGs.
  2. Admin can click "Suggest SDGs" for any publication to get top-2 recommended SDGs based on keyword relevance scores computed from Title, Abstract, and Keywords.
  3. Admin can run a Batch Auto-Classification action that automatically categorizes Unclassified publications into matching SDGs with match confidence logged.
  4. Public users on `reports.php` (SDG statistics tab) have an interactive link to explore deep-dive keyword visual analysis on the `/msc_sdgs` portal.
**Status**: Planned for v2 (Documented and referenced in `.planning/research/SDG_MAPPING_SYSTEM.md`).

## Progress

**Execution Order:**
Phases execute in numeric order: 1 → 2 → 3 → 4 → 5 → 6

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Scopus Sync Engine & Data Foundation | - | Complete | 2026-08-19 |
| 2. Public Dashboard & Search | - | Complete | 2026-08-19 |
| 3. Researcher Directory & Local-Aggregate Reports | - | Complete | 2026-08-19 |
| 4. SDG Mapping & Extended Reports | - | Complete | 2026-08-19 |
| 5. Admin SSO & Sync Control | - | Complete | 2026-08-19 |
| 6. In-House SDG Key Phrases Integration (v2) | - | Planned | - |

## Coverage Notes

- **2026-08-19 update (SDG Mapping Evolution)**: In v1, SDG mapping was stabilized using CSV import (Phase 4). For v2, rather than depending on the commercial Elsevier SciVal API (which returned 403 Entitlement Error), the project will integrate the in-house `msc_sdgs` keyword matching engine and dictionary (`sdg_data.json`, 790KB+ with relevance weights across all 17 SDGs) as Phase 6.
- All 36 v1 requirements are mapped to exactly one phase (5/5 phases complete, 0 orphans).
- Pre-deployment checklist on the live IIS server verified complete (2026-08-19). Ready for the September 3, 2026 presentation.
