# MSC Research Repository

## What This Is

ระบบคลังผลงานวิจัยของคณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา ที่ซิงค์ข้อมูลผลงานตีพิมพ์ นักวิจัย citations และ Scopus quartile จากฐานข้อมูล Scopus เข้าสู่ระบบ MySQL ของตัวเอง แล้วแสดงผลผ่านหน้าเว็บสาธารณะ (dashboard, ค้นหา, ทำเนียบนักวิจัย, รายงานสรุปวิเคราะห์หลายมิติ รวมถึง SDG mapping) ให้บุคลากร ผู้บริหาร และบุคคลทั่วไปเข้าถึงแบบอ่านอย่างเดียว โดยมี Admin panel (ล็อกอินผ่าน SSO ภายในคณะ MEDSCI ACC) ทำหน้าที่แค่สั่งซิงค์ข้อมูลเท่านั้น ไม่มีการแก้ไขข้อมูลด้วยมือ

## Core Value

ข้อมูลผลงานตีพิมพ์ของคณะที่ซิงค์มาจาก Scopus ต้องถูกต้อง ครบถ้วน และเข้าถึงได้แบบสาธารณะตลอดเวลา แม้ระหว่างที่กำลังซิงค์ข้อมูลอยู่ก็ตาม

## Requirements

### Validated

- [x] ซิงค์ข้อมูลผลงานตีพิมพ์/นักวิจัยจาก Scopus API เข้าสู่ MySQL พร้อม error handling (timeout retry, rate limit backoff, ข้อมูลไม่สมบูรณ์ skip record, Scopus Author ID ซ้ำ reject) — Phase 1, ทดสอบกับ production data จริง 85 นักวิจัย ไม่มี error (2026-08-19)
- [x] แดชบอร์ดหลักแสดงภาพรวม (จำนวนนักวิจัย, ผลงาน, citations, สัดส่วน quartile, ผู้มีผลงาน/citations/h-index สูงสุด, สถิติรายปี, ผลงานล่าสุด) — Phase 2, verified ผ่าน curl ทุกหน้า ไม่มี error (2026-08-19)
- [x] ค้นหา/กรองผลงานวิจัยตาม title + author (partial match) และ Scopus quartile พร้อม pagination 20 รายการ/หน้า — Phase 2, แก้ bug pagination 15→20 แล้ว (2026-08-19)

### Active

- [ ] ทำเนียบนักวิจัย กรองตามภาควิชา/ประเภทบุคลากร เรียงตามผลงาน/citations/h-index (default descending, toggle ascending ได้) พร้อม pagination
- [ ] รายงานสรุปวิเคราะห์หลายแท็บ (แนวโน้ม, แยกภาควิชา, quartiles, ความร่วมมือระหว่างประเทศ, แหล่งทุน, SDGs, จัดอันดับนักวิจัย, สถิติรายปี, sources, author roles)
- [ ] SDG mapping ผ่าน CSV import โดย admin (จับคู่ด้วย DOI หรือ title) — ผูกได้สูงสุด 2 SDG ต่อผลงานพร้อม rationale, ไม่มี SDG ที่ match เลย = Unclassified (ดูเหตุผลที่เปลี่ยนจาก auto-mapping แบบ weight ใน Key Decisions)
- [ ] Admin panel ล็อกอินผ่าน MEDSCI ACC SSO (Method 1 เท่านั้น) สั่งซิงค์ข้อมูล พร้อม sync lock/mutex กันซิงค์ซ้อน และ audit log (`triggered_by`)
- [ ] นักวิจัยที่ `is_active = false` ไม่แสดงในทำเนียบ แต่ผลงานเก่ายังนับในสถิติรวม

### Out of Scope

- Manual entry/edit ผลงานวิจัยหรือข้อมูลนักวิจัยโดยตรง — ข้อมูลมาจากการซิงค์ Scopus เท่านั้น, Admin ทำได้แค่สั่งซิงค์
- ฐานข้อมูลอ้างอิงอื่นนอกจาก Scopus (Web of Science, Google Scholar) — นอกขอบเขตเวอร์ชันนี้
- Direct API Auth (Method 2 ของ MEDSCI ACC ที่ส่ง username/password ตรง) — ตัดสินใจด้านความปลอดภัย ไม่ให้ระบบสัมผัสรหัสผ่านจริงของผู้ใช้

## Context

- ระบบเดิมรันอยู่แล้วเป็น server-rendered PHP บน IIS (www.medsci.up.ac.th) — URL pattern สังเกตได้: index.php, publications_search.php, researchers_list.php, reports.php, admin/index.php
- Draft schema (researchers, publications, publication_authors, sync_log, sdgs, publication_sdgs) อิงจากข้อมูลที่แสดงบนหน้าเว็บเดิม **ยังไม่ยืนยันกับโครงสร้างฐานข้อมูลจริง** — ต้องตรวจสอบก่อนเขียนโค้ด/test ที่อิงชื่อตาราง/คอลัมน์
- **[สำคัญมาก — ความปลอดภัย]** พบไฟล์ `sso_integration_guide.md` ในโฟลเดอร์โปรเจกต์ ซึ่งตาม SPEC.md ระบุว่ามี Developer Bypass credential ฝังอยู่ ห้าม commit ขึ้น git repo เด็ดขาด (แม้ private repo) — ได้เพิ่มเข้า `.gitignore` แล้วตั้งแต่เริ่มโปรเจกต์
- Timeline กระชับมาก: นำเสนอ 3 กันยายน 2569 (เหลือ 16 วันนับจาก 18 ส.ค. 2569 ตอนเริ่มโปรเจกต์)
- ยังมี Open Questions ที่ต้องยืนยันกับอาจารย์/ทีม IT ก่อน implement จริง (ดู SPEC.md หัวข้อ 12): อายุ token/replay policy ของ MEDSCI ACC, frontend framework จริง, นโยบายรัน self-hosted GitHub Actions runner, รูปแบบไฟล์ config ปัจจุบัน
- **[แก้ไข 2026-08-19]** Open Question เดิมเรื่อง "Scopus API field ที่ให้ค่า SDG weight" มีคำตอบแล้ว: **ไม่มี field แบบนั้นอยู่จริง** — Elsevier แบ่งประเภท SDG แบบ binary (match/ไม่ match กับ Boolean query ที่กำหนดไว้) ไม่มี weight ให้จัดอันดับ และ SDG data อยู่คนละ product (SciVal Publication Lookup API) ซึ่งทดสอบ API key ของโปรเจกต์แล้วไม่มีสิทธิ์เข้าถึง (403 ENTITLEMENTS_ERROR) — v1 จึงใช้ CSV import แทน ดู Key Decisions
- **[ยืนยันแล้ว 2026-08-19]** แหล่งข้อมูลผลงาน = Scopus อย่างเดียว (ระบบเดิมก่อน rewrite ดึงจาก ORCID/PubMed/Google Scholar ด้วย แต่ตั้งใจตัดออกเพราะเป็นสาเหตุปัญหาซ้ำๆ) และ Quartile = import Excel จาก Scimago ต่อไปตามเดิม (ยืนยันแล้วว่า Scimago ไม่มี public API)

## Constraints

- **Tech stack**: Backend PHP, Database MySQL, External API Scopus, Deployment IIS บน server คณะ (www.medsci.up.ac.th) — กำหนดไว้แล้วจากระบบเดิม
- **Timeline**: ต้องนำเสนอโปรเจกต์วันที่ 3 กันยายน 2569 — กระทบการจัดลำดับความสำคัญของ phase
- **Security**: Admin ต้องผ่าน SSO (MEDSCI ACC, SSO Redirect Method 1) เท่านั้น, ต้องป้องกัน SQL Injection (prepared statements), ต้อง HTML-escape ข้อมูลจาก Scopus ทั้งหมดก่อนแสดงผล (XSS), DB user สิทธิ์ least privilege, ห้าม commit `sso_integration_guide.md`/API key/client_secret ขึ้น git
- **Performance**: Dashboard ≤2 วินาที, Reports ≤3 วินาที ที่ขนาดข้อมูล ~100 นักวิจัย/~800 ผลงาน ปัจจุบัน ต้องออกแบบรองรับได้ถึง 10,000 ผลงาน/500 นักวิจัยโดยเวลาโหลดไม่เกิน 2 เท่า
- **Availability**: หน้าสาธารณะต้องเข้าถึงได้ตลอดเวลาแม้ระหว่าง sync กำลังทำงาน (sync ต้องไม่ lock การอ่านข้อมูล)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| ใช้ MEDSCI ACC SSO Redirect (Method 1) เท่านั้น ไม่ใช้ Direct API Auth (Method 2) | ระบบไม่ต้องสัมผัสรหัสผ่านจริงของผู้ใช้ ลดความเสี่ยง logging/leak | — Pending |
| Admin panel มีหน้าที่แค่สั่งซิงค์ ไม่มีฟีเจอร์แก้ไข/ลบข้อมูลโดยตรง | ข้อมูลผลงานต้องตรงกับ Scopus เสมอ ป้องกันข้อมูลเพี้ยนจากการแก้มือ | — Pending |
| ~~SDG weight เป็น float 0.0–1.0, เลือก top-2, tie-break ด้วยรหัส SDG น้อยไปมาก~~ **[ยกเลิก 2026-08-19]** | ตรวจสอบแล้วว่า Elsevier ไม่มี weight field แบบนี้จริง (SDG เป็น binary match) และ SciVal API (ที่มีข้อมูล SDG) ไม่อยู่ในสิทธิ์ของ key ที่มี (ทดสอบจริงได้ 403 ENTITLEMENTS_ERROR) | ✗ Invalidated — แทนที่ด้วยรายการถัดไป |
| SDG mapping v1 = CSV import โดย admin (คงกลไกเดิมของระบบปัจจุบัน), SciVal auto-mapping ผ่าน API ย้ายไป v2 (SDG-06) | ทันเดดไลน์ 3 ก.ย. แน่นอนกว่า เพราะการขอสิทธิ์ SciVal จาก Elsevier/มหาวิทยาลัยใช้เวลาไม่แน่นอน ไม่ควร block งาน v1 | ✓ Good |
| Re-import CSV เขียนทับ SDG tag เดิมทั้งหมด ไม่เก็บ version ประวัติ | ลดความซับซ้อน เหมาะกับ timeline ที่จำกัด | ✓ Good |
| แหล่งข้อมูลผลงาน = Scopus อย่างเดียว (ไม่ทำ ORCID/PubMed/Google Scholar แบบระบบเดิมก่อน rewrite) | ระบบเดิมดึงหลายแหล่งแล้วเจอปัญหา matching/dedup ซ้ำๆ ตามที่เจ้าของโปรเจกต์ยืนยัน | ✓ Good |
| Quartile = คงการ import Excel จาก Scimago ต่อไป ไม่สร้าง live API integration | ยืนยันแล้วว่า Scimago ไม่มี public API ให้เรียกจริง | ✓ Good |
| นักวิจัย inactive ซ่อนจากทำเนียบแต่ผลงานเก่ายังนับสถิติรวม | รักษาความถูกต้องของสถิติภาพรวมคณะย้อนหลัง | — Pending |
| ผลงานผู้แต่งร่วมหลายภาควิชานับซ้ำได้ทุกภาควิชา, ความร่วมมือหลายประเทศนับเครดิตเต็มทุกประเทศ | ค่าเริ่มต้านที่ให้ภาพรวมความร่วมมือครบถ้วนที่สุด รอยืนยันกับคณะ | — Pending |
| gitignore `sso_integration_guide.md` ตั้งแต่เริ่มโปรเจกต์ | เอกสารมี Developer Bypass credential ฝังอยู่ หลุดจะกระทบทุกระบบที่เชื่อม MEDSCI ACC | ✓ Good |

## Evolution

This document evolves at phase transitions and milestone boundaries.

**After each phase transition** (via `/gsd-transition`):
1. Requirements invalidated? → Move to Out of Scope with reason
2. Requirements validated? → Move to Validated with phase reference
3. New requirements emerged? → Add to Active
4. Decisions to log? → Add to Key Decisions
5. "What This Is" still accurate? → Update if drifted

**After each milestone** (via `/gsd-complete-milestone`):
1. Full review of all sections
2. Core Value check — still the right priority?
3. Audit Out of Scope — reasons still valid?
4. Update Context with current state

---
*Last updated: 2026-08-19 — Phase 1 (Scopus sync engine) and Phase 2 (dashboard & search) moved to Validated after implementation and testing against real production data; resolved the SDG-weight/SciVal open question (confirmed no weight field exists and the project's API key lacks SciVal entitlement via a live test), confirmed Scopus-only source and Scimago-Excel-import quartile decisions*
