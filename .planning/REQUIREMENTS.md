# Requirements: MSC Research Repository

**Defined:** 2026-08-18
**Core Value:** ข้อมูลผลงานตีพิมพ์ของคณะที่ซิงค์มาจาก Scopus ต้องถูกต้อง ครบถ้วน และเข้าถึงได้แบบสาธารณะตลอดเวลา แม้ระหว่างที่กำลังซิงค์ข้อมูลอยู่ก็ตาม

## v1 Requirements

### Scopus Sync Engine

- [ ] **SYNC-01**: System syncs publication/researcher data from Scopus API into MySQL; on API timeout, retries up to 3 times with exponential backoff (5s/15s/45s), then cancels the whole batch and marks `sync_log` as "failed" with an error message
- [ ] **SYNC-02**: On Scopus HTTP 429 (rate limit), system pauses per the response header's retry time (or 60s fallback) and retries, canceling after 3 attempts like the timeout case
- [ ] **SYNC-03**: Individual records with incomplete data (e.g. missing DOI, null author) are skipped without failing the whole sync; the number of skipped records is recorded in `sync_log`
- [ ] **SYNC-04**: A sync record whose Scopus Author ID duplicates an existing researcher is rejected (not auto-merged) and flagged in `sync_log` for manual review
- [ ] **SYNC-05**: Public pages remain accessible and unaffected while a sync is in progress (sync must never lock public reads)

### Dashboard

- [ ] **DASH-01**: Public dashboard shows total researcher count, total publication count, and total citation count
- [ ] **DASH-02**: Public dashboard shows Scopus quartile distribution (Q1–Q4 + unclassified)
- [ ] **DASH-03**: Public dashboard shows top researchers by publication count, citations, and h-index
- [ ] **DASH-04**: Public dashboard shows yearly publication statistics
- [ ] **DASH-05**: Public dashboard lists recent publications with authors, journal, DOI, quartile, citation count, funding source, and collaborating countries
- [ ] **DASH-06**: Every public page shows the "last synced" timestamp

### Publication Search

- [ ] **SEARCH-01**: User can search publications by title or author name using partial match
- [ ] **SEARCH-02**: User can filter search results by Scopus quartile
- [ ] **SEARCH-03**: Search results paginate at 20 per page, sorted by publication year descending by default

### Researcher Directory

- [ ] **RESEARCHER-01**: User can view a researcher directory filterable by department and staff type
- [ ] **RESEARCHER-02**: User can sort the directory by publication count, citations, or h-index — descending by default, with an ascending toggle
- [ ] **RESEARCHER-03**: Researcher directory paginates at 20 per page
- [ ] **RESEARCHER-04**: Each directory entry shows Thai and English name, department, Scopus ID, publication count, total citations, and h-index
- [ ] **RESEARCHER-05**: Researchers with `is_active = false` are hidden from the directory, but their historical publications still count in faculty-wide statistics

### Reports

- [ ] **REPORT-01**: Reports page is filterable by department, publication year, and staff type
- [ ] **REPORT-02**: Reports include trend overview, department breakdown, quartile summary, researcher ranking, yearly statistics, and publication sources tabs
- [ ] **REPORT-03**: Reports include an international collaboration tab — each country appearing on a publication gets full credit, not averaged
- [ ] **REPORT-04**: Reports include a funding sources tab
- [ ] **REPORT-05**: Reports include an author roles tab
- [ ] **REPORT-06**: Reports include an SDG statistics tab
- [ ] **REPORT-07**: A publication with co-authors from multiple departments counts toward every department involved in the "department breakdown" report

### SDG Mapping

- [ ] **SDG-01**: After each sync, system computes SDG relevance for every publication and tags the top-2 highest-weighted SDGs
- [ ] **SDG-02**: Ties in SDG weight are broken by ascending SDG code (deterministic, no randomness)
- [ ] **SDG-03**: Publications with no relevant SDG data are tagged "Unclassified" rather than left blank or errored
- [ ] **SDG-04**: SDG assignment is visible on the publication detail view and in report tabs
- [ ] **SDG-05**: Re-sync recomputes SDG mapping from scratch and overwrites the prior mapping — no version history kept

### Admin & Sync Control

- [ ] **ADMIN-01**: Admin authenticates via MEDSCI ACC SSO Redirect (Method 1) only — this system never handles a user's raw username/password
- [ ] **ADMIN-02**: An authenticated admin can trigger a Scopus sync
- [ ] **ADMIN-03**: Triggering a sync while one is already running is rejected with a clear "sync already running" message — no overlapping syncs
- [ ] **ADMIN-04**: Every sync trigger records the triggering user in `sync_log.triggered_by`
- [ ] **ADMIN-05**: Unauthenticated requests to the sync-trigger endpoint are rejected (401/redirect to login), never executed

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Data Export

- **EXPORT-01**: CSV export of the current search results or report table view

### Data Model Hardening

- **RESEARCHER-06**: Support multiple Scopus Author IDs per researcher (`researcher_scopus_ids` side table), for the case of one person accumulating more than one Scopus profile over their career

### Extended Sync

- **SYNC-06**: Scheduled/automatic background sync (cron-triggered), once the manual-trigger flow is proven stable

## Out of Scope

Explicitly excluded. Documented to prevent scope creep.

| Feature | Reason |
|---------|--------|
| Manual entry/edit of publications or researcher records | PROJECT.md/SPEC §3 — data must come from Scopus sync only; admin panel is sync-trigger-only, no CRUD on research data |
| Researcher self-service profile claiming/editing | Same data-integrity-drift risk as manual entry; adds an authentication/authorization surface not defined anywhere in SPEC |
| Open-access compliance tracking / deposit workflow | Different product surface (full-text/licensing/embargo logic), unrelated to the Scopus-sync/dashboard scope |
| Grant/funding lifecycle management | Funding *reporting* (aggregating Scopus's fund-sponsor field) is in scope; funding *management* is a different system with different data ownership |
| Live Scopus API calls on page render | Breaks the ≤2s/≤3s performance budgets, burns API quota per page view, and ties public-site uptime to Scopus's uptime |
| PDF report generation/export | Disproportionately expensive relative to the 16-day timeline; CSV (v2) delivers the same core value cheaper |
| Cross-database bibliometric aggregation (Web of Science, Google Scholar) | PROJECT.md/SPEC §3 — single source of truth is Scopus; multi-source reconciliation is a different, unsolved problem |
| Direct API Auth (MEDSCI ACC Method 2 — raw username/password) | Security decision — this system must never touch a user's real password, even though the vendor's method supports it |

## Traceability

Which phases cover which requirements. Populated during roadmap creation.

| Requirement | Phase | Status |
|-------------|-------|--------|
| SYNC-01 | Phase 1 | Pending |
| SYNC-02 | Phase 1 | Pending |
| SYNC-03 | Phase 1 | Pending |
| SYNC-04 | Phase 1 | Pending |
| SYNC-05 | Phase 1 | Pending |
| DASH-01 | Phase 2 | Pending |
| DASH-02 | Phase 2 | Pending |
| DASH-03 | Phase 2 | Pending |
| DASH-04 | Phase 2 | Pending |
| DASH-05 | Phase 2 | Pending |
| DASH-06 | Phase 2 | Pending |
| SEARCH-01 | Phase 2 | Pending |
| SEARCH-02 | Phase 2 | Pending |
| SEARCH-03 | Phase 2 | Pending |
| RESEARCHER-01 | Phase 3 | Pending |
| RESEARCHER-02 | Phase 3 | Pending |
| RESEARCHER-03 | Phase 3 | Pending |
| RESEARCHER-04 | Phase 3 | Pending |
| RESEARCHER-05 | Phase 3 | Pending |
| REPORT-01 | Phase 3 | Pending |
| REPORT-02 | Phase 3 | Pending |
| REPORT-03 | Phase 4 | Pending |
| REPORT-04 | Phase 4 | Pending |
| REPORT-05 | Phase 4 | Pending |
| REPORT-06 | Phase 4 | Pending |
| REPORT-07 | Phase 3 | Pending |
| SDG-01 | Phase 4 | Pending |
| SDG-02 | Phase 4 | Pending |
| SDG-03 | Phase 4 | Pending |
| SDG-04 | Phase 4 | Pending |
| SDG-05 | Phase 4 | Pending |
| ADMIN-01 | Phase 5 | Pending |
| ADMIN-02 | Phase 5 | Pending |
| ADMIN-03 | Phase 5 | Pending |
| ADMIN-04 | Phase 5 | Pending |
| ADMIN-05 | Phase 5 | Pending |

**Coverage:**
- v1 requirements: 36 total
- Mapped to phases: 36
- Unmapped: 0 ✓

---
*Requirements defined: 2026-08-18*
*Last updated: 2026-08-18 after roadmap creation — 36/36 v1 requirements mapped across 5 phases*
