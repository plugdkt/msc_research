<?php
// admin/expert_finder.php
// Phase 10 (SEM-05): UI for the LLM-ranked expert finder. Backend is
// admin/expert_finder_llm.php (action=search) - this page is just the
// search box + results rendering, following the same admin-page-plus-
// JS-driver shape as sdg_import.php/topics_import.php.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'admin_expert_finder';
$page_title = 'ค้นหาผู้เชี่ยวชาญ (AI)';

require_once __DIR__ . '/admin_header.php';

try {
    $llm_classified_pubs = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE llm_semantic_tags IS NOT NULL AND llm_semantic_tags != '' AND llm_semantic_tags != '[]'")->fetchColumn();
} catch (PDOException $e) {
    $llm_classified_pubs = 0;
}
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2><i class="fa-solid fa-magnifying-glass-chart" style="color: #8b5cf6;"></i> ค้นหาผู้เชี่ยวชาญด้วย AI (Semantic Expert Finder)</h2>
    <p>พิมพ์คำถามเป็นภาษาไทยหรือภาษาอังกฤษ ระบบจะให้ LLM จัดอันดับนักวิจัยที่มีผลงานตรงกับคำถามมากที่สุด พร้อมผลงานที่ใช้เป็นหลักฐานประกอบ (Phase 10 - UP AI Connect)</p>
</div>

<?php if ($llm_classified_pubs === 0): ?>
    <div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 30px; border: 1px solid rgba(245, 158, 11, 0.3); background: rgba(245, 158, 11, 0.05);">
        <div style="display:flex; align-items:center; gap:10px; color:#f59e0b; font-weight:600;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <span>ยังไม่มีผลงานที่ผ่านการวิเคราะห์ด้วย Zero-Shot LLM</span>
        </div>
        <p style="font-size: 0.85rem; color: var(--color-text-muted); margin-top: 8px;">
            กรุณาไปที่ <a href="sdg_import.php" style="color: #8b5cf6;">จัดการ SDGs (ผลงานวิจัย)</a> แล้วกด "เริ่ม LLM Classify" ก่อน เพื่อสร้าง semantic tags ที่ใช้ในการค้นหานี้
        </p>
    </div>
<?php else: ?>
    <div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 30px;">
        <p style="font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 15px;">
            ผลงานที่พร้อมใช้ค้นหา: <strong style="color: #8b5cf6;"><?php echo number_format($llm_classified_pubs); ?></strong> รายการ
        </p>
        <div style="display: flex; gap: 10px;">
            <input type="text" id="expert-search-input" class="search-input" placeholder="เช่น ใครทำวิจัยเรื่องสารต้านอนุมูลอิสระจากพืชสมุนไพร" style="flex: 1;">
            <button type="button" id="expert-search-btn" class="btn-premium" style="padding: 10px 24px; background: rgba(139, 92, 246, 0.15); border-color: rgba(139, 92, 246, 0.4); color: #a78bfa;">
                <i class="fa-solid fa-magnifying-glass"></i> ค้นหา
            </button>
        </div>
    </div>

    <div id="expert-search-status" style="display:none; margin-bottom: 20px; font-size: 0.85rem; color: var(--color-text-muted);"></div>

    <div id="expert-search-results" style="display: flex; flex-direction: column; gap: 15px;"></div>
<?php endif; ?>

<script>
const CSRF_TOKEN = "<?php echo htmlspecialchars(get_csrf_token()); ?>";
document.addEventListener('DOMContentLoaded', () => {
    const input = document.getElementById('expert-search-input');
    const btn = document.getElementById('expert-search-btn');
    const statusEl = document.getElementById('expert-search-status');
    const resultsEl = document.getElementById('expert-search-results');
    if (!btn) return;

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    }

    async function runSearch() {
        const q = input.value.trim();
        if (!q) return;

        btn.disabled = true;
        statusEl.style.display = 'block';
        statusEl.textContent = 'กำลังค้นหา...';
        resultsEl.innerHTML = '';

        try {
            const resp = await fetch('expert_finder_llm.php?action=search&q=' + encodeURIComponent(q), {
                headers: { 'X-CSRF-Token': CSRF_TOKEN }
            });
            const data = await resp.json();

            if (data.error) {
                statusEl.textContent = 'เกิดข้อผิดพลาด: ' + data.error;
                btn.disabled = false;
                return;
            }

            if (!data.researchers || data.researchers.length === 0) {
                statusEl.textContent = 'ไม่พบนักวิจัยที่ตรงกับคำค้นหานี้';
                btn.disabled = false;
                return;
            }

            statusEl.textContent = 'พบ ' + data.researchers.length + ' คน สำหรับ "' + q + '"';

            resultsEl.innerHTML = data.researchers.map(r => `
                <div class="glass-panel" style="padding: 20px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                        <div>
                            <div style="font-weight:700; font-size:1.05rem;">${escapeHtml(r.name)}</div>
                            ${r.department ? `<div style="font-size:0.8rem; color:var(--color-text-muted);">${escapeHtml(r.department)}</div>` : ''}
                        </div>
                        <span style="background: rgba(139, 92, 246, 0.15); color:#a78bfa; font-weight:700; padding:4px 12px; border-radius:14px; font-size:0.85rem;">
                            ${r.best_score}% ตรงกัน
                        </span>
                    </div>
                    <div style="margin-top:12px; display:flex; flex-direction:column; gap:8px;">
                        ${r.matching_publications.map(p => `
                            <div style="font-size:0.82rem; padding:10px 12px; background:rgba(255,255,255,0.02); border:1px solid var(--border-glass); border-radius:8px;">
                                <div style="font-weight:600;">${escapeHtml(p.title)}</div>
                                <div style="color:#8b5cf6; margin-top:4px;"><i class="fa-solid fa-robot"></i> ${escapeHtml(p.reason)} (${p.relevance_score}%)</div>
                            </div>
                        `).join('')}
                    </div>
                </div>
            `).join('');
        } catch (e) {
            statusEl.textContent = 'เชื่อมต่อไม่สำเร็จ กรุณาลองใหม่';
        }
        btn.disabled = false;
    }

    btn.addEventListener('click', runSearch);
    input.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') runSearch();
    });
});
</script>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
