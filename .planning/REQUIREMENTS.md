# Requirements: MSC Research Repository

**Defined:** 2026-08-18
**Core Value:** ข้อมูลผลงานตีพิมพ์ของคณะที่ซิงค์มาจาก Scopus ต้องถูกต้อง ครบถ้วน และเข้าถึงได้แบบสาธารณะตลอดเวลา แม้ระหว่างที่กำลังซิงค์ข้อมูลอยู่ก็ตาม

## Resolved Decisions (2026-08-19)

Confirmed against the actual production codebase and a live Elsevier API test — see git history for the full discussion:

- **Data source: Scopus only.** The production system (v1, pre-rewrite) also pulled from ORCID, PubMed, and a Google Scholar scrape — deliberately dropped for this rewrite (multi-source matching/dedup logic was a recurring source of bugs). SYNC-01 below already assumed Scopus-only; no change needed there, just confirming the decision explicitly.
- **Quartile: Scimago Excel import, not a live API.** SCImago Journal & Country Rank has no public API — confirmed via web search. The production system's existing manual yearly Excel import (`admin/temp_import_xlsx_quartiles.php`) is the only viable mechanism and should be kept for v1 (worth promoting out of `temp_*.php` into a proper admin page later). *Superseded 2026-08-20 (v2, see Phase 9 below): this was true for SCImago specifically (still has no API), but the plain Scopus Search API's Serial Title endpoint - a different content type entirely, not SciVal - turned out to expose CiteScore Percentile per journal via `view=CITESCORE`, from which Quartile can be derived using Elsevier's own published formula. Faculty leadership decided to drop SCImago/SJR Quartile entirely and use Scopus CiteScore Quartile as the sole source going forward.*
- **SDG mapping: CSV import for v1, not Scopus/SciVal weight-based auto-mapping.** See the SDG Mapping section below for why the original weight-based design doesn't match reality.

## v1 Requirements

### Scopus Sync Engine

- [x] **SYNC-01**: System syncs publication/researcher data from Scopus API into MySQL; on API timeout, retries up to 3 times with exponential backoff (5s/15s/45s), then cancels the whole batch and marks `sync_log` as "failed" with an error message
- [x] **SYNC-02**: On Scopus HTTP 429 (rate limit), system pauses per the response header's retry time (or 60s fallback) and retries, canceling after 3 attempts like the timeout case
- [x] **SYNC-03**: Individual records with incomplete data (e.g. missing DOI, null author) are skipped without failing the whole sync; the number of skipped records is recorded in `sync_log`
- [x] **SYNC-04**: A sync record whose Scopus Author ID duplicates an existing researcher is rejected (not auto-merged) and flagged in `sync_log` for manual review
- [x] **SYNC-05**: Public pages remain accessible and unaffected while a sync is in progress (sync must never lock public reads)

### Dashboard

- [x] **DASH-01**: Public dashboard shows total researcher count, total publication count, and total citation count
- [x] **DASH-02**: Public dashboard shows Scopus quartile distribution (Q1–Q4 + unclassified)
- [x] **DASH-03**: Public dashboard shows top researchers by publication count, citations, and h-index
- [x] **DASH-04**: Public dashboard shows yearly publication statistics
- [x] **DASH-05**: Public dashboard lists recent publications with authors, journal, DOI, quartile, citation count, funding source, and collaborating countries
- [x] **DASH-06**: Every public page shows the "last synced" timestamp

### Publication Search

- [x] **SEARCH-01**: User can search publications by title or author name using partial match
- [x] **SEARCH-02**: User can filter search results by Scopus quartile
- [x] **SEARCH-03**: Search results paginate at 20 per page, sorted by publication year descending by default

### Researcher Directory

- [x] **RESEARCHER-01**: User can view a researcher directory filterable by department and staff type
- [x] **RESEARCHER-02**: User can sort the directory by publication count, citations, or h-index — descending by default, with an ascending toggle
- [x] **RESEARCHER-03**: Researcher directory paginates at 20 per page
- [x] **RESEARCHER-04**: Each directory entry shows Thai and English name, department, Scopus ID, publication count, total citations, and h-index
- [x] **RESEARCHER-05**: Researchers with `is_active = false` are hidden from the directory, but their historical publications still count in faculty-wide statistics

### Reports

- [x] **REPORT-01**: Reports page is filterable by department, publication year, and staff type
- [x] **REPORT-02**: Reports include trend overview, department breakdown, quartile summary, researcher ranking, yearly statistics, and publication sources tabs
- [x] **REPORT-03**: Reports include an international collaboration tab — each country appearing on a publication gets full credit, not averaged
- [x] **REPORT-04**: Reports include a funding sources tab
- [x] **REPORT-05**: Reports include an author roles tab
- [x] **REPORT-06**: Reports include an SDG statistics tab
- [x] **REPORT-07**: A publication with co-authors from multiple departments counts toward every department involved in the "department breakdown" report

### SDG Mapping

> **Revised 2026-08-19** — the original weight-based design below was invalidated by two confirmed facts: (1) Elsevier's SDG classification is a **binary match against fixed Boolean queries per SDG**, not a continuous relevance score — there is no weight to rank or tie-break on; (2) the project's Scopus API key was tested directly against the SciVal Publication Lookup API (`analytics/scival/publication/{id}`, the endpoint that carries SDG data) and returned `403 ENTITLEMENTS_ERROR` — the key has Scopus Search access but no SciVal entitlement. Given the 2026-09-03 deadline, v1 uses the CSV-import mechanism that already exists and works (`admin/sdg_import.php`); automatic SciVal-based mapping is deferred to v2 (see below) pending a separate entitlement request to the university's Elsevier account.

- [x] **SDG-01**: Admin can import a CSV file (matched by DOI, falling back to title) that assigns up to 2 SDGs (primary + secondary) plus a free-text rationale to existing publications
- [x] **SDG-02**: A publication can carry 0, 1, or 2 SDG tags — there is no "highest-weighted" ordering to compute; primary vs. secondary is whatever the imported CSV specifies
- [x] **SDG-03**: Publications with no SDG data imported are tagged "Unclassified" rather than left blank or errored — reports.php's SDG statistics tab had no such bucket at all until 2026-08-19 (see REPORT-06)
- [x] **SDG-04**: SDG assignment is visible on the publication detail view and in report tabs
- [x] **SDG-05**: Re-importing a CSV for a publication overwrites its previous SDG tags (primary/secondary/rationale) with the new values — no version history kept

### Admin & Sync Control

- [x] **ADMIN-01**: Admin authenticates via MEDSCI ACC SSO Redirect (Method 1) only — this system never handles a user's raw username/password
- [x] **ADMIN-02**: An authenticated admin can trigger a Scopus sync
- [x] **ADMIN-03**: Triggering a sync while one is already running is rejected with a clear "sync already running" message — no overlapping syncs
- [x] **ADMIN-04**: Every sync trigger records the triggering user in `sync_log.triggered_by`
- [x] **ADMIN-05**: Unauthenticated requests to the sync-trigger endpoint are rejected (401/redirect to login), never executed

## v2 Requirements

Deferred to future release. Tracked but not in current roadmap.

### Data Export

- **EXPORT-01**: CSV export of the current search results or report table view

### Data Model Hardening

- **RESEARCHER-06**: Support multiple Scopus Author IDs per researcher (`researcher_scopus_ids` side table), for the case of one person accumulating more than one Scopus profile over their career
- **SDG-07**: Replace the 2-fixed-column `sdg_primary`/`sdg_secondary` design with a proper `publication_sdgs` many-to-many table, so a publication that genuinely matches 3+ SDGs isn't capped. Deferred: real data shows this affects a small minority of an already-sparse dataset (10 of 774 publications as of 2026-08-19, all pre-existing manual CSV rows), and SDG mapping in v1 is a manual/curated process anyway. Until this lands, `admin/sdg_import.php` keeps the first 2 distinct SDG codes found across the source CSV's primary/secondary cells and preserves any overflow as a note in `sdg_rationale` rather than discarding it.

### Extended Sync

- **SYNC-06**: Scheduled/automatic background sync (cron-triggered), once the manual-trigger flow is proven stable

### SDG Auto-Mapping & In-House Integration (v2 Milestone)

- [x] **SDG-06a (Dictionary Foundation)**: Integrate `sdg_data.json` from `C:\inetpub\wwwroot\msc_sdgs` (790KB+ curated 17 SDGs dictionary containing keywords, Thai/English names, colors, and relevance weights `rel`) as the core SDG vocabulary. *Done 2026-08-19, then upgraded same day (commit `671499f`, by the server-side session): `get_sdg_dictionary_info()` in `includes/functions.php` reads the live file directly off disk at `C:/inetpub/wwwroot/msc_sdgs/sdg_data.json` (plain same-server file read - no login/API involved), so admins only ever update the dictionary in `msc_sdgs`, never in two places. Falls back to the bundled `data/sdg_data.json` copy if that path isn't reachable. **Cross-directory file permission confirmed working on the live production server 2026-08-19**: `icacls` showed `IIS_IUSRS` has Full Control on the msc_sdgs file, and a live PHP/FastCGI read test under the msc_researchV2 app pool succeeded (796,631 bytes read). `admin/suggest_sdgs.php` surfaces which source was actually used (`dictionary_source: live|bundled|none`) with a colored badge in the admin UI so a stale fallback would be visible, not silent.*
- [x] **SDG-06b (Auto-Suggest on Review)**: Add an interactive "Suggest SDGs" feature in `admin/publications.php` that scores a publication's Title, Abstract, and Keywords against `sdg_data.json` and proposes up to 3 matching SDGs, each with its own score and match rationale. *Done 2026-08-19: `admin/suggest_sdgs.php` + UI in `admin/publications.php`, verified end-to-end (real Scopus abstract/keyword fetch → real scoring → admin applies suggestion → normal save). Admin never has values auto-applied without review. Widened from top-2 to top-3 on 2026-08-20 alongside SDG-06c (see below) - `admin/publications.php` gained a third `sdg_tertiary` select and a matching "ใช้เป็น SDG ลำดับ 3" apply button; every suggestion shows its own score (`s.score.toFixed(2)`) inline.*
- [x] **SDG-06c (Batch Auto-Classify)**: Provide a one-click batch auto-classification tool for admins to automatically tag unclassified publications using weighted keyword scoring. *Done 2026-08-19, on explicit user request to unblock the earlier deferral. New `admin/auto_classify_sdgs.php` (`action=list` / `action=process`) + a JS driver in `admin/sdg_import.php` process one publication per HTTP request in a browser-driven sequential loop, not one big server-side loop - avoids the IIS FastCGI timeout risk flagged in the original deferral (one Scopus Abstract Retrieval API call per publication, no batch endpoint exists) while giving a live progress bar, per-item log, and a Stop button. Each publication commits independently, so the whole batch is resumable if interrupted. Writes `sdg_primary`/`sdg_secondary`/`sdg_tertiary`/`sdg_rationale` unattended only when a match score clears `MIN_AUTO_APPLY_SCORE` (a deliberate safety threshold, not part of msc_sdgs's own algorithm, added to protect data accuracy from low-confidence keyword coincidences); below it, the publication is left Unclassified for manual review via SDG-06b instead. Never overwrites an existing SDG tag.
  **Threshold history**: 0.4 (initial) → **1.0** (2026-08-19, server-side review: "≥1.0 or full-phrase match", since this data is published faculty-wide) → **strictly >0.5** (2026-08-20, per faculty leadership decision relayed by the user: "1.0 คือคะแนนเต็ม") → **≥0.5** (2026-08-20, same day: the parallel server-side session independently changed the comparison from strict `>` back to `>=`; the user confirmed keeping the server's `>=` version when this was surfaced as a discrepancy during a later git sync, rather than reverting to the earlier strict-`>` decision). Current, confirmed behavior: a score of exactly 0.5 auto-applies.
  **Up to 3 SDGs per publication (2026-08-20)**: previously only the top 2 ranks (primary/secondary) could be written; added a `sdg_tertiary` column (`database/add_sdg_tertiary_column.php`) and widened `action=process` to consider the top 3 scored SDGs, each gated individually by the same threshold - a lower-ranked SDG is only written if its own score clears the bar, not just because a higher-ranked one did. The rationale text now states each rank's own score, e.g. `SDG 2 (คะแนน 2.5): ...`. All public/admin display surfaces (`index.php`, `publications_search.php`, `profile.php`, `reports.php`, `admin/publications.php`, `admin/import.php`) and the SDG filter query in `publications_search.php` were updated to read/write/filter `sdg_tertiary` alongside primary/secondary. The CSV import path (`admin/sdg_import.php`'s `process_sdg_csv()`) also widened its overflow-code handling from 2 to 3 slots.
  Re-verified end-to-end against the local Docker stack + real publications after both changes: publication #49 (scores 2.5/1.05/0.66, all >0.5) now correctly gets all 3 SDGs written with per-rank scores in the rationale; #53 (only top score 0.66 clears the bar) gets only `sdg_primary` written; #51 (score 0.46, not > 0.5) correctly stays `low_confidence`. `admin/suggest_sdgs.php?id=195` confirmed returning 3 ranked suggestions with scores.*
- [x] **SDG-06d (Cross-System Exploration)**: Add deep-dive links from `reports.php` (SDG statistics tab) to the `/msc_sdgs` portal for exploring faculty-wide keyword trend visualizations. *Done 2026-08-21: user confirmed the public URL (`https://www.medsci.up.ac.th/msc_sdgs/`), added as a link button under the SDG stats header in `reports.php`.*

### NIH iCite RCR Integration (v2 Milestone, Phase 7)

> **Done 2026-08-20**, implemented directly after the two open decisions (RCR-05, RCR-06) were confirmed with the user via a parallel subagent, then the user said "ทำ phase 7 ได้เลยครับ" (go ahead with Phase 7). One correction surfaced during implementation, not anticipated in the original spec: see RCR-01's note below - the "NCBI ID Converter" named in the original spec turned out to be the wrong tool.

- [x] **RCR-01 (DOI → PMID Resolution)**: System resolves a publication's DOI to its PubMed ID (PMID), storing the resolved PMID on the publication so repeat lookups aren't needed. A publication with no DOI, or whose DOI doesn't resolve to a PMID (common - PubMed only indexes biomedical/health literature, so non-MEDLINE-indexed journals genuinely have no PMID), is left with a null PMID rather than treated as an error. ***Implementation correction:*** *the spec named the PMC ID Converter (`pmc/utils/idconv`) as the resolution tool - this turned out to be wrong. That endpoint only resolves DOIs deposited in PMC (PubMed Central's full-text archive), and returned "Identifier not found in PMC" for a real, definitely-PubMed-indexed test DOI (`10.1002/biof.1637`). The correct tool is NCBI's **ESearch** E-utility searched directly against the `pubmed` database (`https://eutils.ncbi.nlm.nih.gov/entrez/eutils/esearch.fcgi?db=pubmed&term={doi}[doi]&retmode=json`), which covers every PubMed-indexed article (PMC deposit or not) - the same test DOI resolved correctly to PMID 32449276 via this endpoint. `fetch_pmid_from_doi()` in `includes/functions.php` uses ESearch.*
- [x] **RCR-02 (iCite Fetch)**: For publications with a resolved PMID, system fetches the Relative Citation Ratio (`relative_citation_ratio`) and NIH percentile (`nih_percentile`) from the NIH iCite API (`https://icite.od.nih.gov/api/pubs?pmids=...`, free, no API key required) and stores them on the publication. *Done: `fetch_icite_rcr()`/`_with_retry()` in `includes/functions.php`. Verified against the corrected PMID above: returned RCR 1.348, percentile 60.7, matching iCite's own API response exactly.*
- [x] **RCR-03 (No Live Fetch On Page Render)**: PMID resolution and RCR fetching only ever happen via an explicit admin-triggered action, never inline during a public page render. *Done: all fetching lives in `admin/fetch_rcr.php`, auth-gated; every public display surface only reads already-stored `rcr`/`nih_percentile` columns.*
- [x] **RCR-04 (Graceful Absence)**: A publication with no resolvable PMID, or a PMID iCite has no record for, displays "RCR not available" (or is simply omitted where a per-publication badge doesn't apply) rather than blank or an error, and is skipped without failing any batch operation. *Done: verified live - a no-DOI publication returns `status: skipped`; a real non-biomedical-journal DOI correctly returns `status: no_match` ("ไม่พบ PMID สำหรับ DOI นี้") rather than erroring; `render_rcr_badge()` returns an empty string (no badge at all) when `rcr` is null, and `reports.php`'s aggregate excludes publications with no RCR from the average rather than counting them as 0.*
- [x] **RCR-05 (Display Location)**: All three candidate locations, not just one - the Reports page (aggregate/summary), the researcher profile page (per-publication), and publication list badges (`index.php`/`publications_search.php`). *Done: `render_rcr_badge()` shared helper in `includes/functions.php`, called from all three display surfaces plus `admin/publications.php`'s edit form; `reports.php`'s Overview tab shows average RCR, coverage count, and a top-10-by-RCR table.*
- [x] **RCR-06 (Fetch Trigger)**: Both candidate mechanisms. The primary/main mechanism is a batch tool mirroring SDG-06c's Auto-Classify (one-click, processes all publications, with a manual "Refresh" re-run). Secondary/supplementary is an on-demand per-publication fetch mirroring SDG-06b's Suggest SDGs. *Done: `admin/fetch_rcr.php` (`action=list`/`action=process`) + `admin/rcr_import.php` (batch UI, mirrors `admin/topics_import.php`'s shape) for the primary mechanism; `admin/publications.php`'s edit form gained a "ดึงค่า RCR (NIH iCite)" button calling the exact same `action=process` endpoint for one publication, so the on-demand path shares its logic with the batch path rather than duplicating it.*
- [x] **RCR-07 (Refresh Support)**: Admin can re-run RCR/percentile fetching for already-resolved publications - via the batch tool's "Refresh ทั้งหมด" action or via the on-demand button - to pick up iCite's periodic recomputation. No "never overwrite" guard, since RCR/PMID are machine-computed only. *Done: verified live - reprocessing an already-resolved publication correctly re-fetched and overwrote its RCR/percentile without error (PMID itself is only re-resolved if not already stored, since a DOI's PMID mapping doesn't change the way RCR does - saves a redundant ESearch call on every refresh).*

### Topic Prominence & Trends (v2 Milestone, Phase 8)

> **Done 2026-08-20**, implemented directly after the spec was written (user: "ทำ phase 8 ก่อนได้เลย" - go ahead with Phase 8 first). TOPIC-08's display location was decided by proceeding with the leading candidate from the spec (a new `reports.php` tab) since the user didn't object when approving implementation. Verified end-to-end against the local Docker stack + real OpenAlex API (no key required for the calls made; `OPENALEX_API_KEY` wired up as optional/config-driven for the user's provided key regardless).

- [x] **TOPIC-01 (OpenAlex Work Lookup)**: System resolves a publication's OpenAlex work record via its DOI (`https://api.openalex.org/works/doi:{doi}`, free, no API key required - and unlike Phase 7's NIH iCite, DOI-native, no PMID or other ID-conversion step needed) and extracts its topic classification: `primary_topic` plus the full `topics` list, each carrying the OpenAlex hierarchy (domain → field → subfield → topic) and a relevance score. *Done: `fetch_openalex_work_topics()` + `_with_retry()` wrapper in `includes/functions.php`, same SYNC-01/02-style retry contract as the Scopus/abstract fetchers (3x, 5s/15s/45s backoff; 404 = graceful absence, not an error). Verified against a real DOI (`10.1002/biof.1637`) - returned 3 topics with correct primary-topic flagging.*
- [x] **TOPIC-02 (Storage - Normalized, Not Fixed Columns)**: Topics are stored in a new `publication_topics` side table (`publication_id`, `topic_id`, `display_name`, `subfield`, `field`, `domain`, `score`, `is_primary`), not as fixed `topic_primary`/`topic_secondary`-style columns. Reasoning: unlike `sdg_primary`/`sdg_secondary`/`sdg_tertiary`, OpenAlex topics are never admin-curated (no equivalent of "ใช้เป็น SDG หลัก/รอง" review UI is planned) - they're purely computed data feeding aggregate reports, so there's no fixed-slot UX to design around, and a proper one-to-many table avoids repeating the 2→3-column migration this project already hit twice under SDG-06c. *Done: `database/add_publication_topics.php` migration (also adds `publications.openalex_id`/`openalex_checked_at` to track resolution attempts). Verified: re-processing the same publication replaces its 3 rows rather than duplicating them (DELETE-then-INSERT per publication, no "never overwrite" guard needed since nothing lets a human hand-curate a topic).*
- [x] **TOPIC-03 (No Live Fetch On Page Render)**: Same rule as RCR-03 - OpenAlex lookups only happen via an explicit admin-triggered action, never inline during a public page render. *Done: all fetching lives in `admin/classify_topics.php`, auth-gated; `reports.php` only ever reads already-stored `publication_topics` rows.*
- [x] **TOPIC-04 (Graceful Absence)**: A publication with no DOI, or whose DOI isn't found in OpenAlex, is simply excluded from topic aggregates/trend charts rather than erroring or blocking a batch run. *Done: verified live - a no-DOI publication returns `status: skipped` (and is still marked `openalex_checked_at` so it isn't re-selected every batch run); a 404 from OpenAlex returns `status: no_match` without throwing; `reports.php` shows an explicit "N ผลงานไม่มี Topic" note rather than a fake zero-credit bucket.*
- [x] **TOPIC-05 (Prominence Aggregate)**: A report view shows the faculty's publication counts grouped by OpenAlex field, ranked descending - answering "what is this faculty prominent in." *Done: new "Topic Prominence & Trends" tab in `reports.php` - horizontal bar chart (top 10 fields) + a full ranked table with percentages, built from primary-topic-only aggregation over the currently filtered publication set (respects the existing dept/year/type filters).*
- [x] **TOPIC-06 (Trend Over Time)**: The same aggregate broken out by `publish_year`, so growth/decline per field is visible over time. *Done: multi-line Chart.js chart, one line per top-5 field by total count, x-axis = publish year - reuses the same filtered-publication-set data the prominence chart uses.*
- [x] **TOPIC-07 (Fetch Trigger - Batch)**: Topic resolution runs as a batch tool mirroring SDG-06c's Auto-Classify pattern (`admin/classify_topics.php` + JS driver in `admin/topics_import.php`), plus a separate "Refresh ทั้งหมด" button that re-targets every publication with a DOI regardless of prior `openalex_checked_at`. *Done. Note on the spec's original cost/batching question: OpenAlex's pricing model (confirmed by the user, quoting OpenAlex's own docs) treats a single-entity lookup like `/works/doi:{doi}` as a free "view," not a billed search/filter call - so the one-request-per-publication shape (kept for IIS-timeout-avoidance reasons, same as SDG-06c) costs nothing regardless of volume; the `filter=doi:a|b|c` batch-query alternative floated in the original spec turned out to be unnecessary.*
- [x] **TOPIC-08 (Display Location)**: New "Topic Prominence & Trends" tab in `reports.php`, next to the existing SDG statistics tab (REPORT-06) - the leading candidate named in the spec, implemented as-is once the user approved starting Phase 8.

### Auto-Quartile from Scopus CiteScore (v2 Milestone, Phase 9)

> **Done 2026-08-20.** The user shared a real Scopus Source Title List export (`ext_list_Jul_2026.xlsx`, 48,888 journals) asking whether it could drive Quartile - inspection showed it carries ISSN/title/ASJC classification only, no CiteScore/SJR/Quartile field at all, so it can't. That led to testing whether the project's existing `SCOPUS_API_KEY` could fetch Quartile directly instead of requiring any file. It can - see QUARTILE-01. Faculty leadership then confirmed (2026-08-20): drop SCImago/SJR Quartile entirely, Scopus CiteScore Quartile is now the sole source.

- [x] **QUARTILE-01 (CiteScore Percentile Fetch)**: System fetches a journal's CiteScore and per-subject percentile from the Scopus Serial Title API (`https://api.elsevier.com/content/serial/title/issn/{issn}?view=CITESCORE`) using the existing `SCOPUS_API_KEY` - no new credential needed. *Verified live against real ISSNs before committing to this approach (the project's established "test before committing" pattern, same as the SciVal 403 discovery in Phase 4): the plain STANDARD/ENHANCED views return only raw SJR/CiteScore numbers, no percentile; `view=CITESCORE` was the one that actually carries `citeScoreSubjectRank` (`rank`, `percentile`) per ASJC subject, confirmed against The Lancet (percentile 99) and a real low-impact Thai journal (percentile 2-4) already in this project's own data.*
- [x] **QUARTILE-02 (Quartile Derivation)**: Quartile is computed from CiteScore Percentile using Elsevier's own published formula - Q1 ≥75th percentile, Q2 ≥50th, Q3 ≥25th, Q4 below - not an invented approximation. Confirmed via web search against Elsevier/Scopus's own definition of CiteScore Quartile before implementing, since this exact threshold logic needed to be provably Scopus's own definition, not a guess, to be credible as a Scopus-quartile replacement for SCImago/SJR quartile.
- [x] **QUARTILE-03 (Multi-Subject Handling)**: A journal classified under multiple ASJC subject areas can have a different percentile per subject (confirmed live - a real journal in this dataset scored percentile 2, 3, and 4 across its three subjects). The highest percentile across all of a journal's subjects is used, since `journal_quartiles` stores one quartile per journal (matching how the existing SCImago/CSV import already works - no per-subject storage exists to change).
- [x] **QUARTILE-04 (Fetch Trigger - Per-Journal Batch)**: Quartile is fetched once per unique journal ISSN in use (not once per publication) via a batch tool mirroring the Auto-Classify/Topic/RCR pattern (`admin/fetch_quartiles.php` + UI added directly into the existing `admin/scopus_quartiles.php` page, alongside its existing CSV-import feature rather than a new page) - far cheaper than a per-publication loop, since many publications share the same journal. A separate "Refresh ทั้งหมด" action re-targets every ISSN in use to pick up next year's CiteScore update.
- [x] **QUARTILE-05 (Always Overwrite)**: Fetching a journal's quartile always overwrites both the `journal_quartiles` master row and every publication's `quartile` field for that ISSN - including replacing a prior SCImago-sourced value - matching leadership's decision to drop SCImago entirely, and matching the existing CSV import's own already-established "always update, even if it clears old SCImago quartiles" behavior for the identical reason.
- [x] **QUARTILE-06 (Graceful Absence)**: An ISSN not found in Scopus at all, or found but with no CiteScore computed yet (e.g. a brand-new journal), is skipped without failing the batch - the same graceful-absence pattern used throughout this project's other external-API integrations (SDG-06b, RCR-04, TOPIC-04).

### Zero-Shot LLM AI Layer via UP AI Connect (v2 Milestone, Phase 10, pre-presentation)

> **Spec revised 2026-08-21 - live-verified, ready to implement.** Drafted in response to the external evaluator's report (65/100, 2026-08-20), which ranked "the system shows no real AI, only a 6,096-word keyword dictionary" as its #1 risk, affecting 40% of the score (criteria 2 + 3 combined). Original design used embedding vectors + cosine similarity, priced at a negligible ~$0.005 one-time hosted-API cost - but paused anyway pending leadership sign-off, since it was still a new paid third-party vendor decision. The user then supplied real access to **UP AI Connect**, a university-provided AI gateway (daily token quota per provider: Gemini/OpenAI 200,000, Claude/Deepseek 150,000, Perplexity 100,000) - its Models list has chat/completion models only (`claude-sonnet-5`, `gpt-5.4-mini`, `gemini-3.1-flash-lite`, `deepseek-v4-flash`, etc.), no embedding model at all, so the requirements below were redesigned around zero-shot LLM classification instead. **Live-tested 2026-08-21** against the real endpoint (`gpt-5.4-mini`) with a real-shaped abstract: returned a correct, sensible SDG classification (primary SDG 3 at 92% confidence, secondary SDG 2 at 55%) with a Thai rationale and usable semantic tags, in valid JSON, at **270 tokens/call** - cheaper than the original 1,000-1,300/call estimate, so the full 774-publication batch (~209,000 tokens) fits inside a single provider's daily quota almost entirely on its own.

- [x] **SEM-01 (Provider & Model - verified live 2026-08-21)**: Use UP AI Connect's OpenAI-compatible `/chat/completions` endpoint (`config/secrets.local.php`: `UP_AI_CONNECT_BASE_URL`, `UP_AI_CONNECT_API_KEY`) - confirmed working, Bearer auth, returns `model_quota` (daily quota/usage/remaining) in every response for cheap quota tracking. `gpt-5.4-mini` verified as the classification model; a cheaper/faster tier can be swapped in later if quota pressure appears.
- [x] **SEM-02 (Combined Classification + Tagging Batch - implemented 2026-08-21)**: An admin-triggered batch tool (`admin/classify_sdgs_llm.php`, mirrors the Phase 6-9 shape: list-unclassified / process-one / graceful skip-on-error) sends each publication's title+abstract to the model in one call, requesting JSON with `sdg_primary`/`confidence_primary`/`sdg_secondary`/`confidence_secondary`/`rationale`/`semantic_tags` - one call produces both the SDG classification and the expert-finder's semantic tags, never computed live on a public page render. New columns via `database/add_llm_classification_columns.php` (`llm_sdg_primary`, `llm_confidence_primary`, `llm_sdg_secondary`, `llm_confidence_secondary`, `llm_rationale`, `llm_semantic_tags`, `llm_model`, `llm_checked_at`, `llm_reviewed_by`/`llm_reviewed_at` for SEM-08). `fetch_llm_sdg_classification()`/`_with_retry()` added to `includes/functions.php`, alongside a new `http_post_json()` helper (this project's HTTP helpers were GET-only until now). Verified end-to-end against the local Docker stack and the real UP AI Connect endpoint: publication #28 ("Dietary restriction, vegetarian diet, and aging intervention") → SDG 3 (95%) primary, SDG 2 (62%) secondary, sensible Thai rationale and semantic tags, all written to the DB; missing-CSRF request → 403; unauthenticated request → 401; re-querying `action=list` after processing correctly excluded #28. Test write reverted after verification, matching this project's established practice.
- [ ] **SEM-03 (Confidence Score & Rationale Display)**: The LLM's confidence score and rationale is shown next to each SDG badge on `reports.php` and `publications_search.php`, visually distinguished from the existing Phase 6 keyword-dictionary classification.
- [ ] **SEM-04 (Runs Alongside, Not Replacing)**: The existing Phase 6 keyword-dictionary classifier keeps running unchanged - the LLM classifier's output is shown alongside it, not silently swapped in, so SEM-07's comparison is possible and a live classification isn't riding on an unvalidated model alone days before presenting.
- [ ] **SEM-05 (LLM-Ranked Expert Finder)**: A search box accepts a free-text question in Thai or English; one single chat-completion call (query + all 774 publications' `semantic_tags` from SEM-02) returns a ranked list of researchers with the specific publications that justified each match - one call per search, never one call per publication per search (which would burn most of a day's quota on a single query).
- [ ] **SEM-06 (Quota-Spread & Demo-Day Safeguard)**: The one-time SEM-02 batch (~209,000 tokens total, verified) is run within a single provider's daily quota or split across providers if convenient; a fixed set of rehearsed demo queries for SEM-05 is pre-cached so the live presentation never depends on an uncached request succeeding on stage.
- [ ] **SEM-07 (Precision/Recall Validation)**: A sample of 150-200 publications, manually labeled by a subject-matter reviewer, produces a Precision/Recall/F1 comparison table between the Phase 6 keyword classifier and the new LLM zero-shot classifier - directly answers the evaluator's Q&A item "how accurate is the SDG classification, measured how."
- [ ] **SEM-08 (Human Review / Audit Trail)**: An admin can view any LLM classification below a confidence threshold and manually confirm or correct it, with who/when recorded - directly answers the evaluator's transparency criterion (4.3) about human oversight of automated decisions.

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
| SYNC-01 | Phase 1 | Done |
| SYNC-02 | Phase 1 | Done |
| SYNC-03 | Phase 1 | Done |
| SYNC-04 | Phase 1 | Done |
| SYNC-05 | Phase 1 | Done |
| DASH-01 | Phase 2 | Done |
| DASH-02 | Phase 2 | Done |
| DASH-03 | Phase 2 | Done |
| DASH-04 | Phase 2 | Done |
| DASH-05 | Phase 2 | Done |
| DASH-06 | Phase 2 | Done |
| SEARCH-01 | Phase 2 | Done |
| SEARCH-02 | Phase 2 | Done |
| SEARCH-03 | Phase 2 | Done |
| RESEARCHER-01 | Phase 3 | Done |
| RESEARCHER-02 | Phase 3 | Done |
| RESEARCHER-03 | Phase 3 | Done |
| RESEARCHER-04 | Phase 3 | Done |
| RESEARCHER-05 | Phase 3 | Done |
| REPORT-01 | Phase 3 | Done |
| REPORT-02 | Phase 3 | Done |
| REPORT-03 | Phase 4 | Done |
| REPORT-04 | Phase 4 | Done |
| REPORT-05 | Phase 4 | Done |
| REPORT-06 | Phase 4 | Done |
| REPORT-07 | Phase 3 | Done |
| SDG-01 | Phase 4 | Done |
| SDG-02 | Phase 4 | Done |
| SDG-03 | Phase 4 | Done |
| SDG-04 | Phase 4 | Done |
| SDG-05 | Phase 4 | Done |
| ADMIN-01 | Phase 5 | Done |
| ADMIN-02 | Phase 5 | Done |
| ADMIN-03 | Phase 5 | Done |
| ADMIN-04 | Phase 5 | Done |
| ADMIN-05 | Phase 5 | Done |

**Coverage:**
- v1 requirements: 36 total
- Mapped to phases: 36
- Unmapped: 0 ✓
- Done: 36/36 ✓ — all 5 phases complete as of 2026-08-19

---
*Requirements defined: 2026-08-18*
*Last updated: 2026-08-19 — Phase 5 (ADMIN-01..05) marked Done, completing all 5 phases (36/36 v1 requirements). ADMIN-03 was the one real gap: no mutex existed to reject an overlapping sync trigger, despite sync_log tracking a 'running' status. Fixed with a check-then-insert lock (30-minute staleness recovery) in run_synchronization(), plus restructured admin/sync.php's auth-check ordering so the required HTTP 409 response actually takes effect (PHP can't change the status code after HTML output starts, which the routing didn't previously account for). ADMIN-01/02/04/05 were already correct, verified via the Docker stack. Phase 4 (SDG-01..05, REPORT-03..06) marked Done: fixed the one real gap (SDG statistics tab had no "Unclassified" bucket, so 91.5% of publications were invisible in it rather than shown as their own category); REPORT-03/04/05 verified already correct by reading the logic. Decided to keep the 2-column sdg_primary/sdg_secondary design rather than build a many-to-many table (added as deferred v2 item SDG-07); admin/sdg_import.php now extracts every valid SDG code from a multi-value cell like "SDG 3; SDG 12" instead of discarding it, keeps the first 2, and preserves any overflow in sdg_rationale. Phase 3 (RESEARCHER-01..05, REPORT-01/02/07) marked Done: researchers_list.php rewritten for server-side filter/sort/paginate, is_active wired end-to-end (get_all_researchers, admin UI, dashboard rankings), REPORT-01/02/07 verified correct in pre-existing reports.php. Also fixed a critical unauthenticated-mutation bug found in admin/researchers.php during this phase (verified live, only this one admin page was affected). Phase 1 (SYNC-01..05) and Phase 2 (DASH-01..06, SEARCH-01..03) marked Done after implementation and verification against real production data via a local Docker stack. SDG Mapping section rewritten from weight-based to CSV-import-based after confirming Elsevier's SDG classification is binary (not weighted) and the project's API key lacks SciVal entitlement (tested directly, 403 ENTITLEMENTS_ERROR); SciVal auto-mapping added as v2 item SDG-06. v1 requirement count unchanged (36).*
