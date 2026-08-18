# Feature Research

**Domain:** University/faculty research-output repository + bibliometric analytics dashboard (public read-only portal, admin sync-only), benchmarked against CRIS products (Elsevier Pure, Symplectic Elements, VIVO) but scoped as a **public bibliometric portal**, not a full CRIS
**Researched:** 2026-08-18
**Confidence:** MEDIUM overall — HIGH for what "table stakes" means structurally, MEDIUM for CRIS feature comparisons (corroborated across official vendor pages), LOW/unverified for two Scopus-data-shape questions called out explicitly below (quartile source, SDG weight field)

## Framing Note (read before using this file)

Elsevier Pure, Symplectic Elements, and VIVO are **CRIS** (Current Research Information System) products. Their table stakes include self-service profile editing, deposit/claim-reject workflows, open-access compliance tracking, and grant-lifecycle management — all things this project explicitly rules out (PROJECT.md Out of Scope: no manual entry/edit; Admin can only trigger sync). If those CRIS table stakes were imported wholesale into this file, nearly everything would land as P1 and the roadmap would get no prioritization signal.

This file instead defines table stakes as **what a visitor to a faculty's public research portal expects to see** (dashboard, search, directory, reports), and explicitly demotes CRIS-only capabilities to Anti-Features with their vendor provenance named, so scope creep toward "let's add what Pure does" can be caught early.

## Feature Landscape

### Table Stakes (Users Expect These)

| Feature | Why Expected | Complexity | Notes | Spec/Project Ref |
|---------|--------------|------------|-------|-------------------|
| Public dashboard: total researchers/publications/citations, quartile mix, top researchers by output/citations/h-index, yearly stats, recent publications | Baseline "what does this faculty produce" summary — every CRIS/portal leads with this | MEDIUM | Single-page aggregate queries over local tables only; must precompute/cache to hit ≤2s target, not live-aggregate on every request | PROJECT.md Active #2, SPEC §4.1 |
| Publication search by title + author, partial match, paginated (20/page) | Minimum expected way to find a specific paper; every bibliographic system has this | LOW–MEDIUM | `LIKE %term%` is fine at current scale (~800 pubs); at 10,000+ pubs, un-indexed LIKE scans will violate the 2x-load performance budget — needs a FULLTEXT index or equivalent before scale-up, not before launch | PROJECT.md Active #3, SPEC §4.1, §8 |
| Quartile filter on search | Quartile (Q1–Q4) is the de-facto quality signal researchers/admins scan for first | LOW (once quartile data exists) | Depends on quartile being present per publication — see "Verification-Required" below; if quartile is a journal-year property requiring a separate lookup, this filter needs a `journals` table joined in, not just a `publications` column | SPEC §4.1, §7 |
| Researcher directory filtered by department + staff type, sortable by output/citations/h-index (default desc, toggle asc), paginated | Standard "who works here" faculty page; sorting by impact metric is expected once metrics exist | MEDIUM | Directory data (Thai/English name, department, staff type, `is_active`) is **not derivable from Scopus** — see Verification-Required #2, this is a real dependency gap, not a nitpick | PROJECT.md Active #4, SPEC §4.1 |
| Multi-tab analytical reports: trend, department breakdown, quartiles, international collaboration, funding sources, SDGs, researcher ranking, yearly stats, sources, author roles | Standard CRIS/bibliometric-dashboard offering (Pure, Symplectic, InCites all bundle multi-dimension reporting) | Ranges LOW→HIGH per tab — **do not treat as one feature**, see decomposition below | PROJECT.md Active #5, SPEC §4.1 |
| SDG mapping displayed per publication + aggregated in reports | THE Impact Rankings and Elsevier's own 2023 SDG mapping have made SDG tagging a now-expected research-output dimension in institutional reporting | MEDIUM–HIGH | Top-2-by-weight + tie-break + Unclassified category is well-specified in SPEC, **but the underlying Scopus data shape is unverified** — see Verification-Required #1 | PROJECT.md Active #6, SPEC §4.1 |
| "Last synced" timestamp visible on public pages | Users of any synced/cached dataset expect to know data freshness, especially since sync can fail/partial | LOW | Trivial: expose `sync_log.completed_at` (or last-success) on every public page footer | SPEC §4.1 (Admin section), §7 |
| Admin sync trigger behind SSO, with sync lock (no concurrent syncs) and audit log (`triggered_by`) | Any admin-controlled external-data-pull feature needs to prevent overlapping runs and record who/when for accountability | MEDIUM | Lock via DB row/mutex + HTTP 409 on concurrent attempt; audit log is a straightforward insert — already well-specified in SPEC §4.1, §7, §14.2 | PROJECT.md Active #7, SPEC §4.1 |
| Inactive researchers hidden from directory, historical output still counted | Standard "current roster" pattern — public directories rarely list departed staff, but historical stats must stay accurate for institutional reporting | LOW | Filter `WHERE is_active = true` on directory list only; all aggregate/report queries must NOT filter on `is_active` | PROJECT.md Active #8, SPEC §4.1, §7 |
| Support multiple Scopus Author IDs per researcher | Common real-world failure mode: one person accumulates 2+ Scopus profiles over their career (name variants, affiliation changes) — publications and h-index silently split across them if not merged | LOW–MEDIUM | Not currently in SPEC's data model (`researchers.scopus_author_id` is singular + unique). Add a `researcher_scopus_ids` side table now — cheap; retrofitting after data has accumulated is expensive. SPEC §6.1 only covers the *inverse* case (duplicate ID across two researchers, which is rarer) | Gap — not in current SPEC/PROJECT |

#### Reports tab decomposition (do not plan as a single feature)

The "multi-dimension reports" requirement bundles 10 tabs with very different cost and risk profiles. This split should drive phase-ordering:

| Tab | Data source | Complexity | Risk |
|-----|-------------|------------|------|
| Trend overview | Local `publications` GROUP BY year | LOW | None — pure local aggregate |
| Yearly stats (count + citations + avg/researcher) | Local aggregate | LOW | None |
| Department breakdown | Local `publication_authors` join `researchers.department` | LOW–MEDIUM | Depends on department master data existing (see Verification-Required #2) |
| Quartile summary | Local `publications.quartile` | LOW | Depends on quartile field being reliably populated (see Verification-Required #3) |
| Researcher ranking | Local aggregate, reuses directory sort logic | LOW | None — mostly a view on directory data |
| Sources (publication venues) | Local `publications.journal` GROUP BY | LOW | None |
| International collaboration | Requires per-author **affiliation country** parsed from Scopus author/affiliation data | MEDIUM–HIGH | Scopus affiliation-country data is inconsistent in completeness/granularity; full-credit-per-country rule (SPEC default) is easy once countries are extracted, but extraction itself is the hard part |
| Funding sources | Requires Scopus `fund-sponsor`/funding field | MEDIUM–HIGH | This field is well known in bibliometrics to be sparse/inconsistently populated by publishers — expect many "no funding data" publications even when funding existed |
| Author roles | Requires author sequence / corresponding-author flag from Scopus author list | MEDIUM | Needs to be captured into `publication_authors` at sync time; if Scopus doesn't expose a clean "role" field, may need to derive a proxy (first/corresponding/co-author) from author order metadata |
| SDG mapping stats | Requires SDG weight/label field from Scopus | MEDIUM–HIGH | See Verification-Required #1 — the deterministic algorithm is fine, but the input data shape is unconfirmed |

**Recommendation:** build the 6 local-aggregate tabs (trend, yearly stats, department, quartile, ranking, sources) first — they're cheap and have no external-field risk. Gate international collaboration, funding sources, author roles, and SDG stats behind a field-verification spike, since all four depend on Scopus fields whose presence/shape/completeness is not yet confirmed against the actual API response (SPEC §12 already flags this for SDG; the same caution should extend to funding and affiliation-country).

### Differentiators (Competitive Advantage)

| Feature | Value Proposition | Complexity | Notes |
|---------|-------------------|------------|-------|
| SDG mapping tagged per publication (top-2, deterministic tie-break, Unclassified category) | Very few small-faculty repository systems do SDG mapping at all — this aligns with THE Impact Rankings methodology and gives the faculty a differentiated "impact" story for accreditation/ranking submissions | MEDIUM–HIGH | Value is real, but contingent on resolving Verification-Required #1 first — a mis-specified algorithm here produces silently wrong SDG stats that surface in official reports |
| Full public availability during sync (non-blocking sync) | Most small institutional systems either lock reads during sync or show stale/broken pages — guaranteeing read-availability during writes is an explicit differentiator vs. a naive lock-everything implementation | MEDIUM | Achievable with a read-committed isolation strategy / swap-in of newly-synced data rather than in-place row locks during long sync jobs; directly named as Core Value in PROJECT.md |
| Multi-dimension analytics beyond a single "publications list" (department, SDG, collaboration, funding, roles) in one place | Distinguishes from a plain institutional repository (which is usually just a searchable list) — closer to what Pure/Symplectic/InCites offer, but scoped down and free of vendor licensing cost | MEDIUM–HIGH | This is the product's main value proposition per PROJECT.md; execute the low-risk tabs first to bank early wins before tackling the four field-dependent tabs |
| CSV export of current search/report view | Low-cost, high-value for faculty administrators who need numbers for slide decks/accreditation reports (a real, recurring need in this domain) | LOW | Recommended resolution for SPEC §12 Open Question ("export to Excel/PDF?") — plain CSV of the on-screen table is roughly an hour of work; treat as a v1.x add-on, not launch-blocking |

### Anti-Features (Commonly Requested, Often Problematic)

| Feature | Why Requested | Why Problematic | Alternative |
|---------|---------------|------------------|-------------|
| Manual entry/edit of publications or researcher records (CRIS table stakes in Pure/Symplectic) | "What if Scopus is wrong/missing something" | Explicitly out of scope per PROJECT.md/SPEC §3 — introduces data-integrity drift between the system and Scopus, and a whole permissions/audit surface this project has no time to build in 16 days | Fix at the source (Scopus profile correction) and re-sync; Admin panel stays sync-trigger-only |
| Researcher self-service profile claiming/editing (Symplectic Discovery Module pattern) | Researchers want control over how they're represented | Same integrity-drift problem as above, plus adds an entire authentication/authorization surface for non-admin users that SPEC does not define | None needed for v1 — all roles are read-only per SPEC §1 |
| Open-access compliance tracking / deposit workflow (Pure/Symplectic core feature) | "Since we're already tracking publications, why not track OA compliance too" | Requires full-text file handling, licensing/embargo logic, and a compliance workflow — a different product surface entirely, unrelated to the Scopus-sync/dashboard scope | Out of scope; revisit only if a future milestone explicitly targets OA policy compliance |
| Grant/funding lifecycle management (Symplectic feature) | Funding source report tab might tempt "let's also manage grants" | Funding *reporting* (aggregating Scopus's fund-sponsor field) is in scope; funding *management* (tracking active grants, deadlines, budgets) is a completely different system with different data ownership | Keep funding strictly as a read-only report dimension sourced from Scopus, never as an editable grant record |
| Live Scopus API calls on page render | "Always show the freshest data" | Breaks the ≤2s dashboard / ≤3s reports performance budget, burns Scopus API quota on every page view, and makes the public site's uptime dependent on Scopus's uptime — directly conflicts with the Availability requirement | Sync-then-serve: all public pages read only from local MySQL; freshness is communicated via the "last synced" timestamp |
| Scheduled/automatic background sync (cron-triggered) | "Why require someone to click a button" | Not in scope for v1 — SPEC's Admin section is explicitly "sync trigger only," and an unattended scheduled job removes the accountability (`triggered_by`) that the audit-log requirement depends on | Admin manually triggers sync; revisit scheduled sync as a v1.x feature once the manual flow is proven stable |
| PDF report generation/export | "Reports should be presentable for meetings" | PDF layout/pagination work is disproportionately expensive relative to the 16-day timeline — this is exactly the kind of feature that silently eats days | CSV export instead (see Differentiators) — same underlying value (get the numbers out) at a fraction of the cost |
| Cross-database bibliometric aggregation (Web of Science, Google Scholar in addition to Scopus) | "More sources = more complete picture" | Explicitly out of scope per PROJECT.md/SPEC §3; each source has different h-index/citation counts for the same person, which would force a reconciliation problem this project doesn't need to solve | Single source of truth = Scopus only, clearly labeled as such in the UI (e.g., "Scopus h-index") |

## Feature Dependencies

```
Public Dashboard
    └──requires──> Scopus sync (publications, researchers, citations landed in MySQL)
    └──requires──> Quartile data resolved [VERIFICATION REQUIRED]

Publication Search
    └──requires──> Scopus sync
    └──enhanced-by──> Quartile filter (requires quartile data resolved)

Researcher Directory
    └──requires──> Scopus sync (h-index, citations, output count)
    └──requires──> Researcher roster master data (dept, staff type, Thai/English name, is_active)
                       └── NOT derivable from Scopus — see Verification-Required #2

Reports: Trend / Yearly Stats / Sources / Ranking
    └──requires──> Scopus sync only (local aggregates)

Reports: Department Breakdown
    └──requires──> Researcher roster master data (department field)

Reports: Quartile Summary
    └──requires──> Quartile data resolved [VERIFICATION REQUIRED]

Reports: International Collaboration
    └──requires──> Author affiliation-country data from Scopus [field completeness unverified]

Reports: Funding Sources
    └──requires──> Scopus fund-sponsor field [known to be sparse in practice]

Reports: Author Roles
    └──requires──> Author sequence/corresponding-author data captured at sync time into publication_authors

Reports: SDG Stats
    └──requires──> SDG Mapping feature
                       └──requires──> Confirmed Scopus SDG weight/label field [VERIFICATION REQUIRED — highest severity]

Admin Sync Trigger
    └──requires──> SSO auth (MEDSCI ACC, Method 1)
    └──requires──> Sync lock/mutex
    └──enhances──> Audit log (triggered_by)

Multiple Scopus IDs per researcher (gap feature)
    └──enhances──> Researcher Directory accuracy (h-index/citation correctness)
    └──conflicts-with──> SPEC's current unique-Scopus-ID assumption (§6.1, §7) — needs schema addition (researcher_scopus_ids)
```

### Dependency Notes

- **Reports tabs split cleanly into "local-only" and "field-dependent" groups** — this is the single most important dependency fact for roadmap phase ordering. The 6 local-only tabs can ship as soon as core sync exists; the 4 field-dependent tabs need a Scopus-field verification spike first (ideally in the 18–19 Aug "confirm open questions" window already planned in SPEC §11).
- **Researcher Directory and Department Breakdown both depend on data that Scopus does not provide** (Thai name, department, staff type, active/inactive status). PROJECT.md's "no manual entry" rule needs an explicit, named exception here: either (a) a one-time roster import/seed step treated as initial setup rather than ongoing "manual edit," or (b) confirmation that the existing legacy MySQL database already has this roster data and the new system inherits/reads it rather than re-collecting it. This should be resolved before the directory/reports phases are planned, not discovered mid-build.
- **Quartile Summary and Quartile Filter both depend on the same unresolved question**: is quartile stored per-publication already, or does it need to be derived per-journal-per-year via a separate lookup? Resolving this once unblocks three surfaces (dashboard quartile donut, search filter, quartile report tab) simultaneously — worth verifying early for that reason alone.
- **SDG Mapping conflicts with SPEC's current assumption** if Scopus turns out to return only SDG *labels* (no numeric weight) rather than a continuous 0.0–1.0 relevance score per SDG (see Verification-Required #1). This doesn't block building the feature — the fallback tie-break rule described below preserves determinism regardless of which data shape shows up.
- **SDG Mapping enhances Reports: SDG Stats** but is otherwise self-contained — it can be built and tested independently of the reports tabs once the sync pipeline lands publications.

## Verification-Required Items (surfaced by this research, ranked by severity)

These are not yet resolved by this research and should be treated as blocking spikes before their dependent features are planned in detail — not silently assumed away.

1. **[HIGH SEVERITY] SDG weight field may not exist in the form SPEC assumes.** Research on Elsevier's actual SDG methodology (2023 mapping) shows Scopus assigns SDG **labels** via keyword-query-matching plus an ML predictive layer (the "Scopus-SM" approach), not necessarily a continuous per-SDG relevance score exposed as a simple 0.0–1.0 field. SPEC §4.1/§13 assumes a float weight per SDG, picks top-2 by weight, tie-breaks by ascending SDG code. **If the real API only returns a set of assigned SDG labels without weights**, "top-2 by weight" has no input to operate on. Fallback that preserves SPEC's intent without redesigning: take all Scopus-assigned SDG labels for a publication, cap at 2 by ascending SDG code (same tie-break rule, now used as the primary and only ordering rule), fall back to Unclassified if none assigned. Keep `publication_sdgs.weight` nullable so the schema works either way. This is already flagged as SPEC §12 Open Question #1 — this research corroborates it needs to be resolved with an actual API response sample, not assumed.

2. **[HIGH SEVERITY] Researcher roster master data (department, staff type, Thai name, is_active) has no defined source.** Scopus author/affiliation data is institution-level, not sub-department-level, and does not carry Thai personnel classifications (สายวิชาการ/สายสนับสนุน) or an active/inactive employment flag. Three features (Researcher Directory, Department Breakdown report, is_active filtering) all depend on this data existing somewhere. Needs an explicit decision: either a one-time roster seed/import (treated as setup, not as the "manual edit" the project prohibits), or confirmation the legacy database already holds this and the new system just reads it forward.

3. **[MEDIUM SEVERITY] Quartile may be a journal-year property, not a document property.** Quartile (Q1–Q4) is derived from a journal's CiteScore/SJR percentile for a given year, which typically requires a Serial Title / journal-metrics lookup keyed by ISSN rather than being returned directly on a document search/abstract record. SPEC §7 currently models `Scopus quartile` as a plain `publications` column. If it's actually a journal-year lookup, this affects schema (may need a `journals`/`sources` table) and sync logic (an extra API call type) — worth confirming against actual Scopus API responses before the dashboard/search/report quartile features are built.

4. **[LOWER SEVERITY, but worth a schema decision now] Multiple Scopus Author IDs per one researcher.** More common in practice than the duplicate-ID-across-two-researchers case SPEC §6.1 already handles. Recommend adding a `researcher_scopus_ids` (one-to-many) side table at initial schema design time rather than retrofitting after publication/citation data has accumulated under a single ID.

## MVP Definition

### Launch With (v1) — presentation deadline 3 Sept 2569

- [ ] Scopus sync job with specified error handling (retry/backoff, rate-limit handling, skip-bad-records, reject-duplicate-ID) — everything else depends on this
- [ ] Public dashboard (counts, quartile mix, top researchers, yearly stats, recent publications)
- [ ] Publication search (title + author partial match, quartile filter, pagination)
- [ ] Researcher directory (department/staff-type filter, sort by output/citations/h-index, pagination, is_active hiding)
- [ ] Reports — the 6 local-aggregate tabs: trend, yearly stats, department breakdown, quartile summary, researcher ranking, sources
- [ ] SDG mapping with the verified (or fallback) algorithm, displayed per publication + basic SDG stats tab
- [ ] Admin SSO login (MEDSCI ACC Method 1) + sync trigger + sync lock + audit log
- [ ] "Last synced" timestamp on public pages

### Add After Validation (v1.x)

- [ ] Reports — the 4 field-dependent tabs (international collaboration, funding sources, author roles) once the underlying Scopus fields are confirmed populated with usable data
- [ ] CSV export of search results / report tables
- [ ] `researcher_scopus_ids` multi-ID support, if the single-ID assumption proves to cause real split-profile problems in the actual faculty dataset

### Future Consideration (v2+)

- [ ] Scheduled/automatic sync (cron), once manual-trigger flow is proven stable and the faculty is comfortable with unattended runs
- [ ] PDF report generation, if CSV proves insufficient for accreditation/meeting use cases
- [ ] Additional bibliometric sources (Web of Science, Google Scholar) — explicitly deferred per PROJECT.md, would require a source-reconciliation strategy first

## Feature Prioritization Matrix

| Feature | User Value | Implementation Cost | Priority |
|---------|------------|---------------------|----------|
| Scopus sync + error handling | HIGH | HIGH | P1 |
| Public dashboard | HIGH | MEDIUM | P1 |
| Publication search + quartile filter | HIGH | MEDIUM (LOW if quartile already resolved) | P1 |
| Researcher directory | HIGH | MEDIUM (blocked on roster data source) | P1 |
| Reports: 6 local-aggregate tabs | HIGH | MEDIUM | P1 |
| SDG mapping + display | MEDIUM–HIGH | MEDIUM–HIGH (pending field verification) | P1 |
| Admin sync trigger + lock + audit log | HIGH (structural requirement) | MEDIUM | P1 |
| Reports: international collaboration | MEDIUM | HIGH | P2 |
| Reports: funding sources | MEDIUM | HIGH | P2 |
| Reports: author roles | MEDIUM | MEDIUM | P2 |
| CSV export | MEDIUM | LOW | P2 |
| Multi-Scopus-ID support | LOW (until it's discovered to matter) | LOW | P2 |
| Scheduled sync | LOW (v1) | MEDIUM | P3 |
| PDF export | LOW | HIGH | P3 (avoid) |

**Priority key:**
- P1: Must have for the 3 Sept launch
- P2: Should have, add when Scopus field verification and timeline allow
- P3: Nice to have, likely post-launch

## Competitor Feature Analysis

| Feature | Elsevier Pure | VIVO (open source) | This Project's Approach |
|---------|---------------|---------------------|--------------------------|
| Researcher profiles | Rich, editable, self-service | Rich, semantic-web linked | Read-only, sync-derived only — no self-editing |
| Data sources | Multi-source (Scopus + WoS + manual) | Multi-source (bibliographic + grants + HR) | Single source: Scopus only (explicit non-goal to add more) |
| Multi-dimension reporting | Extensive, configurable dashboards | Network/visualization-focused | Scoped to the 10 named tabs, split by local-vs-field-dependent risk |
| SDG/impact tagging | Available via Elsevier's own SDG mapping (same underlying data this project consumes) | Not a core VIVO feature | Same underlying Scopus SDG data, simplified top-2 deterministic rule |
| Open-access/compliance workflow | Core feature | Not typically core | Explicit anti-feature — out of scope |
| Admin/editing surface | Full CRUD + workflow approval | Full CRUD | Sync-trigger only, no CRUD on research data |
| Availability during data updates | Not typically a marketed differentiator | N/A (mostly manual data entry driven) | Explicit differentiator — public reads must never block on sync |

## Sources

- [What Is Pure RIMS? Research Information System | Elsevier](https://www.elsevier.com/products/pure) — MEDIUM confidence
- [What Is a RIMS? Benefits, Uses & Why Your Institution Needs One | Elsevier](https://www.elsevier.com/products/pure/why-you-need-cris) — MEDIUM confidence
- [What is Research Information Management? | Symplectic](https://www.symplectic.co.uk/research-management-using-the-elements-platform/) — MEDIUM confidence
- [Symplectic Elements - Powering Research Management](https://www.symplectic.co.uk/products/symplectic-elements/) — MEDIUM confidence
- [VIVO: Data, Tools and Community for Research Discovery and Scholarship](https://vivo.ufl.edu/display/n145145) — MEDIUM confidence
- [VIVO: The Open-Source CRIS Platform — CASRAI](https://casrai.org/guides/vivo-open-source-cris-platform) — MEDIUM confidence
- [2023 Sustainable Development Goals (SDGs) now available on Scopus | Elsevier Scopus Blog](https://blog.scopus.com/posts/2023-sustainable-development-goals-sdgs-now-available-on-scopus) — MEDIUM confidence (corroborated by Elsevier Digital Commons Data mapping datasets and the Springer/Scientometrics gold-standard SDG-assignment comparison paper)
- [Sustainable Development Goals now on Scopus Author Profiles | Elsevier Scopus Blog](https://blog.scopus.com/sustainable-development-goals-now-on-scopus-author-profiles/) — MEDIUM confidence
- [How to use assignments of UN SDGs to scientific papers in research evaluation — Scientometrics/Springer](https://link.springer.com/article/10.1007/s11192-025-05254-w) — MEDIUM confidence (peer-reviewed, corroborates label/query-match methodology)
- [Current research information system — Grokipedia](https://grokipedia.com/page/Current_research_information_system) — LOW confidence, general CRIS/CERIF background only
- [What Is a RIMS / CRIS overview + InCites Research Analytics Dashboard | Clarivate](https://clarivate.com/academia-government/scientific-and-academic-research/research-funding-analytics/incites-benchmarking-analytics/research-analytics-dashboard/) — LOW confidence, marketing page, used only for general dashboard-feature framing
- General h-index/citation dashboard convention findings (library guides, AD Scientific Index) — LOW confidence, used only to confirm h-index/citation display is a universal convention, not for any specific numeric claim
- General API rate-limiting/sync best practices (Zuplo, Truto, Gravitee blog posts) — LOW confidence, generic industry guidance, cross-checked against SPEC §6.1's own already-well-specified retry/backoff rules (which take precedence)
- PROJECT.md and SPEC.md (this repository) — primary source for all scope, requirement, and open-question references

---
*Feature research for: university/faculty research-output repository & bibliometric analytics dashboard, Scopus-synced*
*Researched: 2026-08-18*
