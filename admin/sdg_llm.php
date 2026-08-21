<?php
// admin/sdg_llm.php
// Phase 10 (Zero-Shot LLM AI Layer): status panel + one-click batch
// classification tool using UP AI Connect Gateway (gpt-5.4-mini).

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'admin_sdgs_llm';
$page_title = 'วิเคราะห์ SDGs ด้วย AI (UP AI Connect)';

require_once __DIR__ . '/admin_header.php';

try {
    $total_pubs = (int)$pdo->query("SELECT COUNT(*) FROM `publications`")->fetchColumn();
    $pubs_with_abstract = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE abstract IS NOT NULL AND abstract != '' AND abstract != 'Article'")->fetchColumn();
    $pubs_checked = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE llm_checked_at IS NOT NULL")->fetchColumn();
    $pubs_classified = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE llm_sdg_primary IS NOT NULL")->fetchColumn();
} catch (PDOException $e) {
    $total_pubs = $pubs_with_abstract = $pubs_checked = $pubs_classified = 0;
}
$pubs_pending = max(0, $pubs_with_abstract - $pubs_checked);

// Check UP AI Connect Status
$ai_configured = defined('UP_AI_CONNECT_BASE_URL') && defined('UP_AI_CONNECT_API_KEY') && !empty(UP_AI_CONNECT_BASE_URL) && !empty(UP_AI_CONNECT_API_KEY);

// Fetch recent LLM-classified sample
$stmtSample = $pdo->query("
    SELECT id, title, publish_year, llm_sdg_primary, llm_sdg_confidence_primary, 
           llm_sdg_secondary, llm_sdg_confidence_secondary, llm_rationale, 
           llm_semantic_tags, llm_model, llm_classified_at
    FROM `publications`
    WHERE llm_sdg_primary IS NOT NULL
    ORDER BY llm_classified_at DESC, id DESC
    LIMIT 10
");
$recent_samples = $stmtSample ? $stmtSample->fetchAll() : [];
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2><i class="fa-solid fa-brain" style="color: #a855f7; margin-right: 8px;"></i> วิเคราะห์ความสอดคล้อง SDGs ด้วย AI (UP AI Connect)</h2>
    <p>ใช้โมเดลปัญญาประดิษฐ์ระดับสูง (Zero-Shot LLM Reasoning: gpt-5.4-mini) ผ่านเครือข่าย UP AI Connect ของมหาวิทยาลัย เพื่อจำแนกเป้าหมาย SDGs พร้อมวิเคราะห์เหตุผลภาษาไทยและสกัดแท็กความเชี่ยวชาญ (Semantic Tags)</p>
</div>

<!-- KPI Cards -->
<div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 30px;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: 25px;">
        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">ผลงานทั้งหมด</div>
            <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format($total_pubs); ?></div>
        </div>
        <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">มี Abstract (พร้อมวิเคราะห์)</div>
            <div style="font-size: 1.5rem; font-weight: 700; font-family: var(--font-eng);"><?php echo number_format($pubs_with_abstract); ?></div>
        </div>
        <div style="background: rgba(168, 85, 247, 0.08); border: 1px solid rgba(168, 85, 247, 0.3); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">จำแนกด้วย LLM แล้ว</div>
            <div id="kpi-classified-pubs" style="font-size: 1.5rem; font-weight: 700; color: #c084fc; font-family: var(--font-eng);"><?php echo number_format($pubs_classified); ?></div>
        </div>
        <div style="background: rgba(239, 68, 68, 0.06); border: 1px solid rgba(239, 68, 68, 0.2); padding: 14px; border-radius: 10px; text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">ยังไม่ได้จำแนกด้วย AI</div>
            <div id="kpi-pending-pubs" style="font-size: 1.5rem; font-weight: 700; color: #f87171; font-family: var(--font-eng);"><?php echo number_format($pubs_pending); ?></div>
        </div>
    </div>

    <!-- AI Gateway Status -->
    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 16px 18px; border-radius: 10px; margin-bottom: 20px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
            <div style="display: flex; align-items: center; gap: 10px;">
                <i class="fa-solid fa-server" style="color: var(--color-accent); font-size: 1.2rem;"></i>
                <div>
                    <div style="font-weight: 600; font-size: 0.9rem;">UP AI Connect Gateway: 
                        <?php if ($ai_configured): ?>
                            <span style="color: #10b981; font-family: var(--font-eng);"><i class="fa-solid fa-circle-check"></i> เชื่อมต่อพร้อมใช้งาน (gpt-5.4-mini)</span>
                        <?php else: ?>
                            <span style="color: #f87171;"><i class="fa-solid fa-triangle-exclamation"></i> ยังไม่ได้ตั้งค่า API Key</span>
                        <?php endif; ?>
                    </div>
                    <div style="font-size: 0.78rem; color: var(--color-text-muted); font-family: var(--font-eng);">
                        Endpoint: <?php echo htmlspecialchars(defined('UP_AI_CONNECT_BASE_URL') ? UP_AI_CONNECT_BASE_URL : 'Not configured'); ?>
                    </div>
                </div>
            </div>
            <div>
                <span class="badge" style="font-size: 0.75rem; background: rgba(168, 85, 247, 0.15); color: #c084fc; border: 1px solid rgba(168, 85, 247, 0.3); padding: 4px 10px;">
                    Zero-Shot AI Layer
                </span>
            </div>
        </div>
    </div>

    <!-- Batch Control Panel -->
    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); padding: 16px 18px; border-radius: 10px;">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
            <div>
                <div style="font-weight: 600; font-size: 0.95rem; display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-wand-magic-sparkles" style="color: #c084fc;"></i>
                    <span>เริ่มกระบวนการจำแนก SDGs ด้วย AI (Zero-Shot Batch)</span>
                </div>
                <p style="font-size: 0.8rem; color: var(--color-text-muted); margin: 4px 0 0 0; line-height: 1.4;">
                    ระบบจะส่งบทคัดย่อ (Abstract) ไปวิเคราะห์ทีละบทความผ่าน UP AI Connect บันทึกลงในฟิลด์ AI เฉพาะ (ไม่กระทบกับพจนานุกรมคำสำคัญเดิม)
                </p>
            </div>
            <div style="display:flex; gap:8px; flex-shrink:0;">
                <button type="button" id="llm-start-btn" class="btn-premium" style="padding: 10px 18px; background: linear-gradient(135deg, #a855f7, #6366f1);" <?php echo (!$ai_configured || $pubs_pending === 0) ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-play"></i> เริ่มจำแนกด้วย AI (<?php echo $pubs_pending; ?> เรื่อง)
                </button>
                <button type="button" id="llm-refresh-btn" class="btn-premium" style="padding: 10px 18px; background: rgba(255,255,255,0.05);" <?php echo (!$ai_configured || $pubs_with_abstract === 0) ? 'disabled' : ''; ?>>
                    <i class="fa-solid fa-rotate"></i> Refresh ทั้งหมด
                </button>
                <button type="button" id="llm-stop-btn" class="btn-premium" style="padding: 10px 18px; background: rgba(239, 68, 68, 0.15); border-color: rgba(239, 68, 68, 0.3); color: #f87171; display: none;">
                    <i class="fa-solid fa-stop"></i> หยุด
                </button>
            </div>
        </div>

        <div id="llm-progress" style="display: none; margin-top: 18px;">
            <div style="display: flex; justify-content: space-between; font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 6px;">
                <span id="llm-status-text">กำลังเริ่มต้น...</span>
                <span id="llm-counter">0 / 0</span>
            </div>
            <div style="background: rgba(255,255,255,0.05); border-radius: 8px; height: 10px; overflow: hidden;">
                <div id="llm-bar" style="background: linear-gradient(90deg, #a855f7, #6366f1); height: 100%; width: 0%; transition: width 0.2s ease;"></div>
            </div>
            <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-top: 12px;">
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#c084fc;" id="llm-applied-count">0</div>จำแนกสำเร็จ</div>
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#94a3b8;" id="llm-skipped-count">0</div>ไม่มี Abstract (ข้าม)</div>
                <div style="text-align:center; font-size:0.78rem;"><div style="font-weight:700; color:#f87171;" id="llm-error-count">0</div>ผิดพลาด</div>
            </div>
            <div id="llm-log" style="margin-top: 12px; max-height: 180px; overflow-y: auto; font-size: 0.75rem; color: var(--color-text-muted); background: rgba(0,0,0,0.15); border-radius: 8px; padding: 10px; display: flex; flex-direction: column-reverse; gap: 4px;"></div>
        </div>
    </div>
</div>

<!-- Recent LLM Classifications Table -->
<?php if (!empty($recent_samples)): ?>
<div class="glass-panel animate-fade-in" style="padding: 25px;">
    <h3 style="font-weight: 600; font-size: 1rem; margin-bottom: 15px; display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-list-check" style="color: #c084fc;"></i>
        <span>ตัวอย่างผลงานที่จำแนกด้วย AI ล่าสุด (Top 10 Recent Classifications)</span>
    </h3>
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.85rem; text-align: left;">
            <thead>
                <tr style="border-bottom: 1px solid var(--border-glass); color: var(--color-text-muted);">
                    <th style="padding: 10px 8px; width: 40px;">#</th>
                    <th style="padding: 10px 8px;">ชื่อผลงานวิจัย</th>
                    <th style="padding: 10px 8px; width: 140px;">SDG หลัก (AI)</th>
                    <th style="padding: 10px 8px;">เหตุผลวิเคราะห์ (Rationale)</th>
                    <th style="padding: 10px 8px; width: 180px;">Semantic Tags</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_samples as $idx => $s): ?>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.03);">
                        <td style="padding: 10px 8px; color: var(--color-text-muted); font-family: var(--font-eng);"><?php echo $idx + 1; ?></td>
                        <td style="padding: 10px 8px; font-weight: 500;">
                            <?php echo htmlspecialchars($s['title']); ?>
                            <div style="font-size: 0.72rem; color: var(--color-text-muted); font-family: var(--font-eng);">ID: <?php echo $s['id']; ?> | ปี: <?php echo $s['publish_year']; ?></div>
                        </td>
                        <td style="padding: 10px 8px;">
                            <?php if ($s['llm_sdg_primary']): ?>
                                <span class="badge" style="font-size: 0.75rem; background: #a855f7; color: white; padding: 3px 8px; border-radius: 4px; font-weight: 700;">
                                    SDG <?php echo $s['llm_sdg_primary']; ?> (<?php echo $s['llm_sdg_confidence_primary']; ?>%)
                                </span>
                            <?php endif; ?>
                            <?php if ($s['llm_sdg_secondary']): ?>
                                <div style="margin-top: 4px;">
                                    <span class="badge" style="font-size: 0.68rem; background: transparent; border: 1px solid #c084fc; color: #c084fc; padding: 2px 6px;">
                                        SDG <?php echo $s['llm_sdg_secondary']; ?> (<?php echo $s['llm_sdg_confidence_secondary']; ?>%)
                                    </span>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 10px 8px; color: var(--color-text-main); font-size: 0.8rem; line-height: 1.4;">
                            <?php echo htmlspecialchars($s['llm_rationale'] ?: '-'); ?>
                        </td>
                        <td style="padding: 10px 8px;">
                            <?php 
                            $tags = !empty($s['llm_semantic_tags']) ? json_decode($s['llm_semantic_tags'], true) : [];
                            if (is_array($tags)):
                                foreach ($tags as $tag):
                            ?>
                                <span style="display: inline-block; font-size: 0.68rem; background: rgba(255,255,255,0.04); border: 1px solid var(--border-glass); padding: 1px 6px; border-radius: 3px; margin: 1px 2px;">
                                    <?php echo htmlspecialchars($tag); ?>
                                </span>
                            <?php endforeach; endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<script>
const CSRF_TOKEN = "<?php echo htmlspecialchars(get_csrf_token()); ?>";
document.addEventListener('DOMContentLoaded', () => {
    const startBtn = document.getElementById('llm-start-btn');
    const refreshBtn = document.getElementById('llm-refresh-btn');
    const stopBtn = document.getElementById('llm-stop-btn');
    const progressBox = document.getElementById('llm-progress');
    const progressBar = document.getElementById('llm-bar');
    const statusText = document.getElementById('llm-status-text');
    const counterText = document.getElementById('llm-counter');
    const logBox = document.getElementById('llm-log');

    const appliedCountEl = document.getElementById('llm-applied-count');
    const skippedCountEl = document.getElementById('llm-skipped-count');
    const errorCountEl = document.getElementById('llm-error-count');

    let isRunning = false;
    let shouldStop = false;

    function log(msg, type = 'info') {
        const div = document.createElement('div');
        const colors = { info: '#94a3b8', success: '#10b981', warn: '#f59e0b', error: '#f87171' };
        div.style.color = colors[type] || '#94a3b8';
        const time = new Date().toLocaleTimeString('th-TH');
        div.textContent = `[${time}] ${msg}`;
        logBox.prepend(div);
    }

    async function runBatch(mode = 'pending') {
        if (isRunning) return;
        isRunning = true;
        shouldStop = false;

        startBtn.disabled = true;
        refreshBtn.disabled = true;
        stopBtn.style.display = 'inline-flex';
        progressBox.style.display = 'block';

        let applied = 0;
        let skipped = 0;
        let errors = 0;

        appliedCountEl.textContent = '0';
        skippedCountEl.textContent = '0';
        errorCountEl.textContent = '0';
        logBox.innerHTML = '';

        log(`เริ่มดึงรายการผลงานที่ต้องวิเคราะห์ด้วย AI (โหมด: ${mode === 'refresh' ? 'Refresh ทั้งหมด' : 'เฉพาะที่ยังไม่เคยตรวจ'})...`);

        try {
            const listResp = await fetch('classify_sdgs_llm.php?action=list' + (mode === 'refresh' ? '&mode=refresh' : ''));
            const listData = await listResp.json();

            if (!listResp.ok) {
                throw new Error(listData.error || 'ไม่สามารถดึงรายการผลงานได้');
            }

            const items = listData.items || [];
            const total = items.length;

            if (total === 0) {
                log('ไม่มีผลงานที่ต้องวิเคราะห์ด้วย AI ในขณะนี้', 'success');
                statusText.textContent = 'ไม่มีผลงานที่ต้องดำเนินการ';
                stopBtn.style.display = 'none';
                startBtn.disabled = false;
                refreshBtn.disabled = false;
                isRunning = false;
                return;
            }

            log(`พบผลงานทั้งหมด ${total} รายการ กำลังเริ่มส่งวิเคราะห์ผ่าน UP AI Connect...`, 'info');

            for (let i = 0; i < total; i++) {
                if (shouldStop) {
                    log('ผู้ใช้สั่งหยุดการทำงาน', 'warn');
                    break;
                }

                const item = items[i];
                const current = i + 1;
                const pct = Math.round((current / total) * 100);

                counterText.textContent = `${current} / ${total} (${pct}%)`;
                progressBar.style.width = `${pct}%`;
                statusText.textContent = `กำลังวิเคราะห์ (${current}/${total}): ${item.title.substring(0, 40)}...`;

                try {
                    const resp = await fetch('classify_sdgs_llm.php?action=process&id=' + encodeURIComponent(item.id), {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                            'X-CSRF-Token': CSRF_TOKEN
                        },
                        body: 'csrf_token=' + encodeURIComponent(CSRF_TOKEN)
                    });
                    const res = await resp.json();

                    if (res.success) {
                        if (res.status === 'applied') {
                            applied++;
                            appliedCountEl.textContent = applied;
                            const tagStr = (res.semantic_tags || []).join(', ');
                            log(`✓ #${item.id}: SDG ${res.sdg_primary} (${res.confidence_primary}%) | ${tagStr}`, 'success');
                        } else if (res.status === 'skipped_no_abstract') {
                            skipped++;
                            skippedCountEl.textContent = skipped;
                            log(`- #${item.id}: ไม่มีบทคัดย่อ (ข้าม)`, 'info');
                        }
                    } else {
                        errors++;
                        errorCountEl.textContent = errors;
                        log(`✗ #${item.id}: ${res.error || 'ผิดพลาด'}`, 'error');
                    }
                } catch (err) {
                    errors++;
                    errorCountEl.textContent = errors;
                    log(`✗ #${item.id}: เกิดข้อผิดพลาดเครือข่าย (${err.message})`, 'error');
                }

                // Small delay to pace API requests
                await new Promise(r => setTimeout(r, 400));
            }

            log(`ประมวลผลเสร็จสิ้น! สำเร็จ: ${applied}, ข้าม: ${skipped}, ผิดพลาด: ${errors}`, 'success');
            statusText.textContent = 'เสร็จสิ้นเรียบร้อย';

        } catch (e) {
            log(`เกิดข้อผิดพลาดร้ายแรง: ${e.message}`, 'error');
            statusText.textContent = 'เกิดข้อผิดพลาด';
        } finally {
            isRunning = false;
            stopBtn.style.display = 'none';
            startBtn.disabled = false;
            refreshBtn.disabled = false;
        }
    }

    if (startBtn) startBtn.addEventListener('click', () => runBatch('pending'));
    if (refreshBtn) refreshBtn.addEventListener('click', () => runBatch('refresh'));
    if (stopBtn) stopBtn.addEventListener('click', () => { shouldStop = true; stopBtn.disabled = true; });
});
</script>

<?php require_once __DIR__ . '/admin_footer.php'; ?>
