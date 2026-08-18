# Pitfalls Research

**Domain:** Scopus-integrated research publication repository (PHP/MySQL/IIS, custom internal SSO, self-hosted GitHub Actions CI/CD, multi-dimensional analytics reporting)
**Researched:** 2026-08-18
**Confidence:** MEDIUM overall (cross-checked web search across multiple independent sources; no vendor/official-docs provider was available in this environment — see Sources). One finding below is explicitly LOW confidence and is treated as a verification task, not a fact.

Pitfalls are ordered by **risk to the 3 Sep 2569 demo**, not abstract severity — this project has a 16-day build window (SPEC §11), so a pitfall that silently corrupts the live demo outranks one that is merely "bad practice."

## Critical Pitfalls

### Pitfall 1: Scopus sync runs inside the web request and dies on a PHP/IIS timeout

**What goes wrong:**
Admin clicks "sync" (SPEC §4.1, §8 `admin/index.php`), the PHP script calls the Scopus API for ~800+ publications with up to 3 retries at 5s/15s/45s backoff per failure (SPEC §6.1). PHP's `max_execution_time` (default 30s) and IIS/FastCGI's `activityTimeout`/`requestTimeout` (commonly 70-90s) both expire long before a full sync with retries finishes. The request is killed mid-write, the browser shows a timeout error, and `sync_log` never gets a terminal status — the sync appears to "hang."

**Why it happens:**
The natural first implementation is "handle the POST, loop over API calls, write to MySQL, redirect when done" — because the SPEC describes sync as something Admin "commands" (สั่งซิงค์), it's easy to conflate "user-triggered" with "runs synchronously in the request."

**How to avoid:**
The web request should only *enqueue* the sync (acquire the lock, write a `sync_log` row with status `running`, kick off a detached background process or scheduled task) and return immediately. The actual Scopus fetch/write loop runs as a separate PHP CLI process (`php sync.php` via `proc_open`/Windows Task Scheduler/a polling worker), independent of any HTTP request lifetime. The admin page polls `sync_log` for status.

**Warning signs:**
- Sync "succeeds" for a handful of test records locally but times out once real data volume (~800 publications) is used.
- `sync_log` rows stuck in `running` with no `failed`/`success` transition after a browser timeout.
- IIS logs show `HTTP 500.13`/`502.3` or worker-process recycle events during sync testing.

**Phase to address:** Phase A — Schema & Scopus Sync Engine (SPEC §6.1, §11 "20-23 ส.ค.").

---

### Pitfall 2: "Clear and reload" sync breaks the read-availability guarantee

**What goes wrong:**
SPEC §4.2/Availability requires public pages to stay readable throughout a sync. The straightforward sync implementation — `TRUNCATE publications; INSERT ...` or `DELETE FROM publications WHERE ...` followed by re-insert — leaves a window where dashboard/search/reports queries see an empty or partially-populated table. If tables happen to be MyISAM (plausible on an inherited university PHP/MySQL stack rather than a green-field one), `INSERT`/`DELETE` take table-level locks that block readers outright rather than just showing stale data.

**Why it happens:**
"Re-sync recomputes everything and overwrites" is explicitly the stated model for SDG mapping (SPEC §4.1/§13 item 9) and the mental model bleeds over to publications/researchers sync in general, where it is far more damaging because those tables back every public page.

**How to avoid:**
1. Confirm all application tables are **InnoDB** (row-level locking, no read-blocking on write) before writing any sync code — do not assume it from an inherited system.
2. Never delete-then-insert on live tables. Upsert by natural key (`Scopus Author ID`, `DOI`/Scopus EID) using `INSERT ... ON DUPLICATE KEY UPDATE`, or build into a shadow/staging table and swap with `RENAME TABLE live TO old, staging TO live` (atomic in MySQL/InnoDB) for anything that must be a full rebuild (e.g. SDG mapping, summary/report tables).
3. This directly resolves the SDG "recompute and overwrite" default (SPEC §13 item 9) as well — recompute into `publication_sdgs_staging`, swap, don't `DELETE`+`INSERT` into the live table mid-read.

**Warning signs:**
- Dashboard/reports pages return zero rows or an error for a few seconds during manual sync testing.
- Storage engine check (`SHOW TABLE STATUS`) shows MyISAM anywhere in the schema.

**Phase to address:** Phase A — Schema & Scopus Sync Engine (verify engine + upsert strategy before first sync code is written); reinforced in Phase B for SDG re-sync.

---

### Pitfall 3: Sync mutex is a check-then-set race with no recovery path

**What goes wrong:**
SPEC §4.1/§14.2 requires rejecting a second sync trigger while one is running ("มีการซิงค์กำลังทำงานอยู่"). The obvious implementation — `SELECT status FROM sync_log ORDER BY id DESC LIMIT 1; if not running, INSERT a new running row` — is a classic TOCTOU race: two near-simultaneous admin clicks (or a user double-clicking the button) can both pass the check before either writes. Worse, if the sync process crashes or the server restarts mid-sync (very possible given Pitfall 1), the lock row is left in `running` forever, permanently blocking all future syncs with no documented way to clear it.

**Why it happens:**
A `sync_log` table with a `status` column looks like a lock, but a status column read-then-written in two steps is not atomic.

**How to avoid:**
Use an atomic acquisition primitive: MySQL `GET_LOCK('scopus_sync', 0)` (returns immediately, non-blocking) or an `INSERT ... ON DUPLICATE KEY UPDATE` against a single-row lock table with a `WHERE status != 'running'` guard, checking affected-row count to know if the lock was actually acquired. Add a staleness/heartbeat check (e.g. lock older than N minutes with no progress update is considered abandoned and can be force-cleared), and give Admin a visible "force unlock" action for the demo day specifically — a stuck lock on presentation day with no UI recovery is a realistic failure mode.

**Warning signs:**
- Manually killing the sync process (simulating a crash) leaves the admin panel permanently reporting "sync in progress."
- Load-testing the sync button with 2 rapid clicks results in two `running` rows or a duplicate sync.

**Phase to address:** Phase A (lock mechanism) + Phase D — Admin SSO & CI/CD (recovery UI), tested explicitly in Phase E per SPEC §14.2.

---

### Pitfall 4: The Scopus SDG relevance-weight field may not exist the way the spec assumes

**What goes wrong:**
SPEC §4.1/§7/§13 item 7 designs the entire SDG feature around a per-publication, per-SDG **float weight (0.0–1.0)** returned directly by the Scopus API, from which the system picks the top-2 and tie-breaks deterministically. Public research on Elsevier's SDG mapping methodology indicates SDG-to-document mapping is produced by a search-query + ML/TF-IDF matching pipeline over Title/Abstract/Author-Keywords/Indexed-Terms/Subject-Areas, published as periodic bulk dataset releases (2023, 2025 mappings) — there is **no confirmed, generally-documented Scopus API field** that returns a raw numeric relevance score per SDG per document. (Confidence: **LOW** — this is exactly the kind of thing that can only be confirmed by making a live API call with the project's actual entitlement/key, which is why SPEC §12 already lists it as an open question.)

**Why it happens:**
The SPEC's own default was written before verifying the field against a real API response ("ต้องตรวจสอบกับ field จริงที่ Scopus API ให้มา อาจเป็นคนละ scale" — SPEC §4.1 note), but downstream design (schema `weight`/`rank` columns, tie-break rule, report tab, 4 test-checklist items in §14.3) was built as if the field is already confirmed.

**How to avoid:**
Before Phase B starts (ideally during the 18-19 Aug open-questions window in SPEC §11), make one real API call for a known publication and dump the raw JSON response looking for any SDG-related element. Two branches:
- **If a numeric per-SDG score exists** in the response → proceed with SPEC §4.1 as designed.
- **If Scopus only returns a tag/list of SDGs (or nothing) without a numeric score**, or the field lives in a different product (e.g. only in the Scopus **web UI**/author profile, not the Search/Abstract Retrieval API this project has access to) → fall back to **local classification**: compute the "weight" in-house using Elsevier's published SDG keyword/query sets (title/abstract/keyword matching, e.g. TF-IDF against the public SDG keyword lists) so the `publication_sdgs.weight`/`rank` schema and tie-break rule stay valid, just with the system itself as the score producer instead of Scopus.

Either branch is buildable in the timeline — the risk is discovering this on 24 Aug (when SDG work is scheduled, SPEC §11) with two days of runway left instead of on day 1.

**Warning signs:**
- The Scopus field the team expected to hold a 0.0-1.0 weight is actually a boolean/tag, a differently-scaled score, or simply absent for most records.
- SDG mapping code is written against assumed field names before a real sample response has been inspected.

**Phase to address:** Verification spike before/at start of Phase B — SDG Mapping & Dashboard (SPEC §11 "24-26 ส.ค."); this is the single highest-leverage open question to close first (SPEC §12).

---

### Pitfall 5: Many-to-many join fan-out silently inflates dashboard/report numbers

**What goes wrong:**
`publications` joins to `publication_authors` (many rows per publication) and to `publication_sdgs` (up to 2 rows per publication) — SPEC §7. A single query that joins publications → publication_authors → researchers and then does `SUM(citations)` or `COUNT(*)` multiplies each publication's citation count by its number of co-authors; add `publication_sdgs` into the same join and citations get multiplied again by up to 2. This is the textbook SQL "fan trap," and it will produce dashboard totals (SPEC §4.1 "จำนวน citations รวม") that are silently wrong — not erroring, just wrong, which is worse for a demo.

**Why it happens:**
It's natural to write one query that joins everything needed for a report tab (department + country + funding + SDG in one pass) because it "looks" more efficient than several queries — but each additional many-side join multiplies the row count the aggregate function sees.

**How to avoid:**
Aggregate each many-to-many relation independently at its own grain (subquery/CTE), then join the already-aggregated results together — never run `SUM`/`COUNT(*)` directly over a query that already joined through more than one many-side table. Concretely: total citations = `SELECT SUM(citations) FROM publications` (no author/SDG join at all); per-department publication counts = aggregate over `publication_authors JOIN researchers` at the department grain, independent of the SDG join. Note the inverse case that is **intentional, not a bug**: SPEC §4.1/§13 items 5-6 explicitly want multi-department and multi-country co-authored works to be double-counted per department/country — so per-department/per-country totals will *not* sum to the fact-level total, and report labels must say "publications involving department X," never present an additive grand total across departments/countries that implies no overlap.

**Warning signs:**
- Dashboard total citations don't match `SELECT SUM(citations) FROM publications` run in isolation.
- A publication with 3 co-authors from the same department shows up 3x too much weight in per-department stats when it should show up once per *distinct* department, not once per author.

**Phase to address:** Phase B (dashboard) and Phase C — Search, Researcher Directory & Reports (SPEC §11 "27-29 ส.ค."), verified against SPEC §14.4 checklist.

---

### Pitfall 6: Spec's "cancel whole batch on failure" contradicts the schema's own `partial` status

**What goes wrong:**
SPEC §6.1 says: on repeated timeout/429 failure, "ยกเลิก sync รอบนั้นทั้งหมด (ไม่บันทึกข้อมูลบางส่วน)" — cancel the entire sync, don't save partial data. But the `sync_log` schema (SPEC §7) has a `partial` status value, and the incomplete-record handling rule in the same section (§6.1) says to *skip individual bad records but keep going* rather than aborting. If sync writes records to the live tables as it goes (the natural implementation) and then aborts on a later API failure, the live tables already contain a partial sync's worth of data — contradicting "no partial data saved" — while `sync_log` has nowhere to honestly record that state except the `partial` status the top-level rule says shouldn't exist.

**Why it happens:**
Two different rules were written for two different failure modes (API-level failure = abort-all; record-level incompleteness = skip-and-continue) without reconciling what happens to already-written rows when an API-level failure occurs *after* some records have already succeeded.

**How to avoid:**
Resolve this at design time, not discovery time: write all fetched/transformed data into a **staging table** during the sync run; only on full success, atomically swap staging into live (ties directly into Pitfall 2's swap pattern). On an API-level abort, the staging table is simply discarded and the live tables are untouched — genuinely "no partial data saved." Reserve the `partial` status for the record-skip case (some individual records skipped, but the overall batch still completed and was swapped in) — that's the only case where "partial" is an honest, useful status distinct from "success" and "failed."

**Warning signs:**
- Ambiguity during implementation about whether `sync_log.status = 'partial'` should ever correspond to live-table data or not.
- Live tables show a different publication count than the last "success" sync_log's committed count.

**Phase to address:** Phase A, resolved before sync error-handling code is written (SPEC §6.1, §14.1).

---

### Pitfall 7: Development/testing burns the production Scopus quota before the demo

**What goes wrong:**
Scopus enforces roughly 20,000 requests per 7 days per API key, reset weekly from first use. A single full sync of ~800 publications plus per-author lookups can consume a meaningful chunk of that; repeatedly re-running full syncs during development/debugging (which is exactly what happens when iterating on SDG mapping and error-handling logic in a 16-day sprint) can exhaust the week's quota days before the 3 Sep presentation, leaving no working key for the live demo or for last-minute bug fixes.

**Why it happens:**
There's no visible cost to calling the API in dev the way there would be with, e.g., a metered paid service with a bill — the constraint (quota) is invisible until it's hit, usually at the worst time.

**How to avoid:**
On the first successful sync against real data, capture and store the raw Scopus JSON responses as fixtures. Do all subsequent development/testing (error handling, SDG mapping logic, pagination, report aggregation) against those fixtures rather than live API calls. Reserve live API calls for scheduled, deliberate full-sync tests — check the `X-RateLimit-Remaining` response header before any deliberate full sync and log it.

**Warning signs:**
- `X-RateLimit-Remaining` header dropping fast during the build week with no corresponding deliberate "real sync" milestone.
- Getting a 429 during final rehearsal on 1-2 Sep with no fixture-based fallback to demo against.

**Phase to address:** Phase A, established as a practice from the very first successful sync call.

---

### Pitfall 8: Custom SSO handshake has no CSRF/state binding or replay protection

**What goes wrong:**
The MEDSCI ACC handshake as specified (SPEC §9) is: redirect to `msc_acc/sso/login.php` with `client_id`+`redirect_uri` → user logs in → redirected back with `?token=...` → server POSTs to `verify.php`. Nothing in this flow binds the returned token to a session *this app* actually initiated (no `state`/nonce parameter mentioned), and the token-reuse/replay policy is explicitly unconfirmed (SPEC §12 "Token จาก MEDSCI ACC มีอายุเท่าไหร่... ป้องกัน token replay"). Without a bound state value, an attacker can potentially initiate their own login and trick a victim into completing it under the attacker's session (login CSRF), and without single-use enforcement on the token, a captured `?token=...` value (e.g. from browser history, a referrer leak, or a shared proxy log) could be replayed by the verifying server if MEDSCI ACC's own `verify.php` doesn't already reject reused tokens — something this project's team does not control and hasn't gotten a confirmed answer on.

**Why it happens:**
The redirect+server-verify pattern *looks* equivalent to standard OAuth/OIDC, but standard libraries add `state`/`nonce` handling automatically; a proprietary, hand-rolled Method 1 integration (explicitly not a standard OAuth/SAML/CAS library per this project's constraints) has to add that binding manually, and it's easy to implement "just the happy path" from the vendor's SSO guide, whose sample code is already known to be unsafe in at least one other place (`CURLOPT_SSL_VERIFYPEER, false` — SPEC §9).

**How to avoid:**
Before redirecting to `msc_acc/sso/login.php`, generate a random, unguessable value; store it server-side bound to the pre-auth session (e.g. a signed cookie or session-stored nonce), and pass/expect it to correlate on return (even if MEDSCI ACC's redirect doesn't itself echo a `state` param, the app can still bind the *return path* to a session it created before redirecting, rejecting any inbound token if no matching pending-login session exists). After successfully verifying a token via `verify.php`, record its hash in a short-lived "consumed tokens" table/cache and reject any second verify attempt with the same token value, regardless of what MEDSCI ACC's own replay policy turns out to be — treat replay protection as this app's responsibility, not something to assume the IdP handles, since the open question in SPEC §12 has not been answered.

**Warning signs:**
- Successfully completing the SSO flow twice with the same `?token=...` URL succeeds both times.
- No pre-redirect session state exists to correlate against when the `redirect_uri` callback is hit.

**Phase to address:** Phase D — Admin SSO & CI/CD (SPEC §9, §11 "30-31 ส.ค."), verified against SPEC §14.6.

---

### Pitfall 9: Self-hosted GitHub Actions runner is a direct path to production compromise

**What goes wrong:**
The chosen CI/CD approach (SPEC §10.1) installs a self-hosted runner as a Windows Service on the production IIS server with write access to the site's physical path. A self-hosted runner is not an ephemeral, isolated sandbox — it typically persists between jobs, so a single compromised workflow run (a malicious/compromised dependency pulled during a build step, a leaked secret, or — if branch protection is ever misconfigured — a PR from an untrusted contributor) has a direct path to writing arbitrary files onto the production web server and reading whatever credentials the runner process can see (DB connection strings, Scopus API key, SSO client_secret if any of those are accessible from the runner's environment).

**Why it happens:**
SPEC §10.1 chose this approach specifically because IIS isn't reachable inbound from GitHub cloud runners, which is a legitimate and reasonable constraint — but "self-hosted runner with write access to prod" is treated as a plumbing decision rather than a security-critical one requiring the same rigor as the SSO integration.

**How to avoid:**
The mitigations SPEC §10.1 already lists (branch protection with required review, no workflows from forks/external PRs, GitHub Secrets for credentials) are necessary but not sufficient on their own. Additionally: keep the default `GITHUB_TOKEN` permission read-only at the repo level and grant only the specific write scope the deploy job needs; since `plugdkt/msc_research` is presumably private with a small trusted collaborator set, explicitly confirm (don't assume) that Actions is configured to require approval for any workflow run triggered by non-collaborators, and that repository visibility stays private for the life of the project; treat the runner host itself as sensitive production infrastructure needing its own patch/monitoring cadence, not just "the web server we already have."

**Warning signs:**
- Any workflow in the repo is triggered by `pull_request` (not `pull_request_target` with review gating) and can run before a maintainer reviews the diff.
- The runner's Windows service account has more filesystem/network access than strictly the webroot + IIS control it needs (e.g. can also read unrelated shares, DB backups, or other credential stores on the same box).

**Phase to address:** Phase D — Admin SSO & CI/CD (SPEC §10.1, §11 "30-31 ส.ค."), verified against SPEC §14.7.

---

### Pitfall 10: Naive file-sync deploy step corrupts or deletes production state

**What goes wrong:**
Two related IIS-specific failure modes: (a) the IIS worker process holds file locks on running application files, so a deploy step that copies new files directly into the live webroot while the site is running can fail mid-copy with "file in use" errors, leaving a mix of old and new files running simultaneously; (b) a deploy script using a mirror-style copy (e.g. `robocopy /MIR`, or any "make destination exactly match source" sync) will delete anything present in the destination but not tracked in the git repo — which includes exactly the config file SPEC §10.1 requires to be generated at deploy time and kept out of the webroot/out of git (DB credentials, Scopus API key, SSO client_secret). A mirror-deploy can wipe that config file (or the entire `App_Data`-equivalent) on every single deploy.

**Why it happens:**
"Sync the repo to the server" is the mental model for the deploy step, but the production server also holds generated, non-repo state (the config file, possibly logs/uploaded assets) that a naive mirror sync doesn't know to preserve.

**How to avoid:**
Follow the release-folder pattern already implied by SPEC §10.1's rollback requirement: deploy each release into a fresh, uniquely-named folder (not in-place over the live one), generate the config file into that release folder from GitHub Secrets as a distinct step, then point IIS at the new release folder (or copy just the application files, explicitly excluding the config path, into the live folder) and recycle the app pool — recycling (or dropping an `app_offline.htm` first) releases file locks cleanly instead of fighting them. Never use an unqualified mirror/sync flag against the live webroot; if a sync tool is used at all, explicitly exclude the config file's path and any other non-repo runtime state.

**Warning signs:**
- A deploy run empties or resets the site's config file, requiring it to be regenerated on every deploy (a sign the exclude rule isn't working) — or worse, requiring it to be manually re-created because the automated generation silently didn't run.
- Deploys occasionally fail with file-locked errors under load testing during Phase E rehearsal.

**Phase to address:** Phase D — Admin SSO & CI/CD (SPEC §10, §11 "30-31 ส.ค."), stress-tested during Phase E per SPEC §14.7 (including the "simulate deploy failure mid-run, confirm rollback" check).

---

## Technical Debt Patterns

Shortcuts that seem reasonable given the 16-day timeline but create long-term (or even short-term demo-day) problems.

| Shortcut | Immediate Benefit | Long-term Cost | When Acceptable |
|----------|-------------------|-----------------|------------------|
| Running Scopus sync synchronously inside the admin HTTP request | Faster to build, no background-job infrastructure | Times out on real data volume (Pitfall 1); breaks the moment record count grows toward the 10,000-publication target (SPEC §4.2) | Never for the real sync — acceptable only as a throwaway local dev script against fixture data |
| Delete-then-reinsert sync into live tables | Simplest possible sync logic | Violates the read-availability NFR mid-sync (Pitfall 2); risk grows with data size | Never on tables the public pages read from; acceptable only for an internal staging table that is swapped in atomically |
| Hardcoding Scopus API key / SSO client_secret in a local `config.php` "just for now, will move to env later" | Gets a working demo faster in week 1 | Exactly the leak risk SPEC §9/§10 explicitly calls out; easy to forget to remove before a commit | Never — set up env/Secrets-based config from day 1, since retrofitting under time pressure is how leaks happen |
| One big joined SQL query per report tab instead of several smaller aggregate queries | Feels more "efficient," fewer round trips | Fan-out inflation (Pitfall 5) that is easy to miss because the query still returns *a* number, just the wrong one | Never for any query involving more than one many-to-many relation; acceptable for single-table or single-join reads |
| Skipping the `state`/nonce binding in the SSO flow "since it's an internal system anyway" | One less thing to build before 30 Aug | Login-CSRF and replay exposure on the one endpoint that has write-adjacent power (triggering sync) — SPEC §9's own security section already sets a high bar | Never — this is explicitly a security requirement area per SPEC §9, not a nice-to-have |
| Storing generated report numbers only in-memory per request, recomputing every page view | No extra tables/cache invalidation logic to write | Reports NFR (≤3s, SPEC §4.2) gets harder to hit as data approaches 10,000 publications; recomputing on every view also multiplies fan-out risk exposure | Acceptable at current scale (~800 publications) as an MVP; should be replaced with precomputed summary tables before/at the point scale testing targets (10,000 publications) are exercised |

## Integration Gotchas

| Integration | Common Mistake | Correct Approach |
|-------------|-----------------|-------------------|
| Scopus API | Assuming every field (DOI, full author names, affiliation, SDG data) is always present and well-formed | Treat every Scopus field as optionally null/malformed; the skip-incomplete-record rule (SPEC §6.1) should be the default posture for *any* field, not just the documented DOI/author-null cases |
| Scopus API | Treating a "duplicate Scopus Author ID" rejection (SPEC §6.1) as the only author-identity failure mode | Also watch for the inverse: one real researcher accidentally represented by two *different* Scopus Author IDs (a known Scopus data-quality pattern), which silently halves that person's publication/citation/h-index counts instead of throwing any error — no automated check catches this; flag researchers with implausibly low totals for manual review |
| Scopus API (SDG data) | Building the `publication_sdgs.weight`/`rank` pipeline against an assumed field name before confirming it exists in a real API response | Make one live API call and inspect the raw JSON before writing any SDG-mapping code (see Pitfall 4) |
| MEDSCI ACC SSO | Copying the vendor's example `verify.php`-calling code as-is | The vendor's own sample code disables TLS verification (`CURLOPT_SSL_VERIFYPEER, false` — SPEC §9); audit every line of vendor sample code before use, don't just adapt variable names |
| MEDSCI ACC SSO doc (`sso_integration_guide.md`) | Assuming `.gitignore` alone prevents the Developer Bypass credential from ever reaching the repo | `.gitignore` only stops *future* accidental adds — if the file was ever committed before the ignore rule was added, it is still in git history. Run `git log --all --full-history -- sso_integration_guide.md` (and check any forks/clones) to confirm it never entered history; if it's on the web server for reference, ensure IIS/`web.config` denies HTTP access to any `.md` file in or near the webroot |
| Self-hosted GitHub Actions runner | Assuming branch protection on `main` alone fully contains risk | Branch protection stops accidental bad merges; it does not stop a compromised dependency inside an approved PR's build step from running arbitrary code on the runner. Pin/audit build dependencies too |

## Performance Traps

| Trap | Symptoms | Prevention | When It Breaks |
|------|----------|------------|-----------------|
| Live join-and-aggregate on every report page view (no precomputation) | Reports page (SPEC §4.1, `reports.php`) creeps toward/past the 3s budget as more tabs are added | Precompute per-tab summary tables refreshed at sync-completion (or on a schedule), read-only queries hit small pre-aggregated tables at request time | Almost certainly by the 10,000-publication/500-researcher scale target (SPEC §4.2); may already be borderline at current scale once all report tabs are implemented together |
| Many-to-many fan-out in aggregate queries (see Pitfall 5) | Dashboard/report totals don't match a simple `SUM`/`COUNT` run in isolation on the same table | Aggregate each many-side relation independently before joining results | Breaks correctness (not just speed) at any scale — a single co-authored paper is enough to demonstrate it |
| Missing indexes on filter/sort columns used by search & directory pages | `publications_search.php`/`researchers_list.php` (SPEC §8) slow down noticeably as row count grows, especially with `LIKE '%keyword%'` partial-match search | Index `department`, `type`, `is_active`, `year`, join keys; note that a leading-wildcard `LIKE %term%` cannot use a standard B-tree index for the search term itself — accept a full scan on `publications`/`researchers` at current scale (~800/~100 rows) but re-benchmark specifically this query at the 10,000/500 target, since it's the one query class that indexing can't fully fix | May become the slowest single query type well before other pages do, because it can't be solved purely with indexes |
| Pagination implemented as "fetch everything, slice in PHP" | Works fine at ~800 publications, silently fine until it isn't | Use `LIMIT`/`OFFSET` (or keyset pagination) in SQL, never fetch full result sets into PHP arrays for slicing | Becomes a real memory/latency issue well before 10,000 publications if every search hit is materialized fully in PHP first |

## Security Mistakes

Domain-specific issues beyond generic OWASP basics, given this project's stated security constraints (SPEC §9).

| Mistake | Risk | Prevention |
|---------|------|------------|
| Trusting client-side-decoded token data instead of the server-verified `verify.php` response | Forged/tampered "logged in as admin" state | Only ever set session/auth state from the server-side `verify.php` HTTPS response body, never from anything read out of the URL/client on the frontend (SPEC §9 already mandates this — treat any deviation as a regression) |
| No CSRF/state binding on the SSO redirect (Pitfall 8) | Login-CSRF; a captured token URL is a valid, replayable credential | Bind a server-side pre-login state token to the outbound redirect; single-use enforcement on consumed tokens |
| Config file reachable via direct URL guess (e.g. `/config.php`, `/.env`) | Full credential disclosure (DB, Scopus API key, SSO client_secret) in one request | Verify config lives outside the IIS-served physical path, or is blocked via `web.config` `<requestFiltering>`/deny rule if it must be inside webroot (SPEC §10.1); explicitly test this in Phase E (SPEC §14.7) |
| `sso_integration_guide.md` present anywhere reachable by IIS or git history | Developer Bypass credential leak affecting every MEDSCI ACC-connected system, not just this project | See Integration Gotchas above — check git history, not just `.gitignore` going forward; confirm it's not physically present under the webroot at all |
| Assuming MEDSCI ACC's `verify.php` fully handles token replay | Replayed tokens accepted because this app never checked, and the IdP's actual replay policy is an unanswered open question (SPEC §12) | Implement replay protection (consumed-token tracking) in this app regardless of what the IdP does — don't wait for the open question to be answered before shipping this |
| DB user with more than SELECT/INSERT/UPDATE on production (SPEC §9 least-privilege requirement) | A SQL-injection-class bug or a compromised app process could `DROP`/`ALTER` production tables | Enforce least-privilege DB accounts from the first schema migration, not retrofitted later; separate migration-capable credentials from the app's runtime credentials |

## UX Pitfalls

| Pitfall | User Impact | Better Approach |
|---------|-------------|-------------------|
| Per-department/per-country report totals presented alongside an unlabeled grand "Total" row | Numbers look inconsistent/"wrong" to end users (department sums exceed the faculty total, by design per SPEC §13 items 5-6) — administrators reviewing the report may assume a bug | Label these views explicitly as counting co-authored works in every department/country they touch (e.g. "publications involving [department]"), and avoid an additive grand-total row that implies non-overlap |
| Inactive researchers (`is_active=false`) simply absent from the directory with no explanation | A visitor searching for a known former staff member finds nothing and may think the system is broken/incomplete, especially since that person's works still show up in search results and reports | Consider whether inactive researchers should still be reachable indirectly (e.g. their name still appears as an author on a publication detail page) even though they're excluded from the browsable directory list — SPEC already accepts this asymmetry (§13 item 4), just make sure the publication-detail-page author link doesn't silently 404 |
| Zero-publication researchers excluded from the directory by an accidental `INNER JOIN` | A newly added researcher with no synced publications yet vanishes from the directory entirely, which looks like a data-entry failure rather than "hasn't published yet" | Directory query must `LEFT JOIN` researchers to publications (SPEC §14.4 explicitly tests for this) so a 0-publication researcher still appears with 0s |
| Sync-in-progress state invisible to public-page visitors | If sync is slow/fails, a visitor has no way to know the data they're seeing might be stale/mid-update, even though SPEC only requires the *data* stay available, not that staleness be communicated | Surface "last synced at" timestamp (already required on the admin side per SPEC §4.1) somewhere on public pages too, so visitors aren't left guessing whether numbers are current |

## "Looks Done But Isn't" Checklist

- [ ] **Scopus sync:** Often looks done after a successful run against a small manual test batch — verify it survives the *full* real dataset volume (~800 publications) end-to-end without a request timeout (Pitfall 1), and that killing the process mid-run doesn't leave a permanently stuck lock (Pitfall 3).
- [ ] **SDG mapping:** Often looks done once the top-2/tie-break logic passes unit tests against hand-crafted fixture weights — verify the weight actually originates from a real, confirmed Scopus API field (Pitfall 4), not just that the *logic* is correct given assumed inputs.
- [ ] **Report tabs (department/country/funding/SDG):** Often look done once each tab renders a plausible-looking number — verify each aggregate independently against a hand-computed expected value on a small known dataset to rule out fan-out inflation (Pitfall 5), especially for any tab whose query joins more than one many-to-many table.
- [ ] **SSO admin login:** Often looks done once a happy-path login redirects successfully and shows the admin panel — verify `CURLOPT_SSL_VERIFYPEER` is `true` in the actual shipped code (not just the intent), that a captured/replayed token URL is rejected the second time, and that there is a state/nonce binding preventing login-CSRF (Pitfall 8; SPEC §14.6 already tests part of this).
- [ ] **CI/CD deploy pipeline:** Often looks done once a manual push-and-deploy succeeds once — verify a mid-deploy failure genuinely triggers rollback to the previous release (SPEC §14.7), and that a full deploy cycle doesn't wipe the runtime config file (Pitfall 10).
- [ ] **Performance budgets:** Often look done when measured against the current ~800-publication dataset — verify against a synthetically scaled-up dataset (toward the stated 10,000-publication target, SPEC §4.2) before declaring the ≤2s/≤3s budgets met, since fan-out and missing-precomputation issues (Pitfalls 5, and the Performance Traps table) often only appear at higher row counts.
- [ ] **Directory pagination/sorting:** Often looks done with a small researcher list where every page shows results — verify with a 0-publication researcher and with more than one page's worth of results that both edge cases (SPEC §14.4, §14.5) behave as specified, not just the common case.

## Recovery Strategies

When pitfalls occur despite prevention, how to recover without derailing the 3 Sep timeline.

| Pitfall | Recovery Cost | Recovery Steps |
|---------|---------------|------------------|
| Sync mutex stuck in `running` (Pitfall 3) | LOW | Provide a documented manual SQL/admin-UI unlock (`UPDATE sync_log SET status='failed' WHERE status='running'` or `SELECT RELEASE_LOCK(...)`) as a day-one fallback even before building the polished "force unlock" UI |
| SDG weight field doesn't exist as assumed (Pitfall 4), discovered late | MEDIUM | Fall back to local title/abstract keyword-matching against Elsevier's published SDG query sets to produce a locally-computed weight; the downstream schema/tie-break logic doesn't need to change, only the score's source |
| Fan-out-inflated numbers already shipped/demoed once (Pitfall 5) | LOW | Because nothing is destructive, this is a query-only fix: rewrite the specific aggregate query to pre-aggregate the many-side relation, no data migration needed |
| Config file wiped by a mirror-deploy (Pitfall 10) | LOW–MEDIUM | Keep the last-known-good config file backed up outside the deploy pipeline (e.g. in the Secrets-driven generation step, or a manually-held copy) so it can be immediately restored/regenerated without re-deriving values from scratch under time pressure |
| Scopus quota exhausted mid-week (Pitfall 7) | MEDIUM | Switch remaining development/testing to captured fixtures immediately; if a live demo sync is at risk, coordinate the timing of the next quota reset (7 days from first use) against the 3 Sep date well in advance rather than discovering the conflict late |
| `sso_integration_guide.md` found in git history after the fact | HIGH | Treat as a credential-leak incident: rotate/replace the Developer Bypass credential with MEDSCI ACC's owning team immediately (this affects every system integrated with MEDSCI ACC, not just this project), then scrub git history (e.g. filter-repo) as a secondary, less urgent step |

## Pitfall-to-Phase Mapping

Phases below follow the thematic work blocks implied by SPEC §11's timeline (no numbered roadmap phases exist yet — this table is written to seed that structure).

| Pitfall | Prevention Phase | Verification |
|---------|-------------------|---------------|
| 1. Sync runs in-request, times out | Phase A — Schema & Scopus Sync Engine | Full sync against real ~800-publication volume completes without a browser/IIS timeout; sync survives a closed browser tab |
| 2. Delete-then-reload breaks read availability | Phase A — Schema & Scopus Sync Engine | Public pages queried continuously during a live sync show no errors/empty results (SPEC §14.8 last item) |
| 3. Sync mutex race / stuck lock | Phase A (mechanism) + Phase D (recovery UI) | Double-click sync trigger produces exactly one running sync (SPEC §14.2); simulated crash leaves a recoverable, not permanent, lock state |
| 4. Unverified SDG weight field | Verification spike before Phase B — SDG Mapping & Dashboard | A real Scopus API response has been inspected and the weight source (Scopus field vs. local computation) is documented as a decision, not an assumption |
| 5. Join fan-out inflates aggregates | Phase B (dashboard) + Phase C — Reports | Every report/dashboard aggregate matches a hand-computed value on a small known test dataset (SPEC §14.4) |
| 6. §6.1 partial-write contradiction | Phase A — Schema & Scopus Sync Engine | `sync_log` status transitions and live-table state are consistent under every documented failure mode in SPEC §14.1 |
| 7. Dev/test quota exhaustion | Phase A, ongoing practice | Fixtures exist and are used for all non-final-integration testing; `X-RateLimit-Remaining` checked before deliberate full syncs |
| 8. SSO CSRF/replay gaps | Phase D — Admin SSO & CI/CD | Replayed token URL rejected on second use; login flow cannot be completed without a matching pre-redirect session state (extends SPEC §14.6) |
| 9. Self-hosted runner compromise path | Phase D — Admin SSO & CI/CD | Branch protection + required review confirmed active; `GITHUB_TOKEN` default permissions confirmed read-only; repo confirmed private (SPEC §14.7) |
| 10. Deploy wipes/locks production files | Phase D — Admin SSO & CI/CD | Config file survives a full deploy cycle unchanged in content; simulated mid-deploy failure triggers automatic rollback (SPEC §14.7) |

## Sources

Web search only (no curated/official-docs MCP provider was configured in this environment); each finding below was cross-checked across 2+ independent sources during research and cached at MEDIUM confidence via the research-store, except the SDG API-field claim which is explicitly LOW confidence and framed as a verification task rather than a fact:

- Scopus API rate limits/quota: Elsevier Support ("What are the quotas and throttling rates for the Research Products APIs?"), kth-library kthcorpus docs, pybliometrics documentation, QUTlib citation-import GitHub issue
- Scopus data-quality/affiliation gaps: arXiv "Red alert: Millions of homeless publications in Scopus should be resettled" (2025), arXiv affiliation-discrepancy study, pybliometrics-dev GitHub issue #139
- Elsevier SDG mapping methodology: Elsevier Scopus Blog ("2023 Sustainable Development Goals now available on Scopus"), Elsevier Digital Commons Data SDG mapping dataset pages, EdUHK library guide on SDG keyword mapping
- Custom/proprietary SSO and OAuth-pattern vulnerabilities: SSOJet/Security Boulevard "Common SSO Vulnerabilities and Mitigations," CyberReplay "When SSO Goes Wrong," ZeriFlow "OAuth Security Vulnerabilities," arXiv "Mitigating CSRF attacks on OAuth 2.0 and OpenID Connect," arXiv "Second-Order Vulnerabilities in OpenID Connect"
- Self-hosted GitHub Actions runner risk: Wiz "Hardening GitHub Actions: Lessons from Recent Attacks," Sysdig "How threat actors are using self-hosted GitHub Actions runners as backdoors," GitHub Docs "Securely using pull_request_target," DevSecOpsAtlas self-hosted runner security guide
- IIS deploy/file-lock behavior: Octopus Deploy docs on IIS websites/app pools, Rick Strahl's Weblog on WebDeploy locking/shadow copy, LeanSentry "How to correctly reset, restart, and recycle IIS websites"
- SQL fan-out/many-to-many aggregation: Medium "SQL Fanout: The Hidden Trap in Joins and Aggregates," DZone "The Dangers of SQL JOINs & Aggregate Functions," "The Fan Trap: Why Your SQL Joins Are Inflating Your Numbers"
- Dashboard/reporting performance: Leapcell "Speeding Up Complex Analytics with Materialized Views," BoldBI "Materialized Views in MySQL: Optimizing Dashboard Performance," Metabase Learn "Making dashboards faster"
- Project-specific pitfalls (PHP/IIS timeout interaction, sync-vs-availability, mutex race, fan-out on this exact schema, §6.1/schema contradiction, SSO state binding, robocopy/config-wipe risk, git-history leak persistence, split Scopus IDs, encoding, INNER JOIN exclusion): derived directly from cross-referencing SPEC.md sections 4.1, 4.2, 6.1, 7, 9, 10, 10.1, 13, 14 against the general findings above — not independently web-sourced, since these are specific to this project's exact spec combination

---
*Pitfalls research for: Scopus-based research publication repository (medical sciences faculty), PHP/MySQL/IIS/custom-SSO/self-hosted-runner stack*
*Researched: 2026-08-18*
