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
- [x] ทำเนียบนักวิจัย กรองตามภาควิชา/ประเภทบุคลากร เรียงตามผลงาน/citations/h-index (default descending, toggle ascending ได้) พร้อม pagination — Phase 3, เขียนใหม่จาก client-side filter (ใช้ pagination จริงไม่ได้) เป็น server-side filter+sort+paginate (2026-08-19)
- [x] รายงานสรุปวิเคราะห์ — ส่วน local-aggregate (แนวโน้ม, แยกภาควิชา, quartiles, จัดอันดับนักวิจัย, สถิติรายปี, sources) — Phase 3, ของเดิมถูกต้องอยู่แล้ว ตรวจสอบ query แยกภาควิชาแล้วว่านับผลงานผู้แต่งร่วมหลายภาควิชาซ้ำได้ถูกต้องจริง (2026-08-19)
- [x] นักวิจัยที่ `is_active = false` ไม่แสดงในทำเนียบ แต่ผลงานเก่ายังนับในสถิติรวม — Phase 3, เพิ่ม UI toggle ใน admin (เดิมมีแค่ column ในฐานข้อมูล ไม่มีทางตั้งค่าได้เลย) (2026-08-19)
- [x] รายงานสรุปวิเคราะห์ — ส่วน field-dependent (ความร่วมมือระหว่างประเทศ, แหล่งทุน, SDGs, author roles) — Phase 4, ของเดิมถูกต้องอยู่แล้ว 3/4 แท็บ, เพิ่มหมวด "Unclassified" ในแท็บ SDG ที่หายไปทั้งหมด (91.5% ของผลงานไม่มี SDG แต่ก่อนหน้านี้ไม่แสดงที่ไหนเลย) (2026-08-19)
- [x] SDG mapping ผ่าน CSV import โดย admin (จับคู่ด้วย DOI หรือ title) — ผูกได้สูงสุด 2 SDG ต่อผลงานพร้อม rationale, ไม่มี SDG ที่ match เลย = Unclassified (ดูเหตุผลที่เปลี่ยนจาก auto-mapping แบบ weight ใน Key Decisions) — Phase 4 (2026-08-19)
- [x] Admin panel ล็อกอินผ่าน MEDSCI ACC SSO (Method 1 เท่านั้น) สั่งซิงค์ข้อมูล พร้อม sync lock/mutex กันซิงค์ซ้อน และ audit log (`triggered_by`) — Phase 5 (**phase สุดท้าย**), เจอ gap จริง 1 จุด: ไม่มี mutex กันซิงค์ซ้อนเลย แก้แล้วพร้อม auto-recovery ถ้า sync ค้าง (2026-08-19)

### Active

(ไม่มี — ครบทั้ง 5 phases แล้ว 2026-08-19)

### Out of Scope

- Manual entry/edit ผลงานวิจัยหรือข้อมูลนักวิจัยโดยตรง — ข้อมูลมาจากการซิงค์ Scopus เท่านั้น, Admin ทำได้แค่สั่งซิงค์
- ฐานข้อมูลอ้างอิงอื่นนอกจาก Scopus (Web of Science, Google Scholar) — นอกขอบเขตเวอร์ชันนี้
- Direct API Auth (Method 2 ของ MEDSCI ACC ที่ส่ง username/password ตรง) — ตัดสินใจด้านความปลอดภัย ไม่ให้ระบบสัมผัสรหัสผ่านจริงของผู้ใช้

## Context

- ระบบเดิมรันอยู่แล้วเป็น server-rendered PHP บน IIS (www.medsci.up.ac.th) — URL pattern สังเกตได้: index.php, publications_search.php, researchers_list.php, reports.php, admin/index.php
- Draft schema (researchers, publications, publication_authors, sync_log, sdgs, publication_sdgs) อิงจากข้อมูลที่แสดงบนหน้าเว็บเดิม **ยังไม่ยืนยันกับโครงสร้างฐานข้อมูลจริง** — ต้องตรวจสอบก่อนเขียนโค้ด/test ที่อิงชื่อตาราง/คอลัมน์
- **[สำคัญมาก — ความปลอดภัย]** พบไฟล์ `sso_integration_guide.md` ในโฟลเดอร์โปรเจกต์ ซึ่งตาม SPEC.md ระบุว่ามี Developer Bypass credential ฝังอยู่ ห้าม commit ขึ้น git repo เด็ดขาด (แม้ private repo) — ได้เพิ่มเข้า `.gitignore` แล้วตั้งแต่เริ่มโปรเจกต์
- **[แก้ไขแล้ว 2026-08-19 — ช่องโหว่ร้ายแรง]** `admin/researchers.php` เดิม require `admin_header.php` (จุดที่เช็ค login) ไว้ล่างสุดของไฟล์ **หลัง** action handler ทั้งหมด (delete, save, import CSV) — ทดสอบจริงยืนยันว่าใครก็ได้ที่ไม่ login สามารถยิง `researchers.php?delete=<id>` ได้ผลจริง ตรวจสอบทุกไฟล์ admin/*.php แล้วพบว่าเป็นไฟล์เดียวที่มีปัญหานี้ (อีก 9 ไฟล์ gate ถูกต้อง) — ย้าย auth check ไปไว้บนสุดของไฟล์แล้ว
- Timeline กระชับมาก: นำเสนอ 3 กันยายน 2569 (เหลือ 16 วันนับจาก 18 ส.ค. 2569 ตอนเริ่มโปรเจกต์)
- ยังมี Open Questions ที่ต้องยืนยันกับอาจารย์/ทีม IT ก่อน implement จริง (ดู SPEC.md หัวข้อ 12): อายุ token/replay policy ของ MEDSCI ACC, frontend framework จริง, นโยบายรัน self-hosted GitHub Actions runner, รูปแบบไฟล์ config ปัจจุบัน
- **[แก้ไข 2026-08-19]** Open Question เดิมเรื่อง "Scopus API field ที่ให้ค่า SDG weight" มีคำตอบแล้ว: **ไม่มี field แบบนั้นอยู่จริง** — Elsevier แบ่งประเภท SDG แบบ binary (match/ไม่ match กับ Boolean query ที่กำหนดไว้) ไม่มี weight ให้จัดอันดับ และ SDG data อยู่คนละ product (SciVal Publication Lookup API) ซึ่งทดสอบ API key ของโปรเจกต์แล้วไม่มีสิทธิ์เข้าถึง (403 ENTITLEMENTS_ERROR) — v1 จึงใช้ CSV import แทน ดู Key Decisions
- **[ยืนยันแล้ว 2026-08-19]** แหล่งข้อมูลผลงาน = Scopus อย่างเดียว (ระบบเดิมก่อน rewrite ดึงจาก ORCID/PubMed/Google Scholar ด้วย แต่ตั้งใจตัดออกเพราะเป็นสาเหตุปัญหาซ้ำๆ) และ Quartile = import Excel จาก Scimago ต่อไปตามเดิม (ยืนยันแล้วว่า Scimago ไม่มี public API)
- **[2026-08-19]** พบว่ามีอีก session/เครื่องหนึ่งกำลังแก้ไข security บนฝั่ง server แบบขนานกัน (`web.config` hardening, auth guard บน `diagnose.php`, ลบ `admin/temp_*.php` และ `fix_admin.php`) — commit มาถึง GitHub ช้ากว่าที่แจ้งไว้ในแชทตอนแรก ทำให้เกิดความสับสนชั่วคราวว่า push สำเร็จหรือยัง สุดท้าย merge เข้ากันได้สะอาด (คนละไฟล์กันทั้งหมด ไม่มี conflict) — ควรเช็ค `git remote -v`/`git log` ให้ตรงกันทุกครั้งที่มีคนทำงานพร้อมกันหลาย session

## Constraints

- **Tech stack**: Backend PHP, Database MySQL, External API Scopus, Deployment IIS บน server คณะ (www.medsci.up.ac.th) — กำหนดไว้แล้วจากระบบเดิม
- **Timeline**: ต้องนำเสนอโปรเจกต์วันที่ 3 กันยายน 2569 — กระทบการจัดลำดับความสำคัญของ phase
- **Security**: Admin ต้องผ่าน SSO (MEDSCI ACC, SSO Redirect Method 1) เท่านั้น, ต้องป้องกัน SQL Injection (prepared statements), ต้อง HTML-escape ข้อมูลจาก Scopus ทั้งหมดก่อนแสดงผล (XSS), DB user สิทธิ์ least privilege, ห้าม commit `sso_integration_guide.md`/API key/client_secret ขึ้น git
- **Performance**: Dashboard ≤2 วินาที, Reports ≤3 วินาที ที่ขนาดข้อมูล ~100 นักวิจัย/~800 ผลงาน ปัจจุบัน ต้องออกแบบรองรับได้ถึง 10,000 ผลงาน/500 นักวิจัยโดยเวลาโหลดไม่เกิน 2 เท่า
- **Availability**: หน้าสาธารณะต้องเข้าถึงได้ตลอดเวลาแม้ระหว่าง sync กำลังทำงาน (sync ต้องไม่ lock การอ่านข้อมูล)

## Key Decisions

| Decision | Rationale | Outcome |
|----------|-----------|---------|
| ใช้ MEDSCI ACC SSO Redirect (Method 1) เท่านั้น ไม่ใช้ Direct API Auth (Method 2) | ระบบไม่ต้องสัมผัสรหัสผ่านจริงของผู้ใช้ ลดความเสี่ยง logging/leak | ✓ Good — verified Phase 5 (2026-08-19); local login ที่มีอยู่คู่ขนานใช้ password แยกของระบบเอง ไม่ใช่รหัสผ่าน MEDSCI ACC จริง จึงไม่ขัดกับ decision นี้ |
| Admin panel มีหน้าที่แค่สั่งซิงค์ ไม่มีฟีเจอร์แก้ไข/ลบข้อมูลโดยตรง | ข้อมูลผลงานต้องตรงกับ Scopus เสมอ ป้องกันข้อมูลเพี้ยนจากการแก้มือ | ✓ Good — verified Phase 5 (2026-08-19) |
| Sync mutex = check-then-insert lock ใน `sync_log` (30 นาทีถือว่า stale) แทน DB-level lock (เช่น `GET_LOCK()`) | เครื่องมือ admin ใช้งานจริงไม่ได้มี concurrency สูง (คนกดปุ่ม/cron เป็นครั้งคราว) แนวทางง่ายพอสำหรับ threat model นี้ ตรงกับที่ SPEC.md ต้องการ (reject พร้อมข้อความ ไม่ใช่ hard lock ระดับ DB) | ✓ Good |
| ~~SDG weight เป็น float 0.0–1.0, เลือก top-2, tie-break ด้วยรหัส SDG น้อยไปมาก~~ **[ยกเลิก 2026-08-19]** | ตรวจสอบแล้วว่า Elsevier ไม่มี weight field แบบนี้จริง (SDG เป็น binary match) และ SciVal API (ที่มีข้อมูล SDG) ไม่อยู่ในสิทธิ์ของ key ที่มี (ทดสอบจริงได้ 403 ENTITLEMENTS_ERROR) | ✗ Invalidated — แทนที่ด้วยรายการถัดไป |
| SDG mapping v1 = CSV import โดย admin (คงกลไกเดิมของระบบปัจจุบัน), SciVal auto-mapping ผ่าน API ย้ายไป v2 (SDG-06) | ทันเดดไลน์ 3 ก.ย. แน่นอนกว่า เพราะการขอสิทธิ์ SciVal จาก Elsevier/มหาวิทยาลัยใช้เวลาไม่แน่นอน ไม่ควร block งาน v1 | ✓ Good |
| Re-import CSV เขียนทับ SDG tag เดิมทั้งหมด ไม่เก็บ version ประวัติ | ลดความซับซ้อน เหมาะกับ timeline ที่จำกัด | ✓ Good |
| แหล่งข้อมูลผลงาน = Scopus อย่างเดียว (ไม่ทำ ORCID/PubMed/Google Scholar แบบระบบเดิมก่อน rewrite) | ระบบเดิมดึงหลายแหล่งแล้วเจอปัญหา matching/dedup ซ้ำๆ ตามที่เจ้าของโปรเจกต์ยืนยัน | ✓ Good |
| Quartile = คงการ import Excel จาก Scimago ต่อไป ไม่สร้าง live API integration | ยืนยันแล้วว่า Scimago ไม่มี public API ให้เรียกจริง | ✓ Good |
| `is_active` = toggle ผ่านปุ่มใน admin (ไม่ใช่ hard delete) | ลบนักวิจัยจริงจะ cascade ลบ `researcher_publications` ทำให้ผลงานเก่าหลุดจากสถิติรวมของคณะ ขัดกับ RESEARCHER-05 | ✓ Good |
| นักวิจัย inactive ซ่อนจากทำเนียบแต่ผลงานเก่ายังนับสถิติรวม | รักษาความถูกต้องของสถิติภาพรวมคณะย้อนหลัง | ✓ Good — verified Phase 3 (2026-08-19) |
| ผลงานผู้แต่งร่วมหลายภาควิชานับซ้ำได้ทุกภาควิชา, ความร่วมมือหลายประเทศนับเครดิตเต็มทุกประเทศ | ค่าเริ่มต้านที่ให้ภาพรวมความร่วมมือครบถ้วนที่สุด รอยืนยันกับคณะ | — Pending |
| Phase 6: "Suggest SDGs" เป็นเพียงข้อเสนอแนะที่ admin ต้องกดยืนยันเอง (ใช้เป็น SDG หลัก/รอง แล้วกด Save ตามปกติ) ไม่มีการเขียนทับ sdg_primary/secondary อัตโนมัติ | สอดคล้องกับหลักการเดิมของโปรเจกต์ที่ admin panel ไม่แก้ไขข้อมูลอัตโนมัติโดยไม่ผ่านการตรวจสอบของมนุษย์ (เดิมทีใช้กับ sync, ตอนนี้ขยายมาใช้กับ SDG mapping ด้วย) | ✓ Good — verified 2026-08-19 (real Scopus fetch → score → apply → save, end-to-end) |
| Phase 6: SDG-06c (batch classify ทั้งหมด) เลื่อนออกไป ไม่ทำเป็น one-click บนหน้า admin | ข้อมูลจริงมี publication ที่ยังไม่จำแนก 1,883 จาก 1,945 รายการ และแทบไม่มีรายการไหนมี abstract/keywords จริงเลย (ต้องเรียก Scopus Abstract Retrieval API ทีละรายการ) — ทำ inline บนหน้าเว็บจะชนปัญหา IIS FastCGI timeout เดียวกับที่เอกสาร stack ของโปรเจกต์เองเคยเตือนไว้สำหรับ sync engine ต้องทำเป็น CLI/cron job แยกต่างหาก | — Pending (deferred, ไม่ใช่ blocker ของ Phase 6) |
| gitignore `sso_integration_guide.md` ตั้งแต่เริ่มโปรเจกต์ | เอกสารมี Developer Bypass credential ฝังอยู่ หลุดจะกระทบทุกระบบที่เชื่อม MEDSCI ACC | ✓ Good |
| Phase 7 (RCR): เลือกใช้ NIH iCite (ต้องแปลง DOI→PMID ก่อนเรียก) แทน OpenAlex (`cited_by_percentile_year`, DOI-native ไม่ต้องใช้ PMID) หรือ FWCI ของ Elsevier เอง | RCR คำนวณจาก co-citation network ของ PubMed เท่านั้น ไม่มีทางเลี่ยง PMID ได้จริงถ้าต้องการค่า RCR ตัวจริง — ผู้ใช้ยืนยันต้องการ RCR ของแท้ ยอมรับขั้นตอนแปลง PMID; ส่วน FWCI ต้องมีสิทธิ์ SciVal แยกต่างหากซึ่งยืนยันแล้วตั้งแต่ Phase 4 ว่า API key ของโปรเจกต์ไม่มีสิทธิ์ (403 ENTITLEMENTS_ERROR) | ✓ Good — ตัดสินใจแล้ว 2026-08-20 |
| Phase 7 (RCR): ตำแหน่งแสดงผล RCR = แสดงทั้ง 3 จุด (Reports/โปรไฟล์นักวิจัย/badge รายการผลงาน) พร้อมกัน ไม่ใช่เลือกจุดเดียว; รูปแบบการดึงข้อมูล = ทำทั้ง 2 แบบ คือ batch tool คล้าย Auto-Classify (เป็นกลไกหลัก) และ on-demand ต่อผลงานคล้าย Suggest SDGs (เป็นส่วนเสริม) | ผู้ใช้ยืนยันต้องการเห็น RCR แบบสาธารณะครบทุกจุดที่เสนอไว้ ไม่จำกัดแค่ admin panel; ด้านการดึงข้อมูลต้องการทั้ง batch (สำหรับรันทั้งชุดและ Refresh เป็นระยะ เพราะ RCR เปลี่ยนค่าไปตามจำนวนการอ้างอิงที่เพิ่มขึ้นเรื่อยๆ ไม่ใช่ classification แบบครั้งเดียวจบแบบ SDG) และ on-demand (สำหรับ resolve/refresh เฉพาะรายการเดียวตอนแก้ไข publication) โดยระบุชัดว่า batch เป็นกลไกหลัก on-demand เป็นส่วนเสริม | ✓ Good — ตัดสินใจแล้ว 2026-08-20 |
| Phase 7 (RCR): ใช้ NCBI ESearch (`esearch.fcgi?db=pubmed&term={doi}[doi]`) แปลง DOI→PMID แทน PMC ID Converter (`pmc/utils/idconv`) ที่ระบุไว้ในสเปกเดิม | ทดสอบจริงพบว่า idconv ตอบ "Identifier not found in PMC" สำหรับ DOI ที่มี PMID จริงใน PubMed (idconv ครอบคลุมเฉพาะผลงานที่ฝากไฟล์เต็มไว้ใน PMC เท่านั้น ไม่ใช่ PubMed ทั้งหมด) ส่วน ESearch ค้นตรงกับฐาน pubmed จึงครอบคลุมทุกผลงานที่มี PMID จริง | ✓ Good — แก้ไขและ verify แล้วระหว่าง implement 2026-08-20 |
| Phase 8 (Topic Prominence & Trends): เก็บ topic ที่ได้จาก OpenAlex เป็นตาราง `publication_topics` แบบ one-to-many แยกต่างหาก แทนที่จะเพิ่มคอลัมน์คงที่แบบ `topic_primary`/`topic_secondary` | OpenAlex topic ไม่ใช่ข้อมูลที่ admin คัดกรอง/ยืนยันเอง (ไม่มีหน้าตรวจสอบแบบ SDG) เป็นข้อมูลคำนวณล้วนๆ สำหรับสรุปภาพรวม/แนวโน้ม การใช้ตารางแยกป้องกันไม่ให้เจอปัญหาต้อง migrate คอลัมน์ซ้ำแบบที่ SDG-06c เจอมาแล้ว 2 รอบ (2→3 SDG) | ✓ Good — ตัดสินใจแล้ว 2026-08-20 |
| Phase 8: ตำแหน่งแสดงผล Topic Prominence & Trends = แท็บใหม่ใน reports.php ข้างแท็บ SDG เดิม | ผู้ใช้อนุมัติให้ implement เลย ("ทำ phase 8 ก่อนได้เลย") จึงดำเนินการตามแนวทางที่เสนอไว้ในสเปก | ✓ Good — implement แล้วและ verify กับ OpenAlex API จริงบน local Docker stack 2026-08-20 |

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
*Last updated: 2026-08-19 — Phase 5 (Admin SSO & Sync Control) moved to Validated after adding the missing sync mutex/lock; **all 5 phases now complete (36/36 v1 requirements)**. Phase 4 (SDG mapping, field-dependent reports) moved to Validated after fixing the SDG statistics tab's missing Unclassified bucket. Phase 3 (researcher directory, local-aggregate reports) moved to Validated; fixed a critical unauthenticated-mutation vulnerability found in admin/researchers.php; merged parallel server-side security work (web.config, diagnose.php, temp file cleanup) with no conflicts. Phase 1 (Scopus sync engine) and Phase 2 (dashboard & search) moved to Validated after implementation and testing against real production data; resolved the SDG-weight/SciVal open question (confirmed no weight field exists and the project's API key lacks SciVal entitlement via a live test), confirmed Scopus-only source and Scimago-Excel-import quartile decisions*
