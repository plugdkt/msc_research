# SPEC.md

## 1. Overview
- ชื่อโปรเจกต์: ระบบคลังผลงานวิจัย (MSC Research Repository)
- สรุปสั้นๆ: ระบบเก็บและแสดงผลข้อมูลผลงานตีพิมพ์วิจัยของบุคลากรคณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา โดยดึงข้อมูลมาจากฐานข้อมูล Scopus มาซิงค์เก็บไว้ในระบบ เพื่อให้บุคลากร ผู้บริหาร และบุคคลทั่วไปสามารถค้นหา วิเคราะห์ และดูสถิติผลงานวิจัยของคณะ/รายบุคคล/รายภาควิชาได้

## 2. Goals
- เป้าหมายหลักที่ต้องทำให้ได้:
  1. รวบรวมและซิงค์ข้อมูลผลงานตีพิมพ์ของบุคลากรจาก Scopus มาเก็บไว้ในฐานข้อมูลของระบบเอง
  2. แสดงแดชบอร์ดภาพรวม (จำนวนนักวิจัย, ผลงานตีพิมพ์, citations, สัดส่วน Scopus quartile)
  3. ให้ค้นหา/กรองผลงานวิจัยและรายชื่อนักวิจัยได้ตามเงื่อนไขต่างๆ
  4. สร้างรายงานวิเคราะห์เชิงลึกทั้งระดับคณะ/ภาควิชา/รายบุคคล
  5. Mapping ผลงานวิจัยแต่ละชิ้นเข้ากับ SDGs (Sustainable Development Goals) โดยดึงข้อมูลจาก Scopus มาประมวลผล แล้วเลือก SDG ที่มีน้ำหนัก (weight/relevance score) สูงสุด 2 อันดับแรกมาผูกกับผลงานนั้น เพื่อให้ระบบ tracking ได้ว่าแต่ละผลงานเกี่ยวข้องกับ SDGs ข้อใดบ้าง

## 3. Non-goals
- สิ่งที่ตั้งใจ "ไม่ทำ" ในเวอร์ชันนี้:
  - ไม่ทำระบบบันทึกผลงานวิจัยด้วยมือ (manual entry) — ข้อมูลผลงานมาจากการซิงค์ Scopus เท่านั้น
  - ไม่ครอบคลุมฐานข้อมูลอ้างอิงอื่นนอกจาก Scopus (เช่น Web of Science, Google Scholar) ในเวอร์ชันนี้

## 4. Requirements

### 4.1 Functional Requirements
- **แดชบอร์ดหลัก**: แสดงจำนวนนักวิจัยทั้งหมด, ผลงานตีพิมพ์ทั้งหมด, จำนวน citations รวม, สัดส่วน Scopus quartile (Q1-Q4 + unclassified), ผู้มีผลงานตีพิมพ์/citations/h-index สูงสุด, สถิติการตีพิมพ์รายปี, ผลงานตีพิมพ์ล่าสุด (พร้อมชื่อผู้แต่ง, วารสาร, DOI, quartile, จำนวน citation, แหล่งทุนวิจัย, ประเทศร่วมวิจัย)
- **ค้นหางานวิจัย**: ค้นหาผลงานตีพิมพ์ด้วยคำค้น และกรองตาม Scopus quartile ได้
- **ทำเนียบนักวิจัย**: แสดงรายชื่อนักวิจัยทั้งหมด กรองตามภาควิชา (Anatomy, Biochemistry, Microbiology, Nutrition and Dietetics, Physiology) และประเภทบุคลากร (สายวิชาการ/สายสนับสนุน) เรียงลำดับตามจำนวนผลงาน/citations/h-index ได้ แต่ละคนแสดงชื่อไทย-อังกฤษ, ภาควิชา, Scopus ID, จำนวนผลงาน, citations รวม, h-index
- **รายงานสรุป**: กรองตามภาควิชา/ปีที่ตีพิมพ์/ประเภทบุคลากร แสดงแท็บวิเคราะห์หลายมุม ได้แก่ ภาพรวมแนวโน้ม, สรุปแยกภาควิชา, สรุปตาม Quartiles, ความร่วมมือระหว่างประเทศ, แหล่งทุนวิจัย, สถิติตาม SDGs, จัดอันดับนักวิจัย, สถิติรายปี (จำนวนผลงาน+citations+เฉลี่ยผลงานต่อคน), สัดส่วนแหล่งเผยแพร่ (sources), บทบาทผู้เขียน (author roles)
- **ระบบ Admin**: ล็อกอินผ่าน Single Sign-On (SSO) เพื่อจัดการ/สั่งซิงค์ข้อมูลจาก Scopus (เห็น timestamp "ซิงค์ข้อมูลล่าสุด" แสดงบนหน้าสาธารณะ)
- **SDG Mapping**: หลังซิงค์ข้อมูลผลงานจาก Scopus แล้ว ระบบต้องประมวลผล mapping ผลงานแต่ละชิ้นเข้ากับ SDGs (17 เป้าหมาย) โดย:
  - ดึง/คำนวณค่าน้ำหนัก (weight/relevance score) ของแต่ละ SDG ที่เกี่ยวข้องกับผลงานนั้น จากข้อมูล Scopus
  - เลือก SDG ที่มีน้ำหนักสูงสุด **2 อันดับแรก** มาผูก (tag) กับผลงานชิ้นนั้น
  - แสดงผล/tracking ได้ว่าผลงานแต่ละชิ้นสังกัดอยู่ใน SDG ข้อใดบ้าง (2 ข้อ) ทั้งในหน้ารายละเอียดผลงานและในหน้ารายงานสรุป (เดิมมีแท็บ "สถิติตาม SDGs" อยู่แล้วในหน้า reports.php — ต้องอิงข้อมูลจาก mapping ชุดนี้)

### 4.2 Non-functional Requirements
- Performance: หน้าแดชบอร์ด/รายงานต้องโหลดข้อมูลสรุป (aggregate) ได้เร็ว แม้มีผลงานหลักร้อย/พันรายการ
- Security: ส่วน Admin ต้องผ่าน SSO เท่านั้น ห้ามเข้าถึงฟังก์ชันซิงค์/แก้ไขข้อมูลได้โดยไม่ล็อกอิน
- Availability: ข้อมูลสาธารณะ (แดชบอร์ด/ค้นหา/ทำเนียบนักวิจัย/รายงาน) ต้องเข้าถึงได้โดยไม่ต้องล็อกอิน

## 5. Tech Stack
- Frontend: (ระบุ - จากการสำรวจหน้าเว็บเป็น server-rendered PHP, ยังไม่ยืนยัน framework CSS/JS ที่ใช้)
- Backend: PHP (สังเกตจาก URL เช่น index.php, publications_search.php, researchers_list.php, reports.php, admin/index.php)
- Database: MySQL (ตามพื้นฐานที่ถนัด)
- External API: Scopus API (สำหรับดึงข้อมูลผลงานตีพิมพ์, citations, Scopus ID, quartile ของวารสาร)
- Deployment/Hosting: Server ของคณะ/มหาวิทยาลัย (www.medsci.up.ac.th)

## 6. Architecture
- ภาพรวม: ระบบ Admin (หลัง SSO) สั่งซิงค์ข้อมูลจาก Scopus API → บันทึก/อัปเดตลงฐานข้อมูล MySQL → หน้าเว็บสาธารณะ (PHP) ดึงข้อมูลจาก MySQL มาแสดงผลแบบ real-time หลังซิงค์เสร็จ
- ส่วนประกอบหลัก:
  1. Public-facing pages (dashboard, search, researcher list, reports)
  2. Admin panel (SSO auth, data sync trigger)
  3. Scopus sync service/job
  4. MySQL database

## 7. Data Model
- ตาราง/entity หลัก (สันนิษฐานจากข้อมูลที่แสดงบนหน้าเว็บ - ควรตรวจสอบกับโครงสร้างจริง):
  - `researchers` (นักวิจัย): ชื่อไทย, ชื่ออังกฤษ, ตำแหน่งวิชาการ, ภาควิชา, ประเภทบุคลากร, Scopus Author ID, h-index
  - `publications` (ผลงานตีพิมพ์): ชื่อเรื่อง, ผู้แต่ง, วารสาร, ปีที่ตีพิมพ์, DOI, Scopus quartile, จำนวน citations, แหล่งทุนวิจัย, ประเทศร่วมวิจัย
  - `publication_authors` (ตารางเชื่อม many-to-many ระหว่าง publications และ researchers, พร้อม author role)
  - `sync_log` (ประวัติการซิงค์ข้อมูลจาก Scopus, timestamp ล่าสุด)
  - `sdgs` (ตารางอ้างอิง 17 เป้าหมาย SDGs: รหัส, ชื่อ, คำอธิบาย)
  - `publication_sdgs` (ตารางเชื่อม many-to-many ระหว่าง publications และ sdgs, เก็บ `weight`/`relevance_score` และ `rank` (1 หรือ 2) — จำกัดไว้แค่ 2 แถวต่อผลงาน 1 ชิ้น ตามน้ำหนักสูงสุด 2 อันดับแรก)
- ความสัมพันธ์: นักวิจัย 1 คน มีผลงานได้หลายชิ้น, ผลงาน 1 ชิ้น มีผู้แต่งร่วมได้หลายคน (many-to-many ผ่าน publication_authors), ผลงาน 1 ชิ้น ผูกกับ SDG ได้สูงสุด 2 ข้อ (many-to-many ผ่าน publication_sdgs พร้อม weight/rank)

## 8. API / Endpoints
| Method | Path | รายละเอียด | Auth ต้องการไหม |
|--------|------|-----------|-----------------|
| GET | /index.php | แดชบอร์ดหลัก | ไม่ต้อง |
| GET | /publications_search.php | ค้นหา/กรองผลงานวิจัย (รองรับ query param เช่น `?quartile=Q1`) | ไม่ต้อง |
| GET | /researchers_list.php | ทำเนียบนักวิจัย พร้อมตัวกรอง | ไม่ต้อง |
| GET | /reports.php | รายงานสรุปวิเคราะห์ (รองรับ query param เช่น `?tab=countries`) | ไม่ต้อง |
| GET/POST | /admin/index.php | หน้าล็อกอิน SSO + จัดการซิงค์ข้อมูล | ต้อง (SSO) |

## 9. Authentication & Security
- วิธียืนยันตัวตน: Single Sign-On (SSO) สำหรับส่วน Admin เท่านั้น (สันนิษฐานว่าเชื่อมกับระบบ SSO ของมหาวิทยาลัย)
- การจัดการ token/session: (ต้องตรวจสอบจากโค้ดจริง - ยังไม่ทราบว่าใช้ session PHP ปกติ หรือ JWT/OAuth)
- ประเด็นความปลอดภัยที่ต้องระวัง:
  - การเก็บ Scopus API key ต้องไม่ hardcode ในโค้ด ควรเก็บใน env/config แยก
  - ต้องป้องกัน SQL Injection ในหน้าค้นหา/กรองข้อมูล (query params หลายจุด)
  - จำกัดสิทธิ์การสั่งซิงค์ข้อมูลให้เฉพาะผู้ที่ล็อกอินผ่าน SSO เท่านั้น

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
- **GitHub Secrets** เก็บค่าที่อ่อนไหว (Scopus API key, DB credentials, SSO config) — ห้าม commit ค่าจริงลง repo ต้องแยกไฟล์ config ออกจากโค้ด (ใส่ใน `.gitignore`) แล้วให้ workflow generate/เขียนไฟล์ config นั้นตอน deploy โดยดึงค่าจาก Secrets
- กำหนด branch strategy (เช่น push เข้า `main` = deploy production เลย หรือมี `staging` branch แยกก่อน)

- Environment variables ที่ต้องตั้งค่า: Scopus API key/credentials, ค่าเชื่อมต่อฐานข้อมูล MySQL, ค่าคอนฟิก SSO (เก็บเป็น GitHub Secrets ฝั่ง CI/CD และไฟล์ config ที่ไม่ commit บนเครื่อง server)

## 11. Timeline / Milestones
| วันที่ | สิ่งที่ต้องเสร็จ |
|--------|-----------------|
|        |                 |

## 12. Open Questions
- ใช้ Scopus API แบบไหน (Scopus Search API / Author Retrieval API) และมี rate limit อย่างไร
- การซิงค์ข้อมูลทำงานแบบ manual (กดปุ่มใน admin) หรือมี cron job ตั้งเวลาอัตโนมัติด้วย
- ระบบ SSO ที่ใช้คือของมหาวิทยาลัยพะเยาโดยตรงหรือไม่ ใช้ protocol อะไร (SAML/OAuth/CAS)
- Frontend framework ที่ใช้จริงคืออะไร (Bootstrap/Tailwind/plain CSS)
- มีการ export ข้อมูลเป็น Excel/PDF จากหน้ารายงานหรือไม่
- Scopus ให้ค่า SDG weight/relevance score มาโดยตรงในฟิลด์ไหน (เช่น Scopus's own SDG mapping ผ่าน Elsevier Fingerprint Engine) หรือต้องคำนวณเองจาก keyword/abstract
- ถ้าผลงานมี SDG ที่น้ำหนักเท่ากันในอันดับ 2-3 จะตัดสินใจเลือกตัวไหนด้วยเกณฑ์อะไร (tie-breaking rule)
- ต้องรองรับการ mapping ซ้ำ (re-run) เมื่อ Scopus อัปเดตข้อมูล SDG ภายหลังหรือไม่
- ทาง IT มหาวิทยาลัยอนุญาตให้ติดตั้ง background service (self-hosted runner) รันค้างไว้บนเครื่อง server ได้หรือไม่ (บางหน่วยงานมีนโยบายจำกัด)
- ปัจจุบันไฟล์ config ที่เก็บ DB credentials/Scopus API key บนเว็บที่รันอยู่ตอนนี้ อยู่ในรูปแบบไหน (เช่น config.php แยกไฟล์ หรือฝังในโค้ด) เพื่อวางแผนแยกออกจาก git repo ให้ถูกต้อง
