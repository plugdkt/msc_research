# SPEC.md

## 1. Overview
- ชื่อโปรเจกต์: ระบบคลังผลงานวิจัย (MSC Research Repository)
- สรุปสั้นๆ: ระบบเก็บและแสดงผลข้อมูลผลงานตีพิมพ์วิจัยของบุคลากรคณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา โดยดึงข้อมูลมาจากฐานข้อมูล Scopus มาซิงค์เก็บไว้ในระบบ เพื่อให้บุคลากร ผู้บริหาร และบุคคลทั่วไปสามารถค้นหา วิเคราะห์ และดูสถิติผลงานวิจัยของคณะ/รายบุคคล/รายภาควิชาได้
- **Roles ทั้ง 3 กลุ่ม (บุคลากร/ผู้บริหาร/บุคคลทั่วไป) มีสิทธิ์เท่ากันคือ "อ่านอย่างเดียว" (read-only) บนหน้าสาธารณะทั้งหมด** ไม่มีการแบ่งสิทธิ์พิเศษระหว่างกลุ่มเหล่านี้ สิทธิ์ที่ต่างออกไปมีเฉพาะ "Admin" (ดูหัวข้อ 9)

## 2. Goals
- เป้าหมายหลักที่ต้องทำให้ได้:
  1. รวบรวมและซิงค์ข้อมูลผลงานตีพิมพ์ของบุคลากรจาก Scopus มาเก็บไว้ในฐานข้อมูลของระบบเอง
  2. แสดงแดชบอร์ดภาพรวม (จำนวนนักวิจัย, ผลงานตีพิมพ์, citations, สัดส่วน Scopus quartile)
  3. ให้ค้นหา/กรองผลงานวิจัยและรายชื่อนักวิจัยได้ตามเงื่อนไขต่างๆ
  4. สร้างรายงานวิเคราะห์เชิงลึกทั้งระดับคณะ/ภาควิชา/รายบุคคล
  5. Mapping ผลงานวิจัยแต่ละชิ้นเข้ากับ SDGs (Sustainable Development Goals) โดยดึงข้อมูลจาก Scopus มาประมวลผล แล้วเลือก SDG ที่มีน้ำหนัก (weight/relevance score) สูงสุด 2 อันดับแรกมาผูกกับผลงานนั้น เพื่อให้ระบบ tracking ได้ว่าแต่ละผลงานเกี่ยวข้องกับ SDGs ข้อใดบ้าง (รายละเอียดกฎการเลือก/tie-break ดูหัวข้อ 4.1)

## 3. Non-goals
- สิ่งที่ตั้งใจ "ไม่ทำ" ในเวอร์ชันนี้:
  - ไม่ทำระบบบันทึก/แก้ไขผลงานวิจัยด้วยมือ (manual entry/edit) — ข้อมูลผลงานมาจากการซิงค์ Scopus เท่านั้น **Admin panel มีหน้าที่แค่ "สั่งซิงค์" เท่านั้น ไม่มีฟีเจอร์แก้ไข/ลบข้อมูลผลงานหรือนักวิจัยโดยตรง** (แก้ไขความคลุมเครือเดิมระหว่างหัวข้อ Non-goals กับ Security)
  - ไม่ครอบคลุมฐานข้อมูลอ้างอิงอื่นนอกจาก Scopus (เช่น Web of Science, Google Scholar) ในเวอร์ชันนี้

## 4. Requirements

### 4.1 Functional Requirements
- **แดชบอร์ดหลัก**: แสดงจำนวนนักวิจัยทั้งหมด, ผลงานตีพิมพ์ทั้งหมด, จำนวน citations รวม, สัดส่วน Scopus quartile (Q1-Q4 + unclassified), ผู้มีผลงานตีพิมพ์/citations/h-index สูงสุด, สถิติการตีพิมพ์รายปี, ผลงานตีพิมพ์ล่าสุด (พร้อมชื่อผู้แต่ง, วารสาร, DOI, quartile, จำนวน citation, แหล่งทุนวิจัย, ประเทศร่วมวิจัย)

- **ค้นหางานวิจัย**: ค้นหาผลงานตีพิมพ์ด้วยคำค้น และกรองตาม Scopus quartile ได้
  - **ฟิลด์ที่ค้นหา**: ชื่อเรื่อง (title) และชื่อผู้แต่ง (author name) เท่านั้น แบบ partial match (LIKE %keyword%) — ไม่ค้นใน abstract/full text ในเวอร์ชันนี้ *(ค่าเริ่มต้นที่กำหนดไว้ — ปรับได้ถ้าต้องการค้นฟิลด์อื่นเพิ่ม)*
  - **Pagination**: แสดง 20 รายการต่อหน้า เรียงตามปีตีพิมพ์ล่าสุดก่อน (descending) เป็นค่าเริ่มต้น *(ค่าเริ่มต้นที่กำหนดไว้)*

- **ทำเนียบนักวิจัย**: แสดงรายชื่อนักวิจัยทั้งหมด กรองตามภาควิชา (Anatomy, Biochemistry, Microbiology, Nutrition and Dietetis, Physiology) และประเภทบุคลากร (สายวิชาการ/สายสนับสนุน) เรียงลำดับตามจำนวนผลงาน/citations/h-index ได้
  - **ทิศทางการเรียงเริ่มต้น**: มาก → น้อย (descending) เสมอ ไม่ว่าจะเรียงตามฟิลด์ใด *(ค่าเริ่มต้นที่กำหนดไว้ — ผู้ใช้สลับเป็น ascending ได้ผ่านปุ่ม toggle)*
  - **Pagination**: แสดง 20 คนต่อหน้า
  - แต่ละคนแสดงชื่อไทย-อังกฤษ, ภาควิชา, Scopus ID, จำนวนผลงาน, citations รวม, h-index
  - **นักวิจัยที่พ้นสภาพ/ลาออก**: ระบบมีฟิลด์ `is_active` (boolean) ต่อ researcher — นักวิจัยที่ inactive จะไม่แสดงในทำเนียบนักวิจัย (list) แต่ผลงานเก่าของเขายังคงถูกนับรวมในสถิติ/รายงานภาพรวมของคณะ (ไม่ลบข้อมูลย้อนหลัง) *(ค่าเริ่มต้นที่กำหนดไว้ — ควรยืนยันกับอาจารย์/คณะว่านโยบายจริงตรงกันหรือไม่)*

- **รายงานสรุป**: กรองตามภาควิชา/ปีที่ตีพิมพ์/ประเภทบุคลากร แสดงแท็บวิเคราะห์หลายมุม ได้แก่ ภาพรวมแนวโน้ม, สรุปแยกภาควิชา, สรุปตาม Quartiles, ความร่วมมือระหว่างประเทศ, แหล่งทุนวิจัย, สถิติตาม SDGs, จัดอันดับนักวิจัย, สถิติรายปี (จำนวนผลงาน+citations+เฉลี่ยผลงานต่อคน), สัดส่วนแหล่งเผยแพร่ (sources), บทบาทผู้เขียน (author roles)
  - **กฎการนับผลงานที่มีผู้แต่งร่วมหลายภาควิชา**: นับผลงาน 1 ชิ้น "ซ้ำได้" ในทุกภาควิชาที่มีผู้แต่งร่วม (co-author) สังกัดอยู่ — เช่น ผลงาน 1 ชิ้นมีผู้แต่งจาก Biochemistry และ Physiology จะถูกนับเข้าทั้ง 2 ภาควิชาในรายงาน "สรุปแยกภาควิชา" *(ค่าเริ่มต้นที่กำหนดไว้ — ทางเลือกอื่นคือนับเฉพาะภาควิชาของ corresponding author เท่านั้น ควรยืนยันแนวทางที่คณะต้องการ)*
  - **กฎการนับความร่วมมือระหว่างประเทศ**: ผลงาน 1 ชิ้นที่มีผู้ร่วมวิจัยจากหลายประเทศ นับเครดิตเต็ม (full count) ให้ทุกประเทศที่ปรากฏ ไม่หารเฉลี่ย *(ค่าเริ่มต้นที่กำหนดไว้)*

- **ระบบ Admin**: ล็อกอินผ่าน Single Sign-On (SSO) เพื่อ**สั่งซิงค์ข้อมูลจาก Scopus เท่านั้น** (ไม่มีฟีเจอร์แก้ไขข้อมูลโดยตรง ตามที่ระบุใน Non-goals) เห็น timestamp "ซิงค์ข้อมูลล่าสุด" แสดงบนหน้าสาธารณะ
  - **ป้องกันการซิงค์ซ้อนกัน**: ระบบต้องมี lock/mutex — ถ้ามีการซิงค์กำลังทำงานอยู่ การกดสั่งซิงค์ซ้ำต้องถูกปฏิเสธพร้อมข้อความแจ้งเตือน ("มีการซิงค์กำลังทำงานอยู่ กรุณารอให้เสร็จก่อน") ไม่ใช่ปล่อยให้รันซ้อนกัน
  - **Audit log**: ทุกครั้งที่มีการสั่งซิงค์ ต้องบันทึกผู้ใช้ที่สั่ง (ดูฟิลด์ `triggered_by` ในหัวข้อ 7)

- **SDG Mapping**: หลังซิงค์ข้อมูลผลงานจาก Scopus แล้ว ระบบต้องประมวลผล mapping ผลงานแต่ละชิ้นเข้ากับ SDGs (17 เป้าหมาย) โดย:
  - ดึงค่าน้ำหนัก (weight/relevance score) ของแต่ละ SDG จาก Scopus/Elsevier Fingerprint Engine เป็นค่า **float ช่วง 0.0–1.0** *(ค่าเริ่มต้นที่กำหนดไว้ — ต้องตรวจสอบกับ field จริงที่ Scopus API ให้มา อาจเป็นคนละ scale)*
  - เลือก SDG ที่มีน้ำหนักสูงสุด **2 อันดับแรก** มาผูก (tag) กับผลงานชิ้นนั้น
  - **กรณีน้ำหนักเท่ากัน (tie)**: ถ้ามี SDG มากกว่า 2 ข้อที่น้ำหนักเท่ากันในอันดับตัดสิน ให้เลือกตาม**รหัส SDG น้อยไปมาก** (เช่น SDG3 มาก่อน SDG5) เป็น tie-breaking rule *(ค่าเริ่มต้นที่กำหนดไว้ — เป็น arbitrary rule เพื่อให้ผลลัพธ์ deterministic ไม่ได้อิงหลักวิชาการใดเป็นพิเศษ)*
  - **กรณีไม่พบ SDG ที่เกี่ยวข้องเลย** (weight ทุกตัว = 0 หรือ Scopus ไม่มีข้อมูล SDG ให้): ผลงานนั้นจะถูก tag เป็น "ไม่มี SDG ที่เกี่ยวข้อง" (ไม่ผูกกับ SDG ใดเลย) และถูกนับแยกเป็นหมวด "Unclassified" ในรายงานสถิติ SDG แทนที่จะไม่แสดงเลย
  - แสดงผล/tracking ได้ว่าผลงานแต่ละชิ้นสังกัดอยู่ใน SDG ข้อใดบ้าง (สูงสุด 2 ข้อ) ทั้งในหน้ารายละเอียดผลงานและในหน้ารายงานสรุป
  - **Re-sync/อัปเดตย้อนหลัง**: ถ้า Scopus อัปเดตข้อมูล SDG ของผลงานที่เคย mapping ไปแล้ว ระบบจะ**คำนวณ mapping ใหม่ทั้งหมดและเขียนทับค่าเดิม** (ไม่เก็บ version ประวัติของ SDG mapping) *(ค่าเริ่มต้นที่กำหนดไว้)*

### 4.2 Non-functional Requirements
- **Performance**:
  - หน้าแดชบอร์ดต้อง render เสร็จภายใน **2 วินาที** ที่ขนาดข้อมูลปัจจุบัน (~100 นักวิจัย, ~800 ผลงาน)
  - หน้ารายงานสรุป (reports.php) ที่มีการ aggregate ข้อมูลหลายมิติ ต้องเสร็จภายใน **3 วินาที**
  - ระบบต้องออกแบบให้รองรับได้ถึง **10,000 publications / 500 researchers** โดยเวลาโหลดไม่เกิน 2 เท่าของตัวเลขข้างต้น *(ตัวเลขเป้าหมายเบื้องต้น — ปรับได้ตามจริงเมื่อ benchmark)*
- **Security**: ส่วน Admin ต้องผ่าน SSO เท่านั้น ห้ามเข้าถึงฟังก์ชันสั่งซิงค์ได้โดยไม่ล็อกอิน (ดูรายละเอียดเพิ่มในหัวข้อ 9)
- **Availability**: ข้อมูลสาธารณะ (แดชบอร์ด/ค้นหา/ทำเนียบนักวิจัย/รายงาน) ต้องเข้าถึงได้โดยไม่ต้องล็อกอิน ตลอดเวลาแม้ระหว่างที่ Admin กำลังสั่งซิงค์ข้อมูลอยู่ (sync ต้องไม่ lock การอ่านข้อมูลฝั่งสาธารณะ)

## 5. Tech Stack
- Frontend: (ระบุ - จากการสำรวจหน้าเว็บเป็น server-rendered PHP, ยังไม่ยืนยัน framework CSS/JS ที่ใช้ — ดู Open Questions)
- Backend: PHP (สังเกตจาก URL เช่น index.php, publications_search.php, researchers_list.php, reports.php, admin/index.php)
- Database: MySQL
- External API: Scopus API (สำหรับดึงข้อมูลผลงานตีพิมพ์, citations, Scopus ID, quartile ของวารสาร, SDG relevance data)
- Deployment/Hosting: IIS บน server ของคณะ/มหาวิทยาลัย (www.medsci.up.ac.th)

## 6. Architecture
- ภาพรวม: ระบบ Admin (หลัง SSO) สั่งซิงค์ข้อมูลจาก Scopus API → บันทึก/อัปเดตลงฐานข้อมูล MySQL → หน้าเว็บสาธารณะ (PHP) ดึงข้อมูลจาก MySQL มาแสดงผลแบบ real-time หลังซิงค์เสร็จ
- ส่วนประกอบหลัก:
  1. Public-facing pages (dashboard, search, researcher list, reports)
  2. Admin panel (SSO auth, data sync trigger, sync lock)
  3. Scopus sync service/job (พร้อม error handling — ดูด้านล่าง)
  4. MySQL database

### 6.1 Error Handling ระหว่างการซิงค์ข้อมูลจาก Scopus
- **Scopus API เข้าถึงไม่ได้/timeout**: sync job ต้อง retry สูงสุด 3 ครั้ง แบบ exponential backoff (เช่น รอ 5s, 15s, 45s) หากยัง fail ให้ยกเลิก sync รอบนั้นทั้งหมด (ไม่บันทึกข้อมูลบางส่วน) และบันทึกสถานะ "failed" ใน `sync_log` พร้อมข้อความ error ให้ admin เห็น
- **Scopus rate limit ถูกจำกัด (HTTP 429)**: sync job ต้องหยุดชั่วคราวตามเวลาที่ Scopus กำหนดใน response header (ถ้ามี) หรือ fallback เป็นรอ 60 วินาทีแล้วลองใหม่ หากเกิน 3 ครั้งให้ยกเลิกเหมือนกรณี timeout
- **ข้อมูลที่ Scopus ส่งมาไม่สมบูรณ์** (เช่น ไม่มี DOI, ชื่อผู้แต่งเป็น null): ข้าม (skip) เฉพาะ record นั้น ไม่ทำให้ sync ทั้งหมดล้มเหลว และบันทึกจำนวน record ที่ถูกข้ามไว้ใน `sync_log`
- **นักวิจัยมี Scopus Author ID ซ้ำกัน**: sync job ต้อง validate ก่อนบันทึก หากพบ ID ซ้ำให้ปฏิเสธการซิงค์ของ record นั้นและแจ้งเตือนใน sync log ให้ admin ตรวจสอบด้วยมือ (ระบบไม่ auto-merge ให้)

## 7. Data Model
- ตาราง/entity หลัก (ยังคงเป็น **draft schema** สันนิษฐานจากข้อมูลที่แสดงบนหน้าเว็บ — **ต้องตรวจสอบกับโครงสร้างฐานข้อมูลจริงก่อนเขียนโค้ด/เขียน test ใดๆ ที่อิงชื่อตาราง/คอลัมน์เหล่านี้**):
  - `researchers` (นักวิจัย): ชื่อไทย, ชื่ออังกฤษ, ตำแหน่งวิชาการ, ภาควิชา, ประเภทบุคลากร, Scopus Author ID (unique constraint), h-index, **`is_active`** (boolean, เพิ่มใหม่สำหรับนักวิจัยที่พ้นสภาพ)
  - `publications` (ผลงานตีพิมพ์): ชื่อเรื่อง, ผู้แต่ง, วารสาร, ปีที่ตีพิมพ์, DOI, Scopus quartile, จำนวน citations, แหล่งทุนวิจัย, ประเทศร่วมวิจัย
  - `publication_authors` (ตารางเชื่อม many-to-many ระหว่าง publications และ researchers, พร้อม author role)
  - `sync_log` (ประวัติการซิงค์ข้อมูลจาก Scopus): timestamp เริ่ม/จบ, สถานะ (success/failed/partial), จำนวน record ที่ข้าม, **`triggered_by`** (เพิ่มใหม่ — user/admin ที่สั่งซิงค์ เพื่อการตรวจสอบย้อนหลัง/audit)
  - `sdgs` (ตารางอ้างอิง 17 เป้าหมาย SDGs: รหัส, ชื่อ, คำอธิบาย)
  - `publication_sdgs` (ตารางเชื่อม many-to-many ระหว่าง publications และ sdgs, เก็บ `weight`/`relevance_score` (float 0.0-1.0) และ `rank` (1 หรือ 2) — จำกัดไว้แค่ 2 แถวต่อผลงาน 1 ชิ้น หรือ 0 แถวถ้าไม่พบ SDG ที่เกี่ยวข้องเลย)
- ความสัมพันธ์: นักวิจัย 1 คน มีผลงานได้หลายชิ้น, ผลงาน 1 ชิ้น มีผู้แต่งร่วมได้หลายคน (many-to-many ผ่าน publication_authors), ผลงาน 1 ชิ้น ผูกกับ SDG ได้สูงสุด 2 ข้อ (many-to-many ผ่าน publication_sdgs พร้อม weight/rank)

## 8. API / Endpoints
| Method | Path | รายละเอียด | Auth ต้องการไหม |
|--------|------|-----------|-----------------|
| GET | /index.php | แดชบอร์ดหลัก | ไม่ต้อง |
| GET | /publications_search.php | ค้นหา/กรองผลงานวิจัย (query param: `q` ค้นหาชื่อเรื่อง/ผู้แต่ง, `quartile`, `page`, `sort`) | ไม่ต้อง |
| GET | /researchers_list.php | ทำเนียบนักวิจัย พร้อมตัวกรอง (query param: `department`, `type`, `sort`, `order`, `page`) | ไม่ต้อง |
| GET | /reports.php | รายงานสรุปวิเคราะห์ (รองรับ query param เช่น `?tab=countries`) | ไม่ต้อง |
| GET/POST | /admin/index.php | หน้าล็อกอิน SSO + สั่งซิงค์ข้อมูล (สั่งซิงค์ซ้อนกันจะถูกปฏิเสธด้วย HTTP 409) | ต้อง (SSO) |

## 9. Authentication & Security
- **วิธียืนยันตัวตน (ยืนยันแล้ว)**: ใช้ระบบกลางของคณะ **MEDSCI ACC** (Identity Provider ภายใน ไม่ใช่ SAML/OAuth/CAS มาตรฐาน) ผ่านแนวทาง **SSO Redirect (Method 1)** เท่านั้น:
  1. หน้า Admin ของเรา redirect ผู้ใช้ไปที่ `msc_acc/sso/login.php` พร้อม `client_id` + `redirect_uri`
  2. ผู้ใช้ล็อกอินที่ระบบกลาง แล้วถูก redirect กลับมาที่ `redirect_uri` ของเราพร้อม `?token=...`
  3. ระบบเรายิง POST ไปที่ `msc_acc/api/verify.php` พร้อม `token` + `client_id` + `client_secret` เพื่อ verify token และรับข้อมูลโปรไฟล์ (user_id, username, name, ตำแหน่ง, สังกัด, email) กลับมา
  - **ห้ามใช้ Method 2 (Direct API Auth ที่ส่ง username/password ตรง)** เด็ดขาด แม้คู่มือจะรองรับ เพราะทำให้ระบบเราต้องสัมผัสรหัสผ่านจริงของผู้ใช้โดยไม่จำเป็น เพิ่มความเสี่ยงเรื่อง logging/leak โดยไม่ตั้งใจ
- **การจัดการ token/session**:
  - การ verify token ต้องทำฝั่ง **server-side เท่านั้น** ผ่าน HTTPS ไปที่ `msc_acc/api/verify.php` — ห้าม trust ค่าที่ decode จาก token เองฝั่ง client โดยไม่ผ่านการ verify กับระบบกลางก่อน
  - **ต้องเปิด SSL/TLS certificate verification ตามปกติเมื่อเรียก API ระบบกลาง** (`CURLOPT_SSL_VERIFYPEER` ต้องเป็น `true` หรือไม่ตั้งค่าเป็น false เด็ดขาด) — โค้ดตัวอย่างในคู่มือ SSO ที่ได้รับมามีการปิดค่านี้ไว้ (`CURLOPT_SSL_VERIFYPEER, false`) ซึ่งขัดกับข้อกำหนดเรื่อง HTTPS ของคู่มือเดียวกันเอง **ห้าม copy โค้ดตัวอย่างนั้นไปใช้ตรงๆ โดยไม่แก้ไขจุดนี้ก่อน**
  - `client_secret` ต้องเก็บใน environment variable/config ฝั่ง server เท่านั้น ห้ามฝังในโค้ด JavaScript ฝั่ง client หรือ commit ลง git repo
  - ใช้ session ที่มีอายุสั้น (เช่น หมดอายุใน 1-2 ชั่วโมง) และต้อง re-authenticate ใหม่หลังหมดอายุ *(อายุ token/การ replay ซ้ำได้กี่ครั้ง ยังไม่ระบุในคู่มือ — ดู Open Questions)*
  - **Redirect URI ที่ลงทะเบียนกับ MEDSCI ACC ต้องระบุ URL เต็มเจาะจง** ไม่ใช้ wildcard และไม่ปล่อยว่าง เพื่อป้องกัน Open Redirect (ตามคำแนะนำในคู่มือ SSO)
- **ประเด็นความปลอดภัยที่ต้องระวัง**:
  - การเก็บ Scopus API key และ MEDSCI ACC client_secret ต้องไม่ hardcode ในโค้ด ควรเก็บใน env/config แยก
  - ต้องป้องกัน SQL Injection ในหน้าค้นหา/กรองข้อมูล (query params หลายจุด) — ใช้ prepared statements เท่านั้น
  - จำกัดสิทธิ์การสั่งซิงค์ข้อมูลให้เฉพาะผู้ที่ล็อกอินผ่าน SSO เท่านั้น
  - **Output encoding / XSS**: ข้อมูลที่มาจาก Scopus (ชื่อเรื่อง, ชื่อผู้แต่ง, แหล่งทุนวิจัย) ถือเป็นข้อมูลจากภายนอก (untrusted) ต้อง HTML-escape ทุกครั้งก่อนแสดงผลบนหน้าเว็บ ห้าม render แบบ raw HTML
  - **Database least privilege**: DB user ที่แอปใช้เชื่อมต่อ MySQL ต้องมีสิทธิ์แค่ SELECT/INSERT/UPDATE เท่าที่จำเป็น ห้ามมีสิทธิ์ DROP/ALTER บน production แยก account สำหรับงาน migration/schema change ออกต่างหาก
  - **[สำคัญมาก] ห้าม commit เอกสาร/ไฟล์คู่มือการเชื่อมต่อ SSO ที่ได้รับจากคณะขึ้น git repo (แม้เป็น private repo)** เพราะมี Developer Bypass credential (username/password ทดสอบที่ auto-map ไปยังบัญชีบุคลากรจริง) ฝังอยู่ในเอกสาร — ถ้าหลุดออกไปจะกระทบความปลอดภัยของทุกระบบย่อยที่เชื่อมกับ MEDSCI ACC ไม่ใช่แค่โปรเจกต์นี้ ให้เก็บเอกสารนี้แยกไว้นอก repo (เช่น ในไฟล์ note ส่วนตัว หรือ password manager) เท่านั้น

## 10. Deployment
- Hosting ปัจจุบัน: IIS บน server ของมหาวิทยาลัย (www.medsci.up.ac.th) — มีสิทธิ์ล็อกอินจัดการ server ได้โดยตรง (admin access)
- เป้าหมาย: เชื่อมต่อ deploy อัตโนมัติผ่าน GitHub เมื่อ push โค้ดเข้า repo

### 10.1 แนวทาง CI/CD ที่แนะนำ: Self-hosted GitHub Actions Runner
เนื่องจากเป็น IIS บน server ภายในที่ GitHub (cloud) เข้าถึงโดยตรงไม่ได้ และเรามีสิทธิ์แอดมินบนเครื่อง วิธีที่ตรงไปตรงมาที่สุดคือติดตั้ง runner ไว้บนเครื่องนั้นเอง (runner เป็นฝ่ายเชื่อมต่อออกไปหา GitHub เอง ไม่ต้องเปิด inbound port ใดๆ)

**สิ่งที่ต้องติดตั้งเพิ่มบน server:**
- Git for Windows (สำหรับ clone/pull โค้ด)
- GitHub Actions self-hosted runner (ลงทะเบียนกับ repo `plugdkt/msc_research` แล้วรันเป็น Windows Service)
- ตรวจสอบ PHP version + extensions ที่แอปต้องใช้ให้ครบ (ปัจจุบันเว็บรันอยู่แล้วน่าจะครบ แต่ควร list ให้ชัดเมื่อเขียนโค้ดจริง)
- ให้สิทธิ์ (permission) แก่ runner service ในการเขียนไฟล์ลง physical path ของเว็บไซต์บน IIS

**สิ่งที่ต้องเตรียมฝั่ง GitHub repo:**
- ไฟล์ `.github/workflows/deploy.yml` — trigger เมื่อ push เข้า branch `main`, รันบน self-hosted runner, ทำหน้าที่ sync ไฟล์ไปยัง physical path ของเว็บไซต์ แล้ว recycle IIS App Pool
- **Branch protection บน `main`**: ต้องเปิด required review (อย่างน้อย 1 คน approve) ก่อน merge เข้า `main` ได้ — ป้องกันไม่ให้ push/PR ที่ไม่ผ่านการตรวจสอบไปรันบน self-hosted runner ที่มีสิทธิ์เขียนไฟล์ลง production ได้โดยตรง
- **ห้ามรัน workflow จาก fork/PR ภายนอกบน self-hosted runner** (ตั้งค่า `pull_request_target` หรือ approval gate ให้เฉพาะ collaborator ที่เชื่อถือได้เท่านั้นที่ trigger ได้)
- **GitHub Secrets** เก็บค่าที่อ่อนไหว (Scopus API key, DB credentials, SSO config) — ห้าม commit ค่าจริงลง repo ต้องแยกไฟล์ config ออกจากโค้ด (ใส่ใน `.gitignore`) แล้วให้ workflow generate/เขียนไฟล์ config นั้นตอน deploy โดยดึงค่าจาก Secrets
  - **ตำแหน่งไฟล์ config**: ต้องวางไว้ **นอก physical path ที่ IIS เสิร์ฟเป็นเว็บ** (เช่น อยู่ระดับโฟลเดอร์เหนือ webroot) หรือถ้าจำเป็นต้องอยู่ใน webroot ให้บล็อกการเข้าถึงผ่าน `web.config` (`<location>` + `<system.webServer><security><requestFiltering>` หรือ deny rule) เพื่อไม่ให้ไฟล์นี้ถูกเรียกดูตรงๆ ผ่าน URL ได้
- **Rollback plan**: เก็บโฟลเดอร์ release ก่อนหน้าไว้เสมอ (เช่น deploy แบบ release folder + symlink สลับ หรือสำรอง backup ก่อน sync ไฟล์ทับ) หาก recycle App Pool หรือ sync ไฟล์ล้มเหลวกลางคัน ให้ workflow สลับกลับไปใช้ release ก่อนหน้าอัตโนมัติ
- กำหนด branch strategy (เช่น push เข้า `main` = deploy production เลย หรือมี `staging` branch แยกก่อน)

- Environment variables ที่ต้องตั้งค่า: Scopus API key/credentials, ค่าเชื่อมต่อฐานข้อมูล MySQL, ค่าคอนฟิก SSO (เก็บเป็น GitHub Secrets ฝั่ง CI/CD และไฟล์ config ที่ไม่ commit บนเครื่อง server)

## 11. Timeline / Milestones
เป้าหมาย: นำเสนอโปรเจกต์วันที่ **3 กันยายน 2569** (เหลือเวลาทำงาน 16 วันนับจากวันนี้ 18 ส.ค. 2569)

| วันที่ | สิ่งที่ต้องเสร็จ |
|--------|-----------------|
| 18-19 ส.ค. 2569 | ยืนยัน Open Questions กับอาจารย์/ทีม IT (Scopus API field, SSO protocol, frontend framework, นโยบายรัน self-hosted runner) + ตรวจสอบ schema ฐานข้อมูลจริงของระบบเดิม |
| 20-23 ส.ค. 2569 | สร้างฐานข้อมูล MySQL ตาม Data Model (หัวข้อ 7) + เขียน Scopus sync job เบื้องต้น พร้อม error handling (หัวข้อ 6.1) |
| 24-26 ส.ค. 2569 | ทำ SDG mapping logic (ดึง weight, เลือก top-2, tie-break rule) + หน้า Dashboard หลัก |
| 27-29 ส.ค. 2569 | หน้าค้นหางานวิจัย + ทำเนียบนักวิจัย (พร้อม filter/pagination) + หน้ารายงานสรุป (reports.php) ทุกแท็บ |
| 30-31 ส.ค. 2569 | หน้า Admin (SSO login + sync trigger + sync lock) + ตั้งค่า CI/CD จริง (self-hosted runner, `.github/workflows/deploy.yml`) |
| 1 ก.ย. 2569 | ทดสอบระบบทั้งหมด โดยเฉพาะ edge case ที่ review ไว้ (หัวข้อ 6.1, 13) + แก้บั๊กที่พบ |
| 2 ก.ย. 2569 | เตรียม slide/สคริปต์นำเสนอ + rehearsal เดโมจริงบน production |
| **3 ก.ย. 2569** | **นำเสนอโปรเจกต์** |

## 12. Open Questions
คำถามที่เหลืออยู่นี้เป็นคำถามที่**ต้องถามอาจารย์/ทีม IT มหาวิทยาลัยโดยตรง** เท่านั้น เพราะไม่มีข้อมูลเพียงพอให้กำหนดเป็นค่าเริ่มต้นได้เอง (ต่างจากหัวข้อ 4.1/4.2/9/10 ที่ใส่ค่าเริ่มต้นที่สมเหตุสมผลไว้ให้แล้ว รอการยืนยัน/แก้ไขภายหลัง):
- ใช้ Scopus API แบบไหน (Scopus Search API / Author Retrieval API) และ field ที่ให้ค่า SDG weight มาคือ field ไหนโดยตรง
- ~~ระบบ SSO ที่ใช้คือของมหาวิทยาลัยพะเยาโดยตรงหรือไม่ ใช้ protocol อะไร~~ **ยืนยันแล้ว**: ใช้ระบบ MEDSCI ACC ของคณะเอง ผ่าน SSO Redirect + token verify API (ดูรายละเอียดหัวข้อ 9)
- Token จาก MEDSCI ACC มีอายุเท่าไหร่ และ `verify.php` เรียกซ้ำด้วย token เดิมได้กี่ครั้ง (ป้องกัน token replay) — คู่มือที่ได้รับมาไม่ได้ระบุไว้
- Frontend framework ที่ใช้จริงคืออะไร (Bootstrap/Tailwind/plain CSS)
- มีการ export ข้อมูลเป็น Excel/PDF จากหน้ารายงานหรือไม่
- ทาง IT มหาวิทยาลัยอนุญาตให้ติดตั้ง background service (self-hosted runner) รันค้างไว้บนเครื่อง server ได้หรือไม่ (บางหน่วยงานมีนโยบายจำกัด)
- ปัจจุบันไฟล์ config ที่เก็บ DB credentials/Scopus API key บนเว็บที่รันอยู่ตอนนี้ อยู่ในรูปแบบไหน (เช่น config.php แยกไฟล์ หรือฝังในโค้ด) เพื่อวางแผนแยกออกจาก git repo ให้ถูกต้อง

## 13. Default Decisions ที่กำหนดไว้ให้ (รอการยืนยัน)
รายการนี้สรุปทุกจุดที่สเปกฉบับนี้ **"เดาแบบมีเหตุผล" (reasonable default)** ไว้แทนที่จะปล่อยเป็นคำถามเปิด เพื่อให้เขียนโค้ด/เขียน test ต่อได้ทันที แต่ยังไม่ใช่ข้อเท็จจริงที่ยืนยันแล้ว — ควรตรวจทานกับอาจารย์/เจ้าของระบบอีกครั้งก่อน implement จริง:
1. ค้นหางานวิจัยจากฟิลด์ title + author เท่านั้น (ไม่รวม abstract)
2. Pagination 20 รายการ/หน้า ทั้งหน้าค้นหาและทำเนียบนักวิจัย
3. เรียงลำดับทำเนียบนักวิจัยแบบ descending เป็นค่าเริ่มต้น
4. นักวิจัยที่พ้นสภาพ: ซ่อนจากทำเนียบ แต่ผลงานเก่ายังนับในสถิติรวม
5. ผลงานที่มีผู้แต่งร่วมหลายภาควิชา: นับซ้ำได้ทุกภาควิชา
6. ความร่วมมือหลายประเทศ: นับเครดิตเต็มทุกประเทศ ไม่หารเฉลี่ย
7. SDG weight เป็น float 0.0–1.0, tie-break ด้วยรหัส SDG น้อยไปมาก
8. ไม่พบ SDG ที่เกี่ยวข้อง: จัดเป็นหมวด "Unclassified" แยกต่างหาก
9. Re-sync SDG mapping: คำนวณใหม่ทั้งหมด เขียนทับค่าเดิม ไม่เก็บ version ประวัติ
10. Scopus API error: retry 3 ครั้งแบบ exponential backoff แล้วยกเลิกทั้ง batch หากยัง fail
11. Session SSO อายุ 1-2 ชั่วโมง
12. Performance target: dashboard ≤2s, reports ≤3s ที่ขนาดข้อมูลปัจจุบัน
13. **เลือกใช้ MEDSCI ACC แบบ SSO Redirect (Method 1) เท่านั้น ไม่ใช้ Direct API Auth (Method 2)** — เป็นการตัดสินใจเชิงความปลอดภัย ไม่ใช่การเดา (ระบบเราจะได้ไม่ต้องสัมผัสรหัสผ่านจริงของผู้ใช้เลย)
