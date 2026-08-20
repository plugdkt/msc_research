<?php
// admin/rcr_import.php
// Phase 7 (RCR Integration): status panel + one-click batch resolution
// tool for NIH iCite's Relative Citation Ratio. Mirrors
// admin/topics_import.php's shape exactly (status panel, batch button,
// separate Refresh button, JS driver hitting admin/fetch_rcr.php's
// action=list/action=process) - this is RCR-06's primary mechanism. The
// secondary (on-demand per-publication) mechanism lives in
// admin/publications.php's edit form instead.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'admin_rcr';
$page_title = 'จัดการ RCR (NIH iCite)';

require_once __DIR__ . '/admin_header.php';

try {
    $total_pubs = (int)$pdo->query("SELECT COUNT(*) FROM `publications`")->fetchColumn();
    $pubs_with_doi = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE doi IS NOT NULL AND doi != ''")->fetchColumn();
    $pubs_checked = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE rcr_checked_at IS NOT NULL")->fetchColumn();
    $pubs_with_rcr = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE rcr IS NOT NULL")->fetchColumn();
} catch (PDOException $e) {
    $total_pubs = $pubs_with_doi = $pubs_checked = $pubs_with_rcr = 0;
}
$pubs_pending = max(0, $pubs_with_doi - $pubs_checked);
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>Relative Citation Ratio (RCR) — NIH iCite</h2>
    <p>ดึงค่า Relative Citation Ratio และ NIH Percentile จาก NIH iCite ให้กับผลงานวิจัย โดยแปลง DOI เป็น PubMed ID (PMID) ก่อนอัตโนมัติผ่าน NCBI ID Converter — ผลงานที่ไม่ได้อยู่ใน PubMed (เช่น วารสารที่ไม่ใช่สายชีวการแพทย์) จะไม่มีค่า RCR ให้ ซึ่งเป็นข้อจำกัดของ PubMed เอง ไม่ใช่ข้อผิดพลาดของระบบ</p>
</div>

<div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 30px;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px;">
        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">ผลงานทั้งหมด</div>
            <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format($total_pubs); ?></div>
        </div>
        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">มี DOI (ดึงข้อมูลได้)</div>
            <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format($pubs_with_doi); ?></div>
        </div>
        <div style="background: rgba(16, 185, 129, 0.06); border: 1px solid rgba(16, 185, 129, 0.2); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">มีค่า RCR แล้ว</div>
            <div id="kpi-rcr-pubs" style="font-size: 1.5rem; font-weight: 700; color: #10b981; font-family: var(--font-eng);"><?php echo number_format($pubs_with_rcr); ?></div>
        </div>
        <div style="background: rgba(239, 68, 68, 0.06); border: 1px solid rgba(239, 68, 68, 0.2); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">ยังไม่เคยดึงข้อมูล</div>
            <div id="kpi-pending-pubs" style="font-size: 1.5rem; font-weight: 700; color: #f87171; font-family: var(--font-eng);"><?php echo number_format($pubs_pending); ?></div>
        </div>
    </div>

    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 16px 18px; border-radius: 10px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <div style="font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-chart-line" style="color: var(--color-accent);"></i>
                    <span>ดึงค่า RCR อัตโนมัติ (NIH iCite)</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--color-text-muted); margin: 4px 0 0 0; line-height: 1.4;">
                    ดึงข้อมูลเฉพาะผลงานที่มี DOI และยังไม่เคยดึงข้อมูลมาก่อน ผลงานที่ไม่มี DOI หรือไม่พบ PMID/RCR จะถูกข้ามอย่างสุภาพ ไม่ถือเป็นข้อผิดพลาด
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-shrink:0;">
                <button type="button" id="rcr-start-btn" class="btn-premium" style="padding: 10px 18px;" <?php echo $pubs_pending === 0 ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-play"></i> เริ่มดึงค่า RCR
                </button>
                <button type="button" id="rcr-refresh-btn" class="btn-premium" style="padding: 10px 18px; background: rgba(255,255,255,0.05);" <?php echo $pubs_with_doi === 0 ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-rotate"></i> Refresh ทั้งหมด
                </button>
                <button type="button" id="rcr-stop-btn" class="btn-premium" style="padding: 10px 18px; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #f87171; display: none;">
                    <i class="fa-solid fa-stop"></i> หยุด
                </button>
            </div>
        </div>

        <div id="rcr-progress" style="display: none; margin-top: 18px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 6px;">
                <span id="rcr-status-text">กำลังเริ่มต้น...</span>
                <span id="rcr-counter">0 / 0</span>
            </div>
            <div style="background: rgba(255,255,255,0.05); border-radius: 8px; height: 10px; overflow: hidden;">
                <div id="rcr-bar" style="background: linear-gradient(90deg, var(--color-primary), var(--color-accent)); height: 100%; width: 0%; transition: width 0.2s ease;"></div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin-top: 12px;">
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#10b981;" id="rcr-applied-count">0</div>ดึงค่าสำเร็จ</div>
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#94a3b8;" id="rcr-nomatch-count">0</div>ไม่พบ PMID/RCR</div>
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#94a3b8;" id="rcr-skipped-count">0</div>ไม่มี DOI (ข้าม)</div>
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#f87171;" id="rcr-error-count">0</div>ผิดพลาด</div>
            </div>
            <div id="rcr-log" style="margin-top: 12px; max-height: 180px; overflow-y: auto; font-size: 0.75rem; color: var(--color-text-muted); background: rgba(0,0,0,0.15); border-radius: 8px; padding: 10px; display: flex; flex-direction: column-reverse; gap: 4px;"></div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const startBtn = document.getElementById('rcr-start-btn');
    const refreshBtn = document.getElementById('rcr-refresh-btn');
    const stopBtn = document.getElementById('rcr-stop-btn');
    if (!startBtn) return;

    const progressEl = document.getElementById('rcr-progress');
    const statusText = document.getElementById('rcr-status-text');
    const counterEl = document.getElementById('rcr-counter');
    const barEl = document.getElementById('rcr-bar');
    const logEl = document.getElementById('rcr-log');
    const countEls = {
        applied: document.getElementById('rcr-applied-count'),
        no_match: document.getElementById('rcr-nomatch-count'),
        skipped: document.getElementById('rcr-skipped-count'),
        error: document.getElementById('rcr-error-count'),
    };

    let stopRequested = false;

    function log(text, color) {
        const div = document.createElement('div');
        if (color) div.style.color = color;
        div.textContent = text;
        logEl.prepend(div);
    }

    async function runBatch(mode) {
        const confirmMsg = mode === 'refresh'
            ? 'ระบบจะดึงค่า RCR ใหม่จาก NIH iCite ให้กับผลงานที่มี DOI ทั้งหมด (รวมที่เคยดึงแล้ว) ยืนยันที่จะเริ่มหรือไม่?'
            : 'ระบบจะดึงค่า RCR จาก NIH iCite ให้กับผลงานที่ยังไม่เคยดึง ยืนยันที่จะเริ่มหรือไม่?';
        if (!confirm(confirmMsg)) return;

        stopRequested = false;
        startBtn.style.display = 'none';
        refreshBtn.style.display = 'none';
        stopBtn.style.display = 'inline-flex';
        progressEl.style.display = 'block';
        counterEl.textContent = '0 / 0';
        barEl.style.width = '0%';
        logEl.innerHTML = '';
        Object.values(countEls).forEach(el => el.textContent = '0');
        const counts = { applied: 0, no_match: 0, skipped: 0, error: 0 };

        statusText.textContent = 'กำลังดึงรายชื่อผลงาน...';
        let items;
        try {
            const listResp = await fetch('fetch_rcr.php?action=list' + (mode === 'refresh' ? '&mode=refresh' : ''));
            const listData = await listResp.json();
            if (listData.error) {
                statusText.textContent = 'เกิดข้อผิดพลาด: ' + listData.error;
                startBtn.style.display = 'inline-flex';
                refreshBtn.style.display = 'inline-flex';
                stopBtn.style.display = 'none';
                return;
            }
            items = listData.items;
        } catch (e) {
            statusText.textContent = 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่';
            startBtn.style.display = 'inline-flex';
            refreshBtn.style.display = 'inline-flex';
            stopBtn.style.display = 'none';
            return;
        }

        const total = items.length;
        for (let i = 0; i < total; i++) {
            if (stopRequested) {
                log('--- หยุดโดยผู้ใช้ ---', '#f59e0b');
                break;
            }
            const item = items[i];
            counterEl.textContent = (i + 1) + ' / ' + total;
            barEl.style.width = Math.round(((i + 1) / total) * 100) + '%';
            statusText.textContent = 'กำลังประมวลผล: ' + (item.title || ('#' + item.id));

            try {
                const resp = await fetch('fetch_rcr.php?action=process&id=' + encodeURIComponent(item.id));
                const data = await resp.json();
                const status = data.status || 'error';
                counts[status] = (counts[status] || 0) + 1;
                if (countEls[status]) countEls[status].textContent = counts[status];

                if (status === 'applied') {
                    log('✓ #' + item.id + ' → RCR ' + data.rcr + (data.nih_percentile !== null ? ' (เปอร์เซ็นไทล์ ' + data.nih_percentile + ')' : ''), '#10b981');
                } else if (status === 'no_match') {
                    log('… #' + item.id + ' ' + (data.reason || 'ไม่พบข้อมูล'), '#94a3b8');
                } else if (status === 'skipped') {
                    log('- #' + item.id + ' ข้าม: ' + (data.reason || 'skipped'), '#94a3b8');
                } else {
                    log('✗ #' + item.id + ' ผิดพลาด: ' + (data.reason || data.error || 'unknown'), '#f87171');
                }
            } catch (e) {
                counts.error++;
                countEls.error.textContent = counts.error;
                log('✗ #' + item.id + ' เชื่อมต่อไม่สำเร็จ', '#f87171');
            }
        }

        statusText.textContent = stopRequested ? 'หยุดกระบวนการแล้ว' : 'ประมวลผลเสร็จสิ้นสมบูรณ์';
        stopBtn.style.display = 'none';
        startBtn.style.display = 'inline-flex';
        refreshBtn.style.display = 'inline-flex';

        const kpiRcr = document.getElementById('kpi-rcr-pubs');
        const kpiPending = document.getElementById('kpi-pending-pubs');
        if (kpiRcr && kpiPending && mode !== 'refresh') {
            let currentRcr = parseInt(kpiRcr.textContent.replace(/,/g, ''), 10) || 0;
            let currentPending = parseInt(kpiPending.textContent.replace(/,/g, ''), 10) || 0;
            kpiRcr.textContent = (currentRcr + counts.applied).toLocaleString();
            kpiPending.textContent = Math.max(0, currentPending - counts.applied - counts.no_match - counts.skipped - counts.error).toLocaleString();
        }
    }

    startBtn.addEventListener('click', () => runBatch('initial'));
    refreshBtn.addEventListener('click', () => runBatch('refresh'));
    stopBtn.addEventListener('click', () => {
        stopRequested = true;
        statusText.textContent = 'กำลังหยุด...';
    });
});
</script>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
