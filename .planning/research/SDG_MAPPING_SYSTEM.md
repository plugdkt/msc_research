# In-House SDG Mapping System Reference (`msc_sdgs`)

## 1. Overview & Location

On the production server (`C:\inetpub\wwwroot\msc_sdgs`), there is an existing in-house web application called **"MEDSCI SDGs Key Phrases Mapping"**.

This system already implements an automated SDG mapping & keyword extraction engine which can serve as a localized replacement for the previously deferred SciVal API integration (which failed due to a 403 Entitlement error on Elsevier's commercial SciVal product).

---

## 2. Core Components in `msc_sdgs`

### 2.1 Curated SDG Key Phrases Dictionary (`sdg_data.json`)
- **File size:** ~796 KB
- **Content:** Covers all 17 SDGs (SDG 1 - SDG 17).
- **Structure:**
  - `num`: SDG Number (1 - 17)
  - `name`: English SDG Name (e.g., "Good Health and Well-being")
  - `name_th`: Thai SDG Name (e.g., "สุขภาพและความเป็นอยู่ที่ดี")
  - `color`: Official SDG Hex Color Code
  - `keyphrases`: Comprehensive array of keyword objects with:
    - `k`: Exact keyword / phrase string (e.g., `"cancer"`, `"microfinance institution"`)
    - `rel`: Relevance weight score (0.0 to 1.0)
    - `growth`: Topic growth percentage

### 2.2 Scopus Metadata & Keyword Fetcher (`scopus_api.php`)
- **Functions:**
  - `fetch_doi`: Accepts a DOI or Title and calls Elsevier Scopus Abstract Retrieval API (`https://api.elsevier.com/content/abstract/doi/{doi}` or `/eid/{eid}`) to fetch:
    - Article Title (`dc:title`)
    - Abstract (`dc:description`)
    - Author Keywords (`authkeywords`)
    - Journal name & Publication date
  - `search`: Queries Scopus via `TITLE-ABS-KEY(...)`.
  - `extract_keywords`: Uses curated Boolean query strings per SDG to extract and aggregate author keywords from Scopus.

---

## 3. Integration Plan for `msc_researchV2` (v2 Scope)

Instead of waiting for an institutional subscription to Elsevier SciVal API, `msc_researchV2` can leverage this in-house dictionary directly in v2:

1. **Auto-Suggest Mode (Admin Review):**
   - When viewing or editing publications in `admin/publications.php` or `admin/sdg_import.php`, an admin can click "Suggest SDGs".
   - The algorithm tokenizes the publication's Title, Abstract, and Keywords against `sdg_data.json` and ranks top-scoring SDGs with matched keyword rationales.
2. **Batch Classification Mode:**
   - A background script/admin tool that processes all *Unclassified* publications in `msc_research` database, scores them against `sdg_data.json`, and pre-populates `sdg_primary` / `sdg_secondary`.
3. **Cross-Linking:**
   - Direct links from `reports.php` (SDG Tab) to `/msc_sdgs` for deeper keyword trend exploration.
