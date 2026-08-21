<?php
// admin/sdg_validation.php
// Phase 10 (SEM-07): blind gold-standard labeling + Precision/Recall/F1
// comparison between the Phase 6 keyword-dictionary classifier and the
// Phase 10 LLM zero-shot classifier - directly answers the evaluator's
// Q&A item "how accurate is the SDG classification, measured how."
//
// IMPORTANT: this tool cannot manufacture ground truth by itself. A real
// subject-matter reviewer must judge each publication's true SDG - the
// labeling UI deliberately never shows either classifier's guess while
// collecting a label, specifically to avoid anchoring the reviewer's
// judgment toward whichever classifier they see first. Metrics below are
// only as trustworthy as the human labels behind them.

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';

$current_page = 'admin_sdg_validation';
$page_title = 'วัดความแม่นยำ SDG (Precision/Recall/F1)';

require_once __DIR__ . '/admin_header.php';

$labeled_count = (int)$pdo->query("SELECT COUNT(*) FROM `sdg_gold_standard`")->fetchColumn();
$eligible_count = (int)$pdo->query("
    SELECT COUNT(*) FROM `publications` p
    WHERE p.llm_checked_at IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM `sdg_gold_standard` g WHERE g.publication_id = p.id)
")->fetchColumn();
$sdgs = get_all_sdgs();

// Pull one random not-yet-labeled candidate for the labeling form below.
// Deliberately SELECTs only title/abstract - no sdg_primary/llm_sdg_primary
// column at all, so there is no way for this page to leak a classifier's
// guess to the reviewer even by accident.
$candidate = $pdo->query("
    SELECT p.id, p.title, p.abstract
    FROM `publications` p
    WHERE p.llm_checked_at IS NOT NULL
      AND NOT EXISTS (SELECT 1 FROM `sdg_gold_standard` g WHERE g.publication_id = p.id)
    ORDER BY RAND()
    LIMIT 1
")->fetch();

// keyword classifier stores "SDG N" strings; LLM classifier stores a plain
// int - normalize both to a plain int|null for comparison. Shared by both
// the gold-standard metrics below and the agreement analysis further down.
$normalize_keyword = function ($v) {
    if (empty($v)) return null;
    $n = (int)preg_replace('/[^0-9]/', '', $v);
    return ($n >= 1 && $n <= 17) ? $n : null;
};
$normalize_llm = function ($v) {
    $n = $v !== null ? (int)$v : null;
    return ($n !== null && $n >= 1 && $n <= 17) ? $n : null;
};

// --- Metrics (only meaningful once some labels exist) ---
$metrics = null;
if ($labeled_count > 0) {
    $rows = $pdo->query("
        SELECT g.correct_sdg, p.sdg_primary AS keyword_sdg, p.llm_sdg_primary AS llm_sdg
        FROM `sdg_gold_standard` g
        JOIN `publications` p ON p.id = g.publication_id
    ")->fetchAll();

    $compute = function ($rows, $guess_key, $normalize) {
        $tp = 0; $guessed_non_null = 0; $gold_non_null = 0;
        foreach ($rows as $r) {
            $gold = $r['correct_sdg'] !== null ? (int)$r['correct_sdg'] : null;
            $guess = $normalize($r[$guess_key]);
            if ($guess !== null) $guessed_non_null++;
            if ($gold !== null) $gold_non_null++;
            if ($gold !== null && $guess !== null && $gold === $guess) $tp++;
        }
        $precision = $guessed_non_null > 0 ? $tp / $guessed_non_null : null;
        $recall = $gold_non_null > 0 ? $tp / $gold_non_null : null;
        $f1 = ($precision !== null && $recall !== null && ($precision + $recall) > 0)
            ? 2 * $precision * $recall / ($precision + $recall) : null;
        return ['precision' => $precision, 'recall' => $recall, 'f1' => $f1, 'tp' => $tp, 'guessed' => $guessed_non_null, 'gold_positive' => $gold_non_null];
    };

    $metrics = [
        'keyword' => $compute($rows, 'keyword_sdg', $normalize_keyword),
        'llm' => $compute($rows, 'llm_sdg', $normalize_llm),
        'total_labeled' => count($rows),
    ];
}

function fmt_pct($v) {
    return $v === null ? 'N/A' : round($v * 100, 1) . '%';
}

// --- Agreement analysis (keyword vs LLM, no human labels needed) ---
// Deliberately separate from the gold-standard Precision/Recall/F1 above:
// this measures how often the two INDEPENDENTLY-designed classifiers agree
// with each other, computable right now from data that already exists -
// no new human labeling required. It is NOT a substitute for real accuracy
// validation: two methods agreeing shows consistency (methodologists call
// this convergent validity / inter-rater agreement), not correctness - if
// both share the same blind spot (e.g. both over-classify everything as
// SDG 3 at a medical faculty), they'd agree with each other while both
// being wrong. Cohen's Kappa corrects raw agreement for exactly this kind
// of chance/skew inflation. Reuses $normalize_keyword defined above.
$agreement_rows = $pdo->query("
    SELECT id, title, sdg_primary AS keyword_sdg, sdg_rationale AS keyword_rationale,
           llm_sdg_primary AS llm_sdg, llm_confidence_primary, llm_rationale
    FROM `publications`
    WHERE sdg_primary IS NOT NULL AND sdg_primary != '' AND llm_sdg_primary IS NOT NULL
")->fetchAll();

$agreement = null;
if (!empty($agreement_rows)) {
    $n = count($agreement_rows);
    $exact_match = 0;
    $keyword_dist = [];
    $llm_dist = [];
    $confusion = []; // [keyword_sdg][llm_sdg] => count, disagreements only
    $disagreement_examples = []; // actual publications, not just counts - for real side-by-side inspection
    foreach ($agreement_rows as $r) {
        $k = $normalize_keyword($r['keyword_sdg']);
        $l = (int)$r['llm_sdg'];
        if ($k === null) continue;
        $keyword_dist[$k] = ($keyword_dist[$k] ?? 0) + 1;
        $llm_dist[$l] = ($llm_dist[$l] ?? 0) + 1;
        if ($k === $l) {
            $exact_match++;
        } else {
            $confusion[$k][$l] = ($confusion[$k][$l] ?? 0) + 1;
            $disagreement_examples[] = [
                'id' => (int)$r['id'],
                'title' => $r['title'],
                'keyword_sdg' => $k,
                'keyword_rationale' => $r['keyword_rationale'],
                'llm_sdg' => $l,
                'llm_confidence' => (int)$r['llm_confidence_primary'],
                'llm_rationale' => $r['llm_rationale'],
            ];
        }
    }
    $po = $n > 0 ? $exact_match / $n : 0;
    $pe = 0;
    foreach ($keyword_dist as $sdg => $kc) {
        $lc = $llm_dist[$sdg] ?? 0;
        $pe += ($kc / $n) * ($lc / $n);
    }
    $kappa = (1 - $pe) > 0 ? ($po - $pe) / (1 - $pe) : null;

    // Top 5 most common disagreement pairs, for a concrete "where do they
    // actually diverge" view rather than just one aggregate percentage.
    $disagreement_pairs = [];
    foreach ($confusion as $k => $llm_counts) {
        foreach ($llm_counts as $l => $cnt) {
            $disagreement_pairs[] = ['keyword' => $k, 'llm' => $l, 'count' => $cnt];
        }
    }
    usort($disagreement_pairs, function ($a, $b) { return $b['count'] <=> $a['count']; });

    // Real example publications, most-confident AI disagreements first -
    // these are the cases most worth a human actually reading, since a
    // high-confidence AI disagreement is either a clear AI win or a clear
    // AI miss, not an ambiguous borderline call either way.
    usort($disagreement_examples, function ($a, $b) { return $b['llm_confidence'] <=> $a['llm_confidence']; });

    $agreement = [
        'n' => $n,
        'exact_match' => $exact_match,
        'po' => $po,
        'kappa' => $kappa,
        'top_disagreements' => array_slice($disagreement_pairs, 0, 5),
        'disagreement_examples' => array_slice($disagreement_examples, 0, 25),
        'disagreement_total' => count($disagreement_examples),
    ];
}
?>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2><i class="fa-solid fa-clipboard-check" style="color: #8b5cf6;"></i> วัดความแม่นยำ SDG ด้วย Gold Standard (Precision/Recall/F1)</h2>
    <p>ผู้เชี่ยวชาญติดป้ายกำกับ SDG ที่ถูกต้องจริงแบบ <strong>ไม่เห็นคำตอบของ AI/พจนานุกรมล่วงหน้า</strong> (blind labeling) เพื่อป้องกันอคติ จากนั้นระบบเทียบผลลัพธ์ของทั้ง 2 วิธีกับป้ายกำกับนี้</p>
</div>

<!-- Agreement analysis: needs zero human labeling, computable right now -->
<?php if ($agreement): ?>
<div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 25px; border: 1px solid rgba(59, 130, 246, 0.25);">
    <h3 style="margin-bottom: 8px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-arrows-left-right" style="color: #60a5fa;"></i>
        <span>ความสอดคล้องระหว่าง 2 วิธี (ไม่ต้องใช้ผู้เชี่ยวชาญ)</span>
    </h3>
    <p style="font-size: 0.82rem; color: var(--color-text-muted); margin-bottom: 18px; line-height: 1.6;">
        เทียบว่าพจนานุกรมคำสำคัญกับ AI Zero-Shot ให้คำตอบ <strong>SDG หลักตรงกัน</strong> บ่อยแค่ไหน จากผลงาน <?php echo number_format($agreement['n']); ?> รายการที่ทั้ง 2 วิธีจำแนกแล้ว
        — <strong style="color: #f59e0b;">นี่คือความสอดคล้องกันเอง ไม่ใช่ความแม่นยำจริง</strong> ถ้าทั้ง 2 วิธีมีจุดบอดแบบเดียวกัน (เช่น เหมาว่าทุกอย่างเป็น SDG 3 เพราะเป็นคณะแพทย์) ก็จะตรงกันได้สูงโดยที่ผิดทั้งคู่ — ใช้เป็นสัญญาณเสริมระหว่างรอผลจาก Gold Standard ด้านล่าง ไม่ใช่ตัวแทน
    </p>
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px; margin-bottom: <?php echo !empty($agreement['top_disagreements']) ? '18px' : '0'; ?>;">
        <div style="text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">ตรงกันพอดี (Exact Match)</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #60a5fa;"><?php echo fmt_pct($agreement['po']); ?></div>
            <div style="font-size: 0.7rem; color: var(--color-text-muted);"><?php echo number_format($agreement['exact_match']); ?> / <?php echo number_format($agreement['n']); ?> รายการ</div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">Cohen's Kappa (ปรับผลของโอกาสสุ่มแล้ว)</div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?php echo $agreement['kappa'] !== null ? round($agreement['kappa'], 3) : 'N/A'; ?></div>
            <div style="font-size: 0.7rem; color: var(--color-text-muted);">&gt;0.6 ถือว่าค่อนข้างสอดคล้อง, &gt;0.8 สอดคล้องมาก</div>
        </div>
    </div>
    <?php if (!empty($agreement['top_disagreements'])): ?>
        <div style="border-top: 1px dashed var(--border-glass); padding-top: 14px;">
            <div style="font-size: 0.78rem; color: var(--color-text-muted); margin-bottom: 8px;">คู่ที่ไม่ตรงกันบ่อยที่สุด (5 อันดับ):</div>
            <div style="display: flex; flex-direction: column; gap: 6px;">
                <?php foreach ($agreement['top_disagreements'] as $d): ?>
                    <div style="font-size: 0.8rem; display: flex; align-items: center; gap: 8px;">
                        <span style="color: #10b981; font-weight: 600;">พจนานุกรม: SDG <?php echo $d['keyword']; ?></span>
                        <i class="fa-solid fa-arrow-right-arrow-left" style="font-size: 0.65rem; color: var(--color-text-muted);"></i>
                        <span style="color: #8b5cf6; font-weight: 600;">AI: SDG <?php echo $d['llm']; ?></span>
                        <span style="color: var(--color-text-muted);">(<?php echo $d['count']; ?> รายการ)</span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php if (!empty($agreement['disagreement_examples'])): ?>
<div class="glass-panel animate-fade-in" style="padding: 25px; margin-bottom: 25px;">
    <h3 style="margin-bottom: 4px; font-weight: 600; display: flex; align-items: center; gap: 8px;">
        <i class="fa-solid fa-magnifying-glass" style="color: #60a5fa;"></i>
        <span>ตัวอย่างผลงานจริงที่ 2 วิธีให้คำตอบไม่ตรงกัน</span>
    </h3>
    <p style="font-size: 0.8rem; color: var(--color-text-muted); margin-bottom: 15px;">
        แสดง <?php echo count($agreement['disagreement_examples']); ?> จาก <?php echo number_format($agreement['disagreement_total']); ?> รายการที่ไม่ตรงกันทั้งหมด เรียงจากที่ AI มั่นใจมากที่สุดก่อน (กรณีมั่นใจสูงแต่ไม่ตรงกับพจนานุกรม คือกรณีที่น่าดูที่สุดว่าใครถูก) — อ่านชื่อเรื่องแล้วตัดสินเองว่า SDG ไหนตรงกับเนื้อหาจริงมากกว่า
    </p>
    <div style="display: flex; flex-direction: column; gap: 10px; max-height: 500px; overflow-y: auto;">
        <?php foreach ($agreement['disagreement_examples'] as $ex): ?>
            <div style="padding: 14px 16px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 8px;">
                <div style="font-weight: 600; font-size: 0.9rem; margin-bottom: 8px;">
                    <?php echo htmlspecialchars($ex['title']); ?>
                    <span style="font-weight: 400; color: var(--color-text-muted); font-size: 0.75rem;">(#<?php echo $ex['id']; ?>)</span>
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; font-size: 0.8rem;">
                    <div style="border-left: 3px solid #10b981; padding-left: 10px;">
                        <div style="color: #10b981; font-weight: 600; margin-bottom: 3px;">พจนานุกรม: SDG <?php echo $ex['keyword_sdg']; ?></div>
                        <div style="color: var(--color-text-muted); line-height: 1.5;"><?php echo htmlspecialchars($ex['keyword_rationale'] ?: '(ไม่มีเหตุผลบันทึกไว้)'); ?></div>
                    </div>
                    <div style="border-left: 3px solid #8b5cf6; padding-left: 10px;">
                        <div style="color: #8b5cf6; font-weight: 600; margin-bottom: 3px;">AI: SDG <?php echo $ex['llm_sdg']; ?> (<?php echo $ex['llm_confidence']; ?>%)</div>
                        <div style="color: var(--color-text-muted); line-height: 1.5;"><?php echo htmlspecialchars($ex['llm_rationale'] ?: '(ไม่มีเหตุผลบันทึกไว้)'); ?></div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>
<div class="glass-panel animate-fade-in" style="padding: 20px 25px; margin-bottom: 25px; color: var(--color-text-muted); font-size: 0.85rem; text-align: center;">
    ยังไม่มีผลงานที่ผ่านทั้งพจนานุกรมคำสำคัญและ AI Zero-Shot พร้อมกัน จึงยังเปรียบเทียบความสอดคล้องไม่ได้
</div>
<?php endif; ?>

<div class="glass-panel animate-fade-in" style="padding: 20px 25px; margin-bottom: 25px;">
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 15px;">
        <div style="text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">ติดป้ายกำกับแล้ว</div>
            <div style="font-size: 1.5rem; font-weight: 700; color: #8b5cf6;"><?php echo number_format($labeled_count); ?> / 150-200 (แนะนำ)</div>
        </div>
        <div style="text-align: center;">
            <div style="font-size: 0.75rem; color: var(--color-text-muted);">พร้อมสุ่มติดป้าย (ผ่าน LLM Classify แล้ว)</div>
            <div style="font-size: 1.5rem; font-weight: 700;"><?php echo number_format($eligible_count); ?></div>
        </div>
    </div>
    <?php if ($eligible_count === 0 && $labeled_count === 0): ?>
        <div style="margin-top: 15px; font-size: 0.85rem; color: #f59e0b;">
            <i class="fa-solid fa-triangle-exclamation"></i> ยังไม่มีผลงานที่ผ่าน LLM Classify เลย กรุณาไปที่ <a href="sdg_import.php" style="color:#8b5cf6;">จัดการ SDGs</a> แล้วรัน "เริ่ม LLM Classify" ให้ครอบคลุมอย่างน้อย 150-200 รายการก่อน จึงจะสุ่มติดป้ายกำกับที่นี่ได้
        </div>
    <?php endif; ?>
</div>

<?php if ($candidate): ?>
    <div class="glass-panel animate-fade-in" id="labeling-panel" style="padding: 25px; margin-bottom: 30px;">
        <h3 style="margin-bottom: 15px; font-weight: 600;">ติดป้ายกำกับถัดไป (Blind - ไม่แสดงคำตอบ AI/พจนานุกรม)</h3>
        <div data-pub-id="<?php echo (int)$candidate['id']; ?>">
            <div style="font-weight: 600; margin-bottom: 8px;"><?php echo htmlspecialchars($candidate['title']); ?></div>
            <div style="font-size: 0.85rem; color: var(--color-text-muted); max-height: 150px; overflow-y: auto; margin-bottom: 15px; line-height: 1.6;">
                <?php echo nl2br(htmlspecialchars($candidate['abstract'] ?? '(ไม่มีบทคัดย่อ)')); ?>
            </div>
            <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <select class="search-input" id="gold-sdg-select" style="width: auto; min-width: 240px;">
                    <option value="">-- ไม่มี SDG ที่เกี่ยวข้อง --</option>
                    <?php foreach ($sdgs as $code => $info): $n = (int)preg_replace('/[^0-9]/', '', $code); ?>
                        <option value="<?php echo $n; ?>">SDG <?php echo $n; ?> - <?php echo htmlspecialchars($info['th_name'] ?? $info['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="button" id="gold-submit-btn" class="btn-premium" style="padding: 8px 18px; background: rgba(139, 92, 246, 0.15); border-color: rgba(139, 92, 246, 0.4); color: #a78bfa;">
                    <i class="fa-solid fa-check"></i> บันทึกและไปรายการถัดไป
                </button>
                <span id="gold-result-msg" style="font-size: 0.8rem;"></span>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($metrics): ?>
    <div class="glass-panel animate-fade-in" style="padding: 25px;">
        <h3 style="margin-bottom: 15px; font-weight: 600;">ผลเปรียบเทียบความแม่นยำ (จากป้ายกำกับ <?php echo $metrics['total_labeled']; ?> รายการ)</h3>
        <div style="overflow-x: auto;">
            <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 0.9rem;">
                <thead>
                    <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted);">
                        <th style="padding: 8px;">วิธีการจำแนก</th>
                        <th style="padding: 8px;">Precision</th>
                        <th style="padding: 8px;">Recall</th>
                        <th style="padding: 8px;">F1-score</th>
                    </tr>
                </thead>
                <tbody>
                    <tr style="border-bottom: 1px solid rgba(255,255,255,0.05);">
                        <td style="padding: 8px;"><span style="color:#10b981; font-weight:600;">พจนานุกรมคำสำคัญ (Phase 6)</span></td>
                        <td style="padding: 8px; font-family: var(--font-eng);"><?php echo fmt_pct($metrics['keyword']['precision']); ?></td>
                        <td style="padding: 8px; font-family: var(--font-eng);"><?php echo fmt_pct($metrics['keyword']['recall']); ?></td>
                        <td style="padding: 8px; font-family: var(--font-eng); font-weight: 700;"><?php echo fmt_pct($metrics['keyword']['f1']); ?></td>
                    </tr>
                    <tr>
                        <td style="padding: 8px;"><span style="color:#8b5cf6; font-weight:600;">LLM Zero-Shot (Phase 10)</span></td>
                        <td style="padding: 8px; font-family: var(--font-eng);"><?php echo fmt_pct($metrics['llm']['precision']); ?></td>
                        <td style="padding: 8px; font-family: var(--font-eng);"><?php echo fmt_pct($metrics['llm']['recall']); ?></td>
                        <td style="padding: 8px; font-family: var(--font-eng); font-weight: 700;"><?php echo fmt_pct($metrics['llm']['f1']); ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p style="font-size: 0.78rem; color: var(--color-text-muted); margin-top: 15px;">
            Precision = สัดส่วนที่ทายถูกจากทั้งหมดที่วิธีนี้ทาย SDG (ไม่ทายว่างเปล่า) | Recall = สัดส่วนที่ทายถูกจากทั้งหมดที่ผู้เชี่ยวชาญระบุว่ามี SDG จริง | ยิ่งตัวอย่างมาก (แนะนำ 150-200) ยิ่งน่าเชื่อถือ
        </p>
    </div>
<?php elseif ($labeled_count === 0): ?>
    <div class="glass-panel animate-fade-in" style="padding: 25px; text-align: center; color: var(--color-text-muted);">
        ยังไม่มีป้ายกำกับ - เริ่มติดป้ายกำกับด้านบนเพื่อดูผลเปรียบเทียบ
    </div>
<?php endif; ?>

<script>
const CSRF_TOKEN = "<?php echo htmlspecialchars(get_csrf_token()); ?>";
document.addEventListener('DOMContentLoaded', () => {
    const btn = document.getElementById('gold-submit-btn');
    if (!btn) return;
    const panel = document.getElementById('labeling-panel');
    const pubId = panel.querySelector('[data-pub-id]').dataset.pubId;
    const select = document.getElementById('gold-sdg-select');
    const msg = document.getElementById('gold-result-msg');

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        msg.textContent = 'กำลังบันทึก...';
        msg.style.color = 'var(--color-text-muted)';
        try {
            const resp = await fetch('sdg_validation_action.php?action=label&id=' + encodeURIComponent(pubId) + '&sdg=' + encodeURIComponent(select.value), {
                headers: { 'X-CSRF-Token': CSRF_TOKEN }
            });
            const data = await resp.json();
            if (data.status === 'saved') {
                window.location.reload();
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
</script>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
