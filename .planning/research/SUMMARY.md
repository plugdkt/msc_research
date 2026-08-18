# Project Research Summary

**Project:** MSC Research Repository (คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา — Scopus-synced publication repository & bibliometric dashboard)
**Domain:** PHP + MySQL server-rendered research-publication dashboard/reporting system, syncing from Scopus REST API, deployed on IIS via self-hosted GitHub Actions runner
**Researched:** 2026-08-18
**Confidence:** MEDIUM

## Executive Summary

This is a read-only public bibliometric portal (dashboard, search, researcher directory, multi-tab reports, SDG mapping) backed by a one-way Scopus-to-MySQL sync, plus a minimal admin panel that does nothing but trigger that sync behind SSO. It is not a CRIS (no self-service editing, no manual data entry, single external data source) — experts building this kind of system keep the surface area small: plain PHP script-per-page (matching the legacy site's fixed URL contract) with a Composer-autoloaded shared library, PDO + prepared statements (no ORM at this scale), Bootstrap + Chart.js with zero build step, and a background CLI sync worker decoupled from any HTTP request. The single most load-bearing architectural decision is that "public pages must stay available during sync" is achieved through InnoDB MVCC + upsert-in-place (never delete-then-reinsert) + per-record transactions — not through clever caching.

The recommended approach is: build the sync engine and its failure-handling contract first (two-phase fetch-then-write, GET_LOCK mutex + durable status + stale-lock reaper, staging/upsert not delete-and-reload), because every other feature — dashboard, search, directory, reports, SDG mapping — reads from tables only the sync writes. Ship the six report tabs that are pure local aggregates (trend, yearly stats, department, quartile, ranking, sources) before the four tabs that depend on unverified or historically-sparse Scopus fields (SDG weight, funding source, affiliation-country, author role). SSO and CI/CD deployment can be built last since the sync worker is CLI-testable without them.

The dominant risk is not the PHP/MySQL work itself — it's three unresolved external-data-shape questions that this research could not verify against a live API response: (1) whether Scopus actually returns a numeric per-SDG relevance weight or just labels, (2) where researcher roster master data (Thai name, department, staff type, active flag) will come from since Scopus doesn't carry it, and (3) whether "quartile" is a document field or a journal-year lookup requiring an extra table. All three are flagged as blocking spikes to resolve before their dependent phases are planned in detail, not discovered mid-build. Secondary but still critical risks are architectural/operational: running sync inline in the HTTP request (timeout/partial-write), a check-then-act sync lock race, SQL fan-out silently inflating dashboard totals, and security gaps in the hand-rolled SSO flow (no CSRF/state binding, vendor sample code disabling TLS verification) and the self-hosted CI runner with write access to production.

## Key Findings

### Recommended Stack

Plain PHP (8.4, verify actual server version before writing code) with no framework — script-per-page matching the legacy site's URL contract, shared logic factored into a Composer PSR-4 `src/` library. MySQL 8.0 with InnoDB (never MyISAM) and `utf8mb4` for Thai/English text. PDO with prepared statements and `EMULATE_PREPARES => false`, no ORM — the data scale (~800–10,000 publications, read-heavy) doesn't justify ORM overhead, and prepared statements are a hard SQL-injection requirement.

**Core technologies:**
- PHP 8.4.x (script-per-page, Composer-autoloaded `src/` library) — matches existing fixed-URL convention; avoids framework/IIS-rewrite setup cost in a 16-day window
- MySQL 8.0 / InnoDB / utf8mb4 — already the existing system; InnoDB's MVCC is *why* public reads never block on sync writes
- PDO + prepared statements, no ORM — SQL-injection requirement (SPEC §9) plus join-heavy reporting workload that ORMs handle poorly at this scale
- Guzzle ^7.9 (not the brand-new 8.0.2) — HTTP client for Scopus + SSO calls, with middleware-based retry/backoff matching the spec's 5s/15s/45s contract
- Bootstrap 5.3.8 + Chart.js 4.x via CDN/static vendoring, zero build step — avoids adding a Node.js toolchain to the Windows deploy runner
- Windows Task Scheduler (or detached `proc_open`) running a PHP CLI worker — the only viable way to run a multi-minute, retrying, API-bound sync job outside IIS/FastCGI's request lifetime

### Expected Features

This is scoped as a public bibliometric portal, not a full CRIS (Pure/Symplectic/VIVO) — most CRIS table stakes (self-service editing, OA compliance, grant management) are explicit anti-features here. The "multi-dimension reports" requirement bundles 10 tabs with very different risk profiles and must not be planned as one feature.

**Must have (table stakes):**
- Public dashboard (counts, quartile mix, top researchers, yearly stats, recent publications)
- Publication search (title + author partial match, quartile filter, pagination)
- Researcher directory (department/staff-type filter, sort by output/citations/h-index, is_active hiding)
- Reports — the 6 local-aggregate tabs only for launch (trend, yearly stats, department, quartile, ranking, sources)
- SDG mapping (top-2 by weight, deterministic tie-break, Unclassified category) — pending field verification
- Admin SSO login + sync trigger + sync lock + audit log
- "Last synced" timestamp on public pages

**Should have (competitive):**
- SDG mapping as a differentiator (few small institutional systems do this)
- Guaranteed read-availability during sync (explicit differentiator vs. naive lock-everything systems)
- CSV export of search/report views (cheap, resolves an open spec question, real recurring faculty need)

**Defer (v2+):**
- Reports — the 4 field-dependent tabs (international collaboration, funding sources, author roles) until Scopus field completeness is confirmed
- Multi-Scopus-ID-per-researcher support (add schema now, defer full handling)
- Scheduled/automatic sync, PDF export, additional bibliometric sources (WoS, Google Scholar)

### Architecture Approach

The system is a one-directional pipeline: Scopus API → CLI sync worker (fetch-then-write, transactional upserts, SDG scoring, rollup building) → MySQL/InnoDB → read-only PHP repository layer → server-rendered public pages, with an admin trigger endpoint that only enqueues work and never calls Scopus itself. This decoupling is what makes "public pages always available, sync never blocks reads" true, and what makes the sync worker buildable and testable via CLI before any SSO/admin UI exists.

**Major components:**
1. Sync Trigger Endpoint (`admin/sync.php`) — auth check, reject-if-running (409), enqueue and return immediately
2. Sync Worker (CLI, Task-Scheduler-launched) — owns the full sync lifecycle: GET_LOCK mutex, two-phase fetch-then-write, record validation/skip-reject, SDG scoring, rollup building, stale-lock reaper
3. Query/Repository Layer — the single place all public pages read from via PDO prepared statements; owns all SQL
4. Rollup Builder — precomputes per-year/department/SDG/country aggregate tables at end of each sync so `reports.php` never live-aggregates across many-to-many joins on every page view

### Critical Pitfalls

1. **Sync runs inside the HTTP request and dies on a PHP/IIS timeout** — retry backoff alone (5s+15s+45s) exceeds PHP `max_execution_time` and IIS FastCGI timeouts. Avoid by having the trigger endpoint only enqueue; a detached CLI worker (Task Scheduler or `proc_open`) does the real work.
2. **Delete-then-reload sync breaks the read-availability guarantee** — even brief windows of empty/partial tables (or MyISAM table locks) violate the core value. Avoid via InnoDB + upsert-by-natural-key (`ON DUPLICATE KEY UPDATE`) or staging-table + atomic `RENAME TABLE` swap; never `TRUNCATE`/`DELETE`-then-`INSERT` on live tables.
3. **Sync mutex is a check-then-set race with no recovery path** — a status-column read-then-write is not atomic, and a crashed worker leaves the lock stuck forever. Avoid via `GET_LOCK`/`RELEASE_LOCK` as the race-free gate, with `sync_log.status` + heartbeat for durable UI state and a stale-lock reaper for crash recovery.
4. **The Scopus SDG relevance-weight field may not exist as the spec assumes** — no confirmed API field returns a numeric 0.0–1.0 SDG score; this may live only in SciVal (separate license) or not be available at all. Avoid by isolating scoring behind an `SdgScorerInterface` and making one real API call before writing SDG-mapping code, with a keyword-matching fallback scorer ready.
5. **Many-to-many join fan-out silently inflates dashboard/report numbers** — joining `publications` through `publication_authors` and `publication_sdgs` in one query multiplies citation/count aggregates. Avoid by aggregating each many-side relation independently (subquery/CTE) before combining, never `SUM`/`COUNT` directly over a multi-many-join query.

## Implications for Roadmap

Based on research, suggested phase structure:

### Phase 1: Foundations + Scopus Sync Engine
**Rationale:** Every other feature reads from tables only this phase's sync writes; it is also where the highest-risk unknowns (real DB schema, PHP version, sync failure-mode contract) must be resolved before downstream work is planned.
**Delivers:** Verified production schema, PDO connection layer, Scopus API client with retry/backoff/429 handling (tested against recorded fixtures), sync orchestrator (GET_LOCK mutex, two-phase fetch-then-write, record validator, stale-lock reaper), `sync_log` state machine — all CLI-runnable without SSO/admin UI.
**Addresses:** Scopus sync with error handling (PROJECT.md Active #1)
**Avoids:** Pitfalls 1 (in-request sync timeout), 2 (delete-then-reload), 3 (mutex race), 6 (partial-write contradiction), 7 (dev quota exhaustion — establish fixture-based testing here)

### Phase 2: Verification Spikes (SDG field, roster data source, quartile shape)
**Rationale:** Three field-shape questions block correct design of downstream features and were explicitly flagged as unresolved by every research file; resolving them now (ideally alongside/immediately after Phase 1's real API access) prevents late-discovery rewrites.
**Delivers:** Confirmed answers (or documented fallback decisions) for: (a) whether Scopus returns a numeric per-SDG weight or just labels, (b) where researcher roster master data (dept, staff type, Thai name, is_active) comes from, (c) whether quartile is a document field or journal-year lookup.
**Addresses:** Unblocks SDG mapping, researcher directory, department reports, quartile filter/reports
**Avoids:** Pitfall 4 (unverified SDG field) — the single highest-leverage open question per PITFALLS.md

### Phase 3: Public Dashboard + Rollup Builder
**Rationale:** Simplest public-facing queries, and this is where the Rollup Builder (needed by heavier report tabs later) should first be built and proven.
**Delivers:** Dashboard (counts, quartile mix, top researchers, yearly stats, recent publications), rollup tables (`stats_by_year`, etc.), "last synced" timestamp on public pages.
**Uses:** MySQL rollup tables (ARCHITECTURE.md Pattern 6), Bootstrap + Chart.js for visualization
**Implements:** Rollup Builder component, Query/Repository Layer

### Phase 4: Publication Search + Researcher Directory
**Rationale:** Both are core table-stakes public pages; directory is gated on Phase 2's roster-data-source decision.
**Delivers:** Search (title/author partial match, quartile filter, pagination), researcher directory (department/staff-type filter, sort, is_active hiding, LEFT JOIN so 0-publication researchers still appear).
**Addresses:** PROJECT.md Active #3, #4
**Avoids:** Pitfall 5 (fan-out), UX pitfall of INNER-JOIN-excluding zero-publication researchers

### Phase 5: Reports — Local-Aggregate Tabs
**Rationale:** These 6 tabs depend only on the sync + rollups already built (trend, yearly stats, department, quartile, ranking, sources) and carry no external-field risk — bank this win before the field-dependent tabs.
**Delivers:** 6 of the 10 report tabs.
**Addresses:** PROJECT.md Active #5 (partial)
**Avoids:** Pitfall 5 (fan-out) — verify every aggregate against a hand-computed value on a known small dataset

### Phase 6: SDG Mapping + SDG Report Tab
**Rationale:** Depends on Phase 2's SDG-field verification; self-contained once that's resolved, and is a named differentiator.
**Delivers:** SDG scoring (top-2, tie-break, Unclassified), per-publication display, SDG stats report tab, transactional per-publication recompute (never table-wide delete-then-insert).
**Addresses:** PROJECT.md Active #6
**Avoids:** Anti-Pattern 2 (whole-table delete-then-reinsert silently misreporting "Unclassified" mid-recompute)

### Phase 7: Admin SSO + Sync Trigger UI + CI/CD Deploy
**Rationale:** Deliberately last — the sync worker has been driven from the CLI throughout, so SSO is additive, not a blocker for anything upstream. Also the highest concentration of security pitfalls, needing dedicated attention rather than being rushed at the end.
**Delivers:** MEDSCI ACC SSO login (Method 1), sync trigger endpoint with 409 handling, audit log (`triggered_by`), force-unlock recovery UI, self-hosted GitHub Actions deploy pipeline with release-folder pattern (not mirror-sync).
**Addresses:** PROJECT.md Active #7
**Avoids:** Pitfalls 8 (SSO CSRF/replay), 9 (self-hosted runner compromise), 10 (mirror-deploy wiping config)

### Phase Ordering Rationale

- Sync-and-schema comes first because literally every other phase reads data only the sync writes — there is no meaningful "vertical slice" that doesn't first need this.
- The three verification spikes are pulled into their own phase immediately after foundations rather than left implicit, because FEATURES.md, ARCHITECTURE.md, and PITFALLS.md all independently flagged the same three unknowns as blocking — treating them as a dedicated phase forces resolution before downstream design, not during it.
- Reports are split into local-aggregate (low risk, no external dependency) vs. field-dependent (higher risk, deferred to v1.x per FEATURES.md's own MVP definition) — this ordering directly reflects the dependency graph FEATURES.md documents.
- SSO/CI-CD is deliberately last because ARCHITECTURE.md's Build Order explicitly notes the sync worker is CLI-testable without it, and because PITFALLS.md concentrates 3 of its 10 critical pitfalls (8, 9, 10) in this area — isolating it lets security review happen without competing against earlier functional work.

### Research Flags

Needs research during planning:
- **Phase 2 (Verification Spikes):** SDG weight field, roster data source, and quartile shape are all LOW-confidence externally and require a live Scopus API call this research pass could not make — flag for `--research-phase` or a dedicated spike task before detailed planning.
- **Phase 1 (Sync Engine):** Windows Task Scheduler vs. detached-process pattern is a design recommendation, not IIS/Scopus-official documentation, and depends on an unresolved IT policy question (permission to register a Scheduled Task) — verify early.
- **Phase 7 (SSO/CI-CD):** Custom SSO replay/CSRF protection and self-hosted runner hardening are domain-specific security patterns without official MEDSCI ACC documentation available — treat as needing careful, possibly externally-reviewed design.

Phases with standard patterns (skip deep research):
- **Phase 3 (Dashboard):** Standard aggregate-query + rollup-table pattern, well-documented in ARCHITECTURE.md.
- **Phase 4 (Search/Directory):** Standard paginated search/filter pattern; only risk (LIKE-scan performance) has a known, documented fix (FULLTEXT index) if needed at scale.
- **Phase 5 (Local-aggregate reports):** Standard GROUP BY reporting; fan-out avoidance pattern is well-specified in PITFALLS.md and ARCHITECTURE.md.

## Confidence Assessment

| Area | Confidence | Notes |
|------|------------|-------|
| Stack | MEDIUM | Version numbers (PHP, Guzzle, Bootstrap) are HIGH confidence — verified directly against php.net, Packagist, getbootstrap.com. Judgment calls (8.4-over-8.5, Guzzle 7.x-over-8.0) are reasoned but not documented fact. Scopus-specific API details (auth headers, rate-limit scheme, SDG source) are explicitly LOW confidence. |
| Features | MEDIUM | HIGH confidence on what counts as table-stakes structurally (corroborated against Pure/Symplectic/VIVO vendor pages); LOW/unverified on two Scopus-data-shape questions (quartile source, SDG weight field) that directly gate feature scope. |
| Architecture | MEDIUM-HIGH | Core patterns (two-phase sync, InnoDB MVCC, GET_LOCK mutex, upsert, rollup tables) are well-established RDBMS/ETL practice, cross-checked against MySQL/MariaDB docs. Two project-specific unknowns (SDG field, Windows Task Scheduler permission model) are explicitly flagged as unverified. |
| Pitfalls | MEDIUM | Cross-checked across 2+ independent sources per finding; no official-docs provider was available in this research environment. The SDG API-field claim is explicitly flagged LOW confidence and framed as a verification task, not a fact. |

**Overall confidence:** MEDIUM

### Gaps to Address

- **SDG weight field source (HIGH severity):** Not confirmed whether Scopus's Search/Abstract Retrieval API returns a numeric per-SDG relevance score, only labels, or nothing (SDG scoring may live only in SciVal, a separately licensed product). Resolve via one real API call before Phase 6 is planned in detail; the `SdgScorerInterface` design lets either outcome be absorbed without touching downstream code.
- **Researcher roster master data source (HIGH severity):** Department, staff type, Thai name, and active/inactive status are not derivable from Scopus at all. Needs an explicit decision (one-time roster seed treated as setup, or confirmation the legacy DB already holds this) before Phase 4/5 (directory, department report) are planned.
- **Quartile data shape (MEDIUM severity):** Unclear whether quartile is a per-document field or a journal-year lookup requiring a `journals`/`sources` table and an extra API call type. Affects schema for dashboard, search filter, and quartile report tab simultaneously — resolve once to unblock all three.
- **Actual production PHP version and DB schema:** Not yet confirmed against the live IIS server; SPEC.md already earmarks an 18–19 Aug verification window — extend it to cover PHP version alongside schema.
- **IT policy on registering a Windows Scheduled Task:** Determines which of the two sync-job patterns (registered Scheduled Task vs. detached `proc_open` spawn) gets built in Phase 1 — resolve before writing the sync worker's process-launch code.
- **MEDSCI ACC SSO token lifetime/replay policy:** Unconfirmed whether the IdP itself rejects replayed tokens; this project's design should implement its own replay protection regardless, per PITFALLS.md Pitfall 8, rather than wait on this answer.

## Sources

### Primary (HIGH confidence)
- php.net — Supported Versions (fetched directly 2026-08-18)
- Packagist — guzzlehttp/guzzle (fetched directly 2026-08-18)
- getbootstrap.com — Versions (fetched directly 2026-08-18)
- MySQL/InnoDB row-level locking + MVCC — foundational, well-documented engine behavior

### Secondary (MEDIUM confidence)
- Elsevier product pages (Pure, Symplectic Elements) and VIVO documentation — CRIS feature-landscape comparison
- Elsevier Scopus Blog — 2023 SDG mapping methodology announcement; Springer/Scientometrics peer-reviewed paper corroborating label/query-match methodology
- MySQL/MariaDB official docs (`GET_LOCK`, `INSERT ... ON DUPLICATE KEY UPDATE`) via cross-checked web search
- DevOps Journal, GitHub Marketplace — self-hosted runner + IIS deploy patterns
- Wiz, Sysdig, GitHub Docs — self-hosted GitHub Actions runner risk
- SSOJet, CyberReplay, arXiv CSRF/OIDC papers — custom SSO vulnerability patterns
- SQL fan-out / many-to-many aggregation trap — multiple independent web sources (Medium, DZone)

### Tertiary (LOW confidence)
- KTH Library kthcorpus docs, Elsevier Data-as-a-Service support page — Scopus rate-limit/quota specifics, needs live verification
- SciVal LibGuide — supports the SDG/SciVal licensing risk but is not a definitive API spec
- Medium/Zomro blog posts on PHP cron-on-Windows — generic guidance, not IIS/Scopus-official
- caseyamcl/guzzle_retry_middleware README — single-source, defaults don't match spec's retry contract

---
*Research completed: 2026-08-18*
*Ready for roadmap: yes*
