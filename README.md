# MSC Research Repository

ระบบคลังผลงานวิจัยของบุคลากร คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา — ซิงค์ข้อมูลผลงานตีพิมพ์จาก Scopus มาแสดงเป็นแดชบอร์ด, ค้นหางานวิจัย, ทำเนียบนักวิจัย, รายงานวิเคราะห์ และ mapping ผลงานเข้ากับ SDGs

## เอกสารโปรเจกต์
รายละเอียดทั้งหมด (requirements, data model, security, deployment, timeline, testing checklist) อยู่ใน **[SPEC.md](./SPEC.md)**

## Tech Stack
- Backend: PHP
- Database: MySQL
- External API: Scopus API (ผลงานตีพิมพ์, citations, SDG data)
- Auth: MEDSCI ACC (SSO กลางของคณะ)
- Hosting: IIS (server ของมหาวิทยาลัย)

## สถานะปัจจุบัน
กำลังอยู่ในขั้นตอนวางแผน/เขียนสเปก ก่อนเริ่ม implementation — ดู [Timeline](./SPEC.md#11-timeline--milestones) และ [Open Questions](./SPEC.md#12-open-questions) ใน SPEC.md
