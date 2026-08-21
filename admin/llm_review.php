<?php
// admin/llm_review.php
// Phase 10 (SEM-08): human-in-the-loop review for low-confidence LLM SDG
// classifications - directly answers the evaluator's transparency
// criterion (4.3) about human oversight of automated decisions.
//
// Whatever the reviewer selects becomes the final llm_sdg_primary (a
// deliberate simplification: "confirm" is just selecting the same value
// the model already suggested, "correct" is selecting a different one -
// one action covers both, since the outcome is identical either way: a
// human-reviewed, 100%-confidence value with an audit trail).

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'admin_llm_review';
$page_title = 'ตรวจสอบผลจำแนก AI ความเชื่อมั่นต่ำ';

require_once __DIR__ . '/admin_header.php';

// Below this confidence, a classification is surfaced for human review
// rather than trusted outright - matches this project's convention
// (SDG-06c's MIN_AUTO_APPLY_SCORE) of a single documented threshold
// constant rather than a magic number scattered across the codebase.
const MIN_LLM_REVIEW_CONFIDENCE = 70;

$stmt = $pdo->prepare("
    SELECT id, title, llm_sdg_primary, llm_confidence_primary, llm_sdg_secondary, llm_confidence_secondary, llm_rationale, llm_reviewed_by, llm_reviewed_at
    FROM `publications`
    WHERE llm_sdg_primary IS NOT NULL
      AND llm_confidence_primary < ?
      AND llm_reviewed_at IS NULL
    ORDER BY llm_confidence_primary ASC
    LIMIT 50
");
$stmt->execute([MIN_LLM_REVIEW_CONFIDENCE]);
$pending = $stmt->fetchAll();

$reviewed_count = (int)$pdo->query("SELECT COUNT(*) FROM `publications` WHERE llm_reviewed_at IS NOT NULL")->fetchColumn();
$sdgs = get_all_sdgs();
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2><i class="fa-solid fa-user-check" style="color: #8b5cf6;"></i> ตรวจสอบผลจำแนก AI ที่ความเชื่อมั่นต่ำ (Human-in-the-Loop)</h2>
    <p>รายการที่ AI จำแนก SDG ด้วยความเชื่อมั่นต่ำกว่า <?php echo MIN_LLM_REVIEW_CONFIDENCE; ?>% ควรมีผู้เชี่ยวชาญยืนยันหรือแก้ไขก่อนนำไปอ้างอิงจริง — ตรวจแล้ว <strong style="color: #8b5cf6;"><?php echo number_format($reviewed_count); ?></strong> รายการ, เหลืออีก <strong><?php echo number_format(count($pending)); ?></strong> รายการ (แสดงสูงสุด 50 รายการต่อหน้า)</p>
</div>

<?php if (empty($pending)): ?>
    <div class="glass-panel animate-fade-in" style="padding: 40px; text-align: center; color: var(--color-text-muted);">
        <i class="fa-solid fa-circle-check" style="font-size: 2.5rem; color: #10b981; margin-bottom: 12px; display: block;"></i>
        ไม่มีรายการความเชื่อมั่นต่ำที่รอตรวจสอบในขณะนี้
    </div>
<?php else: ?>
    <div style="display: flex; flex-direction: column; gap: 15px;">
        <?php foreach ($pending as $pub): ?>
            <div class="glass-panel llm-review-row" data-id="<?php echo (int)$pub['id']; ?>" style="padding: 20px;">
                <div style="font-weight: 600; margin-bottom: 8px;"><?php echo htmlspecialchars($pub['title']); ?></div>
                <div style="font-size: 0.85rem; color: var(--color-text-muted); margin-bottom: 10px;">
                    AI แนะนำ: <strong style="color: #8b5cf6;">SDG <?php echo (int)$pub['llm_sdg_primary']; ?></strong>
                    (<?php echo (int)$pub['llm_confidence_primary']; ?>%)
                    <?php if (!empty($pub['llm_rationale'])): ?>
                        — <?php echo htmlspecialchars($pub['llm_rationale']); ?>
                    <?php endif; ?>
                </div>
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <select class="search-input review-sdg-select" style="width: auto; min-width: 220px;">
                        <option value="">-- ไม่มี SDG ที่เกี่ยวข้อง --</option>
                        <?php foreach ($sdgs as $code => $info): $n = (int)preg_replace('/[^0-9]/', '', $code); ?>
                            <option value="<?php echo $n; ?>" <?php echo $n === (int)$pub['llm_sdg_primary'] ? 'selected' : ''; ?>>
                                SDG <?php echo $n; ?> - <?php echo htmlspecialchars($info['th_name'] ?? $info['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="button" class="btn-premium review-submit-btn" style="padding: 8px 18px; background: rgba(139, 92, 246, 0.15); border-color: rgba(139, 92, 246, 0.4); color: #a78bfa;">
                        <i class="fa-solid fa-check"></i> ยืนยัน/บันทึกผลตรวจสอบ
                    </button>
                    <span class="review-result-msg" style="font-size: 0.8rem;"></span>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<script>
const CSRF_TOKEN = "<?php echo htmlspecialchars(get_csrf_token()); ?>";
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.llm-review-row').forEach(row => {
        const id = row.dataset.id;
        const select = row.querySelector('.review-sdg-select');
        const btn = row.querySelector('.review-submit-btn');
        const msg = row.querySelector('.review-result-msg');

        btn.addEventListener('click', async () => {
            btn.disabled = true;
            msg.textContent = 'กำลังบันทึก...';
            msg.style.color = 'var(--color-text-muted)';
            try {
                const resp = await fetch('llm_review_action.php?action=submit&id=' + encodeURIComponent(id) + '&sdg=' + encodeURIComponent(select.value), {
                    headers: { 'X-CSRF-Token': CSRF_TOKEN }
                });
                const data = await resp.json();
                if (data.status === 'saved') {
                    msg.style.color = '#10b981';
                    msg.textContent = 'บันทึกแล้ว';
                    row.style.opacity = '0.5';
                    btn.disabled = true;
                    select.disabled = true;
                } else {
                    msg.style.color = '#f87171';
                    msg.textContent = data.error || 'เกิดข้อผิดพลาด';
                    btn.disabled = false;
                }
            } catch (e) {
                msg.style.color = '#f87171';
                msg.textContent = 'เชื่อมต่อไม่สำเร็จ';
                btn.disabled = false;
            }
        });
    });
});
</script>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
