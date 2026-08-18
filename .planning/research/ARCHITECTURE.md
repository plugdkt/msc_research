# Architecture Research

**Domain:** Bibliometric sync + read-only PHP/MySQL analytics repository (Scopus → MySQL → public dashboard/reports)
**Researched:** 2026-08-18
**Confidence:** MEDIUM-HIGH (core patterns are well-established RDBMS/ETL practice, cross-checked against MySQL/MariaDB docs; two project-specific unknowns flagged explicitly below)

## Standard Architecture

### System Overview

```
┌───────────────────────────────────────────────────────────────────────┐
│  ADMIN SIDE (behind MEDSCI ACC SSO)                                    │
│  ┌─────────────────┐        ┌──────────────────────┐                  │
│  │ admin/index.php  │──POST──▶│ Sync Trigger Endpoint │                │
│  │ (SSO login page) │        │ writes sync_log(queued)│               │
│  └─────────────────┘        │ returns 202 immediately│                │
│                              │ (409 if already running)│               │
│                              └──────────┬───────────┘                  │
└─────────────────────────────────────────┼──────────────────────────────┘
                                           │ (async — no HTTP request holds open)
┌──────────────────────────────────────────▼─────────────────────────────┐
│  SYNC WORKER (CLI process, launched by Windows Task Scheduler poll)     │
│  ┌────────────────────────────────────────────────────────────────┐   │
│  │ Sync Orchestrator                                               │   │
│  │  1. GET_LOCK('scopus_sync') — race-free mutex                   │   │
│  │  2. mark sync_log row 'running' + heartbeat                     │   │
│  │  3. PHASE 1 — FETCH (read-only, no DB writes yet)                │   │
│  │       Scopus API Client → retry/backoff/429 handling             │   │
│  │  4. PHASE 2 — WRITE (transactional, per-record)                  │   │
│  │       Upsert researchers/publications/publication_authors        │   │
│  │       Skip/reject rules (missing fields, dup Scopus Author ID)   │   │
│  │  5. SDG Scorer → transactional recompute (delete+insert per pub) │   │
│  │  6. Rollup Builder → stats_by_year / stats_by_department / etc. │   │
│  │  7. mark sync_log 'success' | 'failed' | 'partial', RELEASE_LOCK │   │
│  └────────────────────────────────────────────────────────────────┘   │
│  Stale-Lock Reaper: cron-checked; IS_USED_LOCK returns NULL + heartbeat│
│  stale → force sync_log to 'failed', unblock future syncs             │
└──────────────────────────────────────────┬─────────────────────────────┘
                                           │ writes (INSERT/UPDATE, short txns)
┌──────────────────────────────────────────▼─────────────────────────────┐
│  MySQL / InnoDB (row-level locking + MVCC)                             │
│  researchers · publications · publication_authors · sync_log           │
│  sdgs · publication_sdgs · stats_by_year · stats_by_department (etc.)  │
└──────────────────────────────────────────┬─────────────────────────────┘
                                           │ reads (SELECT only, no locks)
┌──────────────────────────────────────────▼─────────────────────────────┐
│  PUBLIC PAGES (PHP, server-rendered, no auth)                          │
│  ┌───────────┐ ┌───────────┐ ┌────────────────┐ ┌──────────────────┐  │
│  │ index.php │ │pub_search │ │researchers_list│ │   reports.php    │  │
│  │(dashboard)│ │   .php    │ │      .php      │ │ (10 analysis tabs)│  │
│  └─────┬─────┘ └─────┬─────┘ └────────┬───────┘ └────────┬─────────┘  │
│        └─────────────┴────────────────┴──────────────────┘            │
│                     Query/Repository Layer (PDO, prepared statements)  │
└──────────────────────────────────────────────────────────────────────┘
```

**Key structural decision:** the sync is not a synchronous HTTP request. Admin click → API call → retry backoff (up to 5s+15s+45s ≈ 65s per failing batch, longer with rate-limit pauses) will exceed PHP `max_execution_time` and IIS FastCGI activity timeouts if run inline in the request. The trigger endpoint only enqueues (`sync_log` row, status `queued`) and returns; a separately-scheduled worker process (Windows Task Scheduler running `php sync_worker.php` on a short interval, or as a long-running service) does the actual work. This also means the sync worker is testable and runnable from the CLI before the SSO/admin panel exists at all — see Build Order.

### Component Responsibilities

| Component | Responsibility | Typical Implementation |
|-----------|----------------|-------------------------|
| Sync Trigger Endpoint (`admin/sync.php`) | Auth check, reject if already running (HTTP 409), enqueue `sync_log(status='queued', triggered_by=...)`, return immediately | PHP endpoint behind SSO session check; does not call Scopus itself |
| Sync Worker (`sync_worker.php`, CLI) | Owns the actual sync lifecycle end to end | PHP CLI script invoked by Windows Task Scheduler on a 1-2 min poll, or run directly for manual/local testing |
| Scopus API Client | HTTP calls to Scopus, retry/backoff, 429 handling, response parsing | Thin wrapper around `curl`/Guzzle; no DB knowledge — returns DTOs |
| Sync Orchestrator | Sequences fetch → write → SDG recompute → rollups; owns the lock and `sync_log` state machine | PHP class; the one place that knows the full sync state machine |
| Sync Mutex | Prevent two syncs running concurrently, race-free | MySQL `GET_LOCK('scopus_sync', 0)` (non-blocking, immediate 0/1) inside the orchestrator's own DB connection |
| Sync Status (durable) | What the admin UI and 409 check read — must survive worker crash | `sync_log.status` column (`queued`/`running`/`success`/`failed`/`partial`) + `heartbeat_at` timestamp, read by trigger endpoint without needing `GET_LOCK` itself |
| Stale-Lock Reaper | Detect a crashed worker (process died, `GET_LOCK` released, but `sync_log` still says `running`) and unstick future syncs | Checked at the top of every trigger request and by worker startup: if `IS_USED_LOCK('scopus_sync')` is NULL but latest `sync_log` row is `running` with a heartbeat older than e.g. 5 minutes, force it to `failed` |
| Record Validator | Skip malformed records (null DOI/author), reject duplicate Scopus Author IDs, count skips | Pure functions/validators run during the write phase, per SPEC 6.1 |
| SDG Scorer | Map each publication to top-2 SDGs by weight, tie-break by SDG code, tag "Unclassified" | Small interface (`ScopusProvidedScorer` vs. fallback `KeywordScorer`) — isolates the open Scopus-field question (see Pattern 5) |
| Rollup Builder | Precompute per-year/per-department/per-SDG/per-country aggregates at end of sync | Runs inside the same sync worker, writes to dedicated `stats_*` tables, keeps `reports.php` to simple `SELECT`s |
| Query/Repository Layer | Single place all public pages get data from; owns SQL, PDO prepared statements | PHP classes/functions per entity (`ResearcherRepository`, `PublicationRepository`, `ReportRepository`) |
| Public Pages | Server-rendered read-only views; no writes, no session required | `index.php`, `publications_search.php`, `researchers_list.php`, `reports.php` — thin controllers over the Repository Layer |
| MySQL/InnoDB | Storage, row-level locking, MVCC (so reads never block on the sync's writes) | Standard InnoDB tables; unique keys on `scopus_author_id`, `scopus_pub_id`/DOI |

## Recommended Project Structure

```
app/
├── public/                    # IIS webroot — only this is served
│   ├── index.php              # dashboard
│   ├── publications_search.php
│   ├── researchers_list.php
│   ├── reports.php
│   └── admin/
│       ├── index.php          # SSO login + sync trigger UI
│       └── sync.php           # POST endpoint: enqueue sync, 409 if running
├── src/                        # NOT web-accessible (outside webroot or blocked via web.config)
│   ├── Sync/
│   │   ├── ScopusClient.php    # HTTP calls, retry/backoff, 429 handling
│   │   ├── SyncOrchestrator.php# lock, sync_log state machine, phase sequencing
│   │   ├── RecordValidator.php # skip/reject rules (6.1)
│   │   ├── StaleLockReaper.php
│   │   └── worker.php          # CLI entrypoint, run by Task Scheduler
│   ├── Sdg/
│   │   ├── SdgScorerInterface.php
│   │   ├── ScopusProvidedScorer.php   # if Scopus exposes a weight field
│   │   └── KeywordFallbackScorer.php  # fallback if it doesn't (see Pattern 5)
│   ├── Rollup/
│   │   └── RollupBuilder.php   # writes stats_by_year, stats_by_department, ...
│   ├── Repository/
│   │   ├── ResearcherRepository.php
│   │   ├── PublicationRepository.php
│   │   └── ReportRepository.php
│   ├── Auth/
│   │   └── MedsciAccClient.php # SSO redirect + verify.php call, session mgmt
│   └── Db.php                  # PDO connection factory, prepared-statement helpers
├── config/
│   └── config.php              # DB creds, Scopus key, SSO client_secret — NOT in webroot, NOT in git
└── .github/workflows/deploy.yml
```

### Structure Rationale

- **`public/` vs `src/`:** only `public/` is the IIS physical webroot; `src/` and `config/` sit outside it (or are blocked via `web.config` deny rules) so config files and SSO client_secret can never be requested directly by URL — this is an explicit SPEC 9/10 requirement.
- **`Sync/` is self-contained and DB-agnostic at the client level:** `ScopusClient` has no MySQL knowledge, so it can be tested against recorded API fixtures without a database — this is what lets the sync worker be built and tested before schema/DB work is fully wired end-to-end.
- **`Sdg/` is an interface, not a single class:** isolates the still-open question of which Scopus field (if any) actually carries an SDG relevance float (see Pattern 5) so that only one file changes if the answer turns out to be "no such field exists."
- **`Rollup/` is separate from `Repository/`:** rollups are write-time (produced once per sync); repositories are read-time (queried per page view). Conflating them would put aggregation cost back on every page load.

## Architectural Patterns

### Pattern 1: Two-Phase Sync (Fetch-then-Write)

**What:** Split each sync run into Phase 1 (fetch everything from Scopus into memory/DTOs, no DB writes) and Phase 2 (write to MySQL, one transaction per record or small batch).
**When to use:** Any sync job with an "abort entire batch on transport failure, but skip individual bad records" requirement — which is exactly what SPEC 6.1 asks for (timeout/429 → abort whole sync with zero partial writes; malformed record → skip that record only, continue).
**Trade-offs:** Resolves the apparent contradiction in 6.1 for free — since transport failures (timeout, 429, retries exhausted) happen entirely in Phase 1, "abort with no partial writes" is automatic because no writes have happened yet. Record-level issues (null DOI, duplicate Scopus Author ID) are only ever encountered in Phase 2 and are handled per-record. At ~800 publications this fits comfortably in PHP memory; if scaling toward 10,000+ triggers memory pressure, land Phase 1 output in a staging table instead of an in-memory array — same two-phase logic, no design change.

**Example:**
```php
// SyncOrchestrator::run()
$fetched = $this->scopusClient->fetchAll(); // throws SyncAbortedException on
                                             // exhausted retries/429 — no DB touched yet
$skipped = 0;
foreach ($fetched as $record) {
    if (!$this->validator->isComplete($record)) { $skipped++; continue; }
    if ($this->validator->isDuplicateAuthorId($record)) { $skipped++; $this->logRejection($record); continue; }
    $this->publicationRepository->upsert($record); // Pattern 3
}
$this->syncLog->recordSkipped($skipped);
```

### Pattern 2: Dual-Layer Sync Lock (mutex + durable status + reaper)

**What:** A race-free in-connection lock (`GET_LOCK`) guards the actual concurrent-execution race; a separate durable `sync_log.status` column is what the admin UI and the 409-check read, because it must be queryable from a different HTTP request/connection than the one running the sync.
**When to use:** Whenever "reject a second trigger while one is running" needs to survive both concurrent requests *and* the crash of the running job (SPEC 14.2 tests the first case explicitly; a crashed worker leaving the system permanently locked is a real production risk the test list does not cover).
**Trade-offs:** Two pieces of state to keep consistent (acceptable, well-understood) vs. the simpler-but-fragile alternative of relying on `sync_log.status='running'` alone, which has a check-then-act race between "check no sync running" and "insert running row" unless also guarded by `GET_LOCK`.

**Example:**
```php
// Trigger endpoint (admin/sync.php) — fast, no Scopus call here
$latest = $syncLogRepo->latest();
if ($latest && $latest->status === 'running' && !staleLockReaper->isStale($latest)) {
    http_response_code(409);
    echo json_encode(['error' => 'มีการซิงค์กำลังทำงานอยู่ กรุณารอให้เสร็จก่อน']);
    exit;
}
$syncLogRepo->enqueue(triggeredBy: $currentUser->id);

// Worker (sync_worker.php) — the actual race-free guard
$pdo = Db::connect(); // fresh, non-persistent connection
$got = $pdo->query("SELECT GET_LOCK('scopus_sync', 0)")->fetchColumn();
if ($got != 1) { exit; } // another worker instance beat us — no-op
try {
    $syncLogRepo->markRunning($jobId);
    // ... phases 1-4 ...
    $syncLogRepo->markSuccess($jobId);
} finally {
    $pdo->query("SELECT RELEASE_LOCK('scopus_sync')");
}
```
Reaper check (run at worker startup and optionally on a schedule):
```sql
SELECT IS_USED_LOCK('scopus_sync'); -- NULL = no live holder
-- if NULL AND sync_log.status='running' AND heartbeat_at < NOW() - INTERVAL 5 MINUTE
-- => UPDATE sync_log SET status='failed', error_message='worker crashed / stale lock' WHERE id = ...
```

### Pattern 3: Idempotent Upsert via `ON DUPLICATE KEY UPDATE`

**What:** Every write during sync targets a table with a unique key derived from Scopus's own identifier (`scopus_author_id` on `researchers`, Scopus publication ID or DOI on `publications`), and uses `INSERT ... ON DUPLICATE KEY UPDATE` to insert-or-update atomically in one statement.
**When to use:** Any re-sync that must not duplicate rows for records already present — this is the mechanism that makes "re-sync recomputes without duplicating" true for the base entity tables, not just SDG mapping.
**Trade-offs:** Requires exactly one relevant unique index per table (multiple unique indexes make `ON DUPLICATE KEY UPDATE` behavior ambiguous) — keep `scopus_author_id` and the Scopus publication identifier as the sole natural keys. [Confidence: MEDIUM — cross-checked against MySQL/MariaDB reference docs.]

**Example:**
```sql
INSERT INTO researchers (scopus_author_id, name_th, name_en, department, h_index, is_active)
VALUES (:scopus_author_id, :name_th, :name_en, :department, :h_index, 1)
ON DUPLICATE KEY UPDATE
  name_th = VALUES(name_th), name_en = VALUES(name_en),
  department = VALUES(department), h_index = VALUES(h_index);
-- is_active intentionally NOT overwritten here — admin never edits it via sync;
-- it only flips based on a separate, explicit business rule, not blind overwrite.
```

### Pattern 4: Transactional Per-Publication SDG Recompute (no transient "Unclassified")

**What:** Re-sync deletes and reinserts a publication's `publication_sdgs` rows to reflect fresh Scopus data — but the delete+insert for a single publication happens inside **one transaction**, never as a separate "DELETE ALL, then re-INSERT all" pass across the whole table.
**When to use:** This is the single most important pitfall in the whole system, because "public pages must stay available and fast during sync" is stated as the Core Value — but availability without correctness is a false pass. If SDG mapping is recomputed as `DELETE FROM publication_sdgs; -- then re-insert everything`, a reader querying the SDG report *between* those two statements sees publications with zero SDG rows and counts them as "Unclassified" even though they have valid mappings — reads were never blocked, but they were wrong for the duration of the recompute.
**Trade-offs:** Per-publication transactions add minor overhead (hundreds of small transactions instead of one big delete/insert) but InnoDB's MVCC means concurrent readers only ever see either the fully-old or fully-new SDG set for a given publication, never a gap. At project scale (≤10,000 publications) this overhead is negligible. The heavier alternative — build a shadow `publication_sdgs_new` table and `RENAME TABLE` swap it in atomically — gives an even stronger single-instant cutover for the *entire* table, but is unnecessary complexity for this timeline; mention it as the option to reach for only if per-row correctness ever proves insufficient.

**Example:**
```php
$pdo->beginTransaction();
$pdo->prepare("DELETE FROM publication_sdgs WHERE publication_id = ?")->execute([$pubId]);
foreach ($topTwoSdgs as $rank => $sdg) {
    $pdo->prepare("INSERT INTO publication_sdgs (publication_id, sdg_id, weight, `rank`) VALUES (?, ?, ?, ?)")
        ->execute([$pubId, $sdg->id, $sdg->weight, $rank + 1]);
}
$pdo->commit(); // readers see old-set-or-new-set, never zero-set, for this publication
```

### Pattern 5: Pluggable SDG Scorer (isolate the open Scopus-field question)

**What:** Define an `SdgScorerInterface::score(publicationData): array<SdgScore>` and implement it behind that boundary, rather than hard-coding "read `$record->sdgWeights`" throughout the sync code.
**When to use:** Whenever a core piece of the pipeline depends on an unconfirmed external contract. Here specifically: SPEC's Open Questions (§12) admit the exact Scopus API/field that supplies an SDG relevance float is unconfirmed, and per-article SDG relevance floats are not a standard field on the Scopus Search/Author Retrieval APIs — that kind of scoring is more commonly associated with Elsevier's SciVal/Fingerprint Engine tooling, which may not be reachable from the credentials this project has. **This should be verified directly against the actual Scopus API response before Phase "SDG mapping" is built** (per the project's own 18-19 Aug open-questions task) — treat it as the highest-risk unresolved item in the architecture, not a minor detail.
**Trade-offs:** If the field exists as assumed, the `ScopusProvidedScorer` implementation is a thin passthrough. If it doesn't, a fallback `KeywordFallbackScorer` (matching title/abstract keywords against Elsevier's published per-SDG search query sets) becomes necessary — a materially larger scope than "read a field," so isolating this now is what prevents that discovery from becoming a late rewrite that touches the orchestrator, the DB writer, and the reports.

### Pattern 6: Rollup Tables for Report Performance

**What:** At the end of every successful sync, the worker computes and writes small aggregate tables (`stats_by_year`, `stats_by_department`, `stats_by_sdg`, `stats_by_country`, etc.) instead of leaving `reports.php` to `GROUP BY` across `publications × publication_authors × researchers` on every page view.
**When to use:** `reports.php` has ten analysis tabs, several of which require fan-out joins (a publication counted once per department of its co-authors, once per country of its collaborators) — recomputing that live on every request is the most likely place the 3-second target breaks first as data grows toward 10,000 publications / 500 researchers.
**Trade-offs:** Report numbers are only as fresh as the last sync (acceptable — the whole system already defines "current" as "as of last sync," shown via a timestamp per SPEC §4.1). This creates an explicit phase dependency: reports.php's heavier tabs depend on the sync worker having run rollups at least once — a roadmap-relevant constraint (see Build Order).

## Data Flow

### Admin-Triggered Sync Flow

```
Admin clicks "Sync" (after SSO login)
    ↓
admin/sync.php: check sync_log latest status (+ reaper) → 409 if running, else enqueue 'queued' row, return 202
    ↓ (decoupled — no open HTTP connection carries this forward)
Windows Task Scheduler fires sync_worker.php (polling or fixed interval)
    ↓
GET_LOCK → mark 'running' + heartbeat
    ↓
Phase 1: ScopusClient.fetchAll() [retry/backoff/429 — see Pitfalls doc]
    ↓ (on transport failure: mark 'failed', RELEASE_LOCK, stop — zero writes so far)
Phase 2: per-record validate → upsert (Pattern 3) → skip/reject counters
    ↓
SDG Scorer → per-publication transactional recompute (Pattern 4)
    ↓
Rollup Builder → stats_* tables
    ↓
mark sync_log 'success' / 'partial' (if skips occurred), RELEASE_LOCK
```

### Public Read Flow (concurrent with the above, never blocked by it)

```
Any visitor request (no auth)
    ↓
Public page (index.php / publications_search.php / researchers_list.php / reports.php)
    ↓
Repository layer → PDO prepared statement SELECT (InnoDB MVCC snapshot read — no lock wait on sync's writer transactions)
    ↓
HTML-escaped render (all Scopus-sourced strings escaped — XSS requirement, SPEC §9)
```

### Key Data Flows

1. **Sync write path:** Scopus API → DTO → validator → MySQL (InnoDB, short transactions) — one-directional, sync worker is the only writer to `researchers`/`publications`/`publication_sdgs`/`stats_*`; nothing else ever writes these tables.
2. **Public read path:** MySQL → Repository → PHP page render — read-only, no session/auth, must never wait on the sync's write locks (guaranteed by InnoDB row-level locking + MVCC, not by any DB config change).
3. **Admin control path:** Admin session (SSO-verified) → `sync_log` status row → worker picks it up asynchronously — decoupled by design so the HTTP request never carries the multi-second/multi-retry sync duration.

## Scaling Considerations

| Scale | Architecture Adjustments |
|-------|---------------------------|
| ~100 researchers / ~800 publications (current) | Simple queries directly against `researchers`/`publications`/`publication_authors` are fine for dashboard/search; rollup tables mainly help `reports.php`'s heavier tabs (department fan-out, country fan-out) stay well under 3s. |
| 500 researchers / 10,000 publications (target) | Rollup tables (Pattern 6) become load-bearing, not just nice-to-have, for every `reports.php` tab. Ensure indexes on `publications.publication_year`, `publications.quartile`, `publication_authors.researcher_id`, `researchers.department`, `researchers.is_active`. `publications_search.php`'s `LIKE '%term%'` on title/author is the one place a full scan risk grows with volume — a MySQL FULLTEXT index on title (and on the author name columns it touches) is the standard fix if plain `LIKE` starts missing the 2s dashboard / page-load budget at this scale (dashboard target doesn't cover search directly, but the same table is queried from multiple pages). |
| Beyond current target (not asked for, noted for awareness) | Read replica for public pages if a single MySQL instance becomes contended — unlikely to be needed at 10k rows; call out only so nobody reaches for it prematurely. |

### Scaling Priorities

1. **First bottleneck:** `reports.php`'s multi-dimension aggregation queries (department fan-out, country fan-out, SDG stats) — addressed by Pattern 6 (rollups computed once per sync, not per page view).
2. **Second bottleneck:** `publications_search.php`'s `LIKE %term%` search as publication count grows toward 10,000 — addressed by a FULLTEXT index on the searched columns if/when `LIKE` scans become measurably slow; not needed at current ~800-row scale.

## Anti-Patterns

### Anti-Pattern 1: Synchronous sync-on-request

**What people do:** Have the admin's "Sync" button directly call the Scopus API inline in the HTTP request handler, including all retries/backoff.
**Why it's wrong:** Retry backoff alone (5s+15s+45s) plus rate-limit pauses can exceed PHP `max_execution_time` and IIS FastCGI timeouts, causing the request to be killed mid-sync — which is exactly the kind of partial-write risk SPEC 6.1 is trying to prevent, and it ties up an HTTP worker thread for the whole duration.
**Do this instead:** Trigger endpoint only enqueues; a separately-scheduled CLI worker (Pattern in System Overview) performs the actual sync.

### Anti-Pattern 2: Whole-table DELETE-then-reinsert for SDG recompute

**What people do:** `DELETE FROM publication_sdgs;` followed by a loop re-inserting fresh rows for every publication, as one long-running operation.
**Why it's wrong:** Between the mass delete and the corresponding re-insert, any concurrent reader sees zero SDG rows for publications that actually have valid mappings — silently misreporting them as "Unclassified." This satisfies "reads aren't blocked" while violating "reads are correct," and it's easy to miss because nothing errors.
**Do this instead:** Per-publication transaction wrapping that publication's delete+insert (Pattern 4), so MVCC guarantees each reader sees a complete old-or-new set, never a gap.

### Anti-Pattern 3: Relying on `sync_log.status` alone as the concurrency guard

**What people do:** Check `SELECT status FROM sync_log ORDER BY id DESC LIMIT 1` and only proceed if it's not `'running'`, with no database-level lock.
**Why it's wrong:** Check-then-act race: two triggers arriving close together can both read "not running" before either writes "running," and both start syncing — the exact scenario SPEC 14.2 tests against.
**Do this instead:** `GET_LOCK` as the race-free gate (Pattern 2), with `sync_log.status` kept only for the queryable/durable UI-facing state, plus the stale-lock reaper for crash recovery.

### Anti-Pattern 4: N+1 queries inside report tab rendering

**What people do:** Loop over departments/years/SDGs in PHP and issue one query per iteration to get each one's counts.
**Why it's wrong:** With 5 departments × multiple metrics × 10 tabs, this multiplies quickly and is the most common cause of PHP+MySQL pages quietly missing performance targets as data grows, even though each individual query looks cheap.
**Do this instead:** Single `GROUP BY` query per metric (or a read from the corresponding rollup table per Pattern 6), never a query inside a PHP loop.

## Integration Points

### External Services

| Service | Integration Pattern | Notes |
|---------|----------------------|-------|
| Scopus API | Server-side HTTP client (`ScopusClient`) with retry/backoff/429 handling per SPEC 6.1 | Confirm exact API (Search vs. Author Retrieval) and the actual field carrying SDG relevance before building the SDG scorer (Pattern 5) — this is an explicitly open, high-risk question, not a settled fact. |
| MEDSCI ACC SSO | Server-side redirect + `POST msc_acc/api/verify.php` (Method 1 only, per SPEC 9) | `CURLOPT_SSL_VERIFYPEER` must be `true` — the sample guide code sets it `false`; do not copy that line unmodified. `client_secret` in server-side config outside webroot only. |
| Windows Task Scheduler (or equivalent Windows service) | Fires `sync_worker.php` on an interval; picks up `queued` `sync_log` rows | Chosen over a PHP-native queue/daemon because the deployment target is IIS/Windows with no existing job-queue infrastructure — the simplest mechanism that fits the existing stack. |
| Self-hosted GitHub Actions runner | Deploy-time only (file sync + IIS App Pool recycle) | Not part of the runtime data flow; listed here because SPEC §10 ties it to the same server. |

### Internal Boundaries

| Boundary | Communication | Notes |
|----------|----------------|-------|
| Admin trigger endpoint ↔ Sync worker | Shared MySQL table (`sync_log`), no direct call | Decoupling is intentional (Anti-Pattern 1) — the endpoint never invokes sync logic directly. |
| Sync Orchestrator ↔ Scopus Client | Direct PHP calls, DTOs in/out | `ScopusClient` has no DB or lock knowledge — testable in isolation against fixtures. |
| Sync Orchestrator ↔ Repository/DB writers | Direct PHP calls within the same process, one MySQL connection, transactional | Only the sync worker writes to `researchers`/`publications`/`publication_sdgs`/`stats_*`; no other component ever writes these. |
| Public pages ↔ Repository layer | Direct PHP calls, read-only prepared statements | No public page should contain raw SQL inline — keeps the "always prepared statements" SQLi requirement (SPEC §9) enforceable in one place. |

## Build Order

Dependencies flow bottom-up; each stage is independently testable before the next begins, which matters given the compressed timeline.

1. **Foundations (blocking everything else):** verify the real production schema against the draft in SPEC §7 (PROJECT.md explicitly flags this as unconfirmed) + config file location outside webroot + PDO connection layer with prepared statements.
2. **Scopus API Client:** retry/backoff/429 handling, built and tested against recorded fixtures — no DB dependency, no admin UI dependency. Resolve the SDG-field open question here, before Pattern 5 is implemented for real.
3. **Sync Orchestrator core:** `GET_LOCK` mutex, `sync_log` state machine, two-phase fetch-then-write (Pattern 1), record validator (skip/reject rules), stale-lock reaper. Runnable and testable from the CLI without SSO or admin UI existing yet.
4. **SDG Scorer + transactional recompute:** implement behind the interface from Pattern 5; wire Pattern 4's per-publication transaction.
5. **Rollup Builder:** end-of-sync aggregate tables — needed before `reports.php`'s heavier tabs can meet the 3s target.
6. **Public pages, in dependency order:** dashboard (simplest queries) → search → researcher list → reports (depends on step 5's rollups for several tabs).
7. **Admin panel + SSO + trigger endpoint (409 path):** last, because the sync worker has been driven from the CLI throughout development up to this point — SSO integration is additive, not a blocker for anything upstream.
8. **CI/CD (self-hosted runner, deploy workflow):** can be set up in parallel with steps 3-6 once step 1's foundations are stable, since it only depends on there being a deployable codebase, not a feature-complete one.

## Sources

- MySQL/MariaDB `GET_LOCK`/`RELEASE_LOCK`/`IS_USED_LOCK` advisory locking pattern — cross-checked via web search (oneuptime.com, the-art-of-web.com); confirms connection-scoped named locks, auto-release on disconnect, standard use for preventing concurrent job execution. [Confidence: MEDIUM — WebSearch, verified against multiple independent sources describing the same MySQL-documented behavior.]
- MySQL/MariaDB `INSERT ... ON DUPLICATE KEY UPDATE` upsert pattern — cross-checked via web search against MariaDB official docs and MySQL reference manual links (dev.mysql.com); confirms atomic insert-or-update on unique key conflict, single-unique-index caveat. [Confidence: MEDIUM-HIGH — corroborated directly by official MySQL/MariaDB documentation links surfaced in search results.]
- InnoDB row-level locking + MVCC as the mechanism that gives non-blocking public reads during concurrent writes — general RDBMS/InnoDB architecture knowledge, standard and uncontested. [Confidence: HIGH — foundational, well-documented MySQL engine behavior.]
- Two-phase (fetch-then-write) ETL/sync job structuring, rollup/materialized-aggregate tables for report performance, N+1 query anti-pattern, and PHP/IIS FastCGI request-timeout risk for long-running synchronous jobs — general software architecture practice for external-API-to-RDBMS sync systems. [Confidence: MEDIUM — standard practice, not sourced from a single authoritative document; treat as engineering judgment applied to this project's stated constraints.]
- Project-specific constraints and unresolved questions (SDG weight field, schema not yet verified, MEDSCI ACC SSO details) drawn directly from `/mnt/c/Users/plugc/Downloads/spec_workshop/SPEC.md` §§4.1, 6, 6.1, 7, 9, 12 and `/mnt/c/Users/plugc/Downloads/spec_workshop/.planning/PROJECT.md`.

---
*Architecture research for: Scopus-sync PHP/MySQL research repository (medical sciences faculty)*
*Researched: 2026-08-18*
