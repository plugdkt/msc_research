<?php
// admin/embed.php
// Interactive Widget Generator & Code Builder for MSC Research
// Admin panel page to customize, preview, and generate embed codes for the main faculty website.

$current_page = 'admin_embed';
$page_title = 'เครื่องมือสร้าง Embed วิดเจ็ตสำหรับเว็บหลัก';

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/admin_header.php';

// Fetch active researchers for dropdown
$researchers_stmt = $pdo->query("
    SELECT id, title_th, first_name_th, last_name_th, first_name_en, last_name_en, department 
    FROM researchers 
    WHERE is_active = 1 
    ORDER BY first_name_th ASC
");
$researchers_list = $researchers_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch distinct departments
$departments_stmt = $pdo->query("
    SELECT DISTINCT department 
    FROM researchers 
    WHERE department IS NOT NULL AND department != '' 
    ORDER BY department ASC
");
$departments_list = $departments_stmt->fetchAll(PDO::FETCH_COLUMN);

// Pre-selected researcher ID if coming from profile.php (?res_id=...)
$pre_res_id = isset($_GET['res_id']) ? (int)$_GET['res_id'] : 0;
$initial_type = $pre_res_id > 0 ? 'researcher' : 'recent';

// Calculate base system URL for embed codes
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'] ?? 'www.medsci.up.ac.th';
$script_dir = dirname($_SERVER['SCRIPT_NAME']); // e.g. /msc_researchv2/admin
$parent_dir = dirname($script_dir); // e.g. /msc_researchv2
$base_dir = ($parent_dir === '/' || $parent_dir === '\\' || $parent_dir === '.') ? '' : '/' . trim($parent_dir, '/\\');
$system_url = $protocol . $host . $base_dir;
?>

<style>
    .config-card {
        padding: 24px;
        border-radius: 14px;
        background: rgba(255, 255, 255, 0.02);
        border: 1px solid var(--border-glass);
    }
    .form-group-custom {
        margin-bottom: 18px;
    }
    .form-label-custom {
        display: block;
        font-size: 0.88rem;
        font-weight: 600;
        margin-bottom: 7px;
        color: var(--color-text-main);
    }
    .form-select-custom, .form-input-custom {
        width: 100%;
        padding: 10px 14px;
        background: rgba(255, 255, 255, 0.05);
        border: 1px solid var(--border-glass);
        border-radius: 8px;
        color: var(--color-text-main);
        font-family: var(--font-thai);
        font-size: 0.9rem;
        transition: var(--transition-smooth);
        outline: none;
    }
    .form-select-custom option {
        background: #0f172a;
        color: #f8fafc;
    }
    .form-select-custom:focus, .form-input-custom:focus {
        border-color: var(--color-primary);
        box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.2);
    }
    .preview-frame-wrapper {
        border-radius: 12px;
        overflow: hidden;
        border: 1px solid var(--border-glass);
        background: #f8fafc;
        transition: background 0.3s;
        box-shadow: 0 8px 30px rgba(0,0,0,0.12);
    }
    .preview-dark-bg {
        background: #0b0f19 !important;
    }
    .code-box {
        background: #0f172a;
        color: #f8fafc;
        padding: 16px;
        border-radius: 8px;
        font-family: 'Consolas', 'Courier New', monospace;
        font-size: 0.85rem;
        line-height: 1.5;
        white-space: pre-wrap;
        word-break: break-all;
        position: relative;
        border: 1px solid rgba(255,255,255,0.1);
    }
    .tab-pill {
        padding: 6px 14px;
        border-radius: 8px;
        border: 1px solid var(--border-glass);
        background: rgba(255,255,255,0.03);
        color: var(--color-text-muted);
        cursor: pointer;
        font-size: 0.8rem;
        font-weight: 600;
        transition: all 0.2s;
    }
    .tab-pill.active {
        background: var(--color-primary);
        color: #ffffff;
        border-color: var(--color-primary);
    }
</style>

<!-- Hero Section -->
<div class="hero glass-panel animate-fade-in" style="padding: 30px 24px; margin-bottom: 30px; text-align: center;">
    <div style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 600; color: #38bdf8; background: rgba(56,189,248,0.1); border: 1px solid rgba(56,189,248,0.25); border-radius: 999px; padding: 5px 16px; margin-bottom: 12px;">
        <i class="fa-solid fa-code"></i> Research Data Embed System
    </div>
    <h2 style="font-size: 1.8rem; font-weight: 700; margin-bottom: 10px; color: var(--color-text-main);">
        เครื่องมือสร้าง Embed วิดเจ็ตสำหรับเว็บไซต์หลัก
    </h2>
    <p style="color: var(--color-text-muted); font-size: 0.95rem; margin: 0 auto; max-width: 780px; line-height: 1.6;">
        นำข้อมูลงานวิจัย ผลงานตีพิมพ์ สรุปสถิติ หรือประวัติอาจารย์ ไปฝังแสดงผลบนหน้าเว็บไซต์หลักของคณะ (<code style="color: var(--color-primary);">www.medsci.up.ac.th</code>), เว็บไซต์ภาควิชา หรือหน้าเว็บบุคลากร ได้อย่างง่ายดาย ข้อมูลอัปเดตอัตโนมัติตลอดเวลา
    </p>
</div>

<!-- Main Layout: 2 Columns -->
<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 28px; margin-bottom: 40px;">
    <!-- Left Column: Configurator Form -->
    <div class="glass-panel config-card animate-fade-in">
        <h3 style="font-size: 1.15rem; font-weight: 700; margin-bottom: 18px; display: flex; align-items: center; gap: 8px; color: var(--color-primary);">
            <i class="fa-solid fa-sliders"></i> กำหนดค่ารูปแบบวิดเจ็ต (Settings)
        </h3>

        <!-- 1. Widget Type -->
        <div class="form-group-custom">
            <label class="form-label-custom">1. เลือกประเภทข้อมูลที่ต้องการแสดง (Widget Type)</label>
            <select id="config-type" class="form-select-custom" onchange="onWidgetTypeChange()">
                <option value="recent" <?php echo $initial_type === 'recent' ? 'selected' : ''; ?>>ผลงานตีพิมพ์วิจัยล่าสุด (Recent Publications)</option>
                <option value="stats">สรุปภาพรวมสถิติวิจัยระดับคณะ (Research Overview Stats)</option>
                <option value="researcher" <?php echo $initial_type === 'researcher' ? 'selected' : ''; ?>>โปรไฟล์และผลงานนักวิจัยรายบุคคล (Researcher Profile)</option>
                <option value="department">ผลงานวิจัยเฉพาะภาควิชา (Department Publications)</option>
            </select>
        </div>

        <!-- 2. Researcher Selector (Conditionally shown) -->
        <div class="form-group-custom" id="group-researcher" style="<?php echo $initial_type === 'researcher' ? '' : 'display: none;'; ?>">
            <label class="form-label-custom">เลือกอาจารย์ / นักวิจัย</label>
            <select id="config-researcher" class="form-select-custom" onchange="updateEmbedPreview()">
                <?php foreach ($researchers_list as $r_item): 
                    $label = trim(($r_item['title_th'] ?? '') . ' ' . $r_item['first_name_th'] . ' ' . $r_item['last_name_th']);
                    if (empty($label)) $label = trim($r_item['first_name_en'] . ' ' . $r_item['last_name_en']);
                    $sel = ($pre_res_id === (int)$r_item['id']) ? 'selected' : '';
                ?>
                    <option value="<?php echo $r_item['id']; ?>" <?php echo $sel; ?>>
                        <?php echo htmlspecialchars($label); ?> (<?php echo htmlspecialchars($r_item['department'] ?: 'คณะ'); ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- 3. Department Selector (Conditionally shown) -->
        <div class="form-group-custom" id="group-department" style="display: none;">
            <label class="form-label-custom">เลือกภาควิชา</label>
            <select id="config-department" class="form-select-custom" onchange="updateEmbedPreview()">
                <?php foreach ($departments_list as $d_name): ?>
                    <option value="<?php echo htmlspecialchars($d_name); ?>"><?php echo htmlspecialchars($d_name); ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- 4. Theme Selector -->
        <div class="form-group-custom">
            <label class="form-label-custom">2. ธีมและโทนสี (Theme)</label>
            <select id="config-theme" class="form-select-custom" onchange="updateEmbedPreview()">
                <option value="light" selected>Light Mode (พื้นสีขาว/เทาอ่อน สบายตา เหมาะกับหน้าเว็บหลัก)</option>
                <option value="dark">Dark Mode (ธีมมืด สไตล์โมเดิร์น กระจกแก้ว)</option>
                <option value="transparent">Transparent (พื้นหลังโปร่งใส กลืนกับพื้นหลังหน้าเว็บเดิม)</option>
            </select>
        </div>

        <!-- 5. Limit / Number of items -->
        <div class="form-group-custom" id="group-limit">
            <label class="form-label-custom">3. จำนวนผลงานที่แสดง (Item Limit)</label>
            <select id="config-limit" class="form-select-custom" onchange="updateEmbedPreview()">
                <option value="3">3 รายการ (กะทัดรัด เหมาะกับคอลัมน์ข้าง Sidebar)</option>
                <option value="5" selected>5 รายการ (แนะนำ - กำลังพอดี)</option>
                <option value="8">8 รายการ</option>
                <option value="10">10 รายการ</option>
            </select>
        </div>

        <!-- 6. Quartile Filter -->
        <div class="form-group-custom" id="group-quartile">
            <label class="form-label-custom">4. กรองระดับคุณภาพวารสาร (Quartile Filter)</label>
            <select id="config-quartile" class="form-select-custom" onchange="updateEmbedPreview()">
                <option value="" selected>แสดงทั้งหมด (All Quartiles)</option>
                <option value="Q1">เฉพาะวารสารชั้นนำ Q1 เท่านั้น</option>
                <option value="Q1_Q2">เฉพาะวารสารคุณภาพสูง Q1 และ Q2</option>
            </select>
        </div>

        <!-- 7. Display Toggles -->
        <div class="form-group-custom" style="display: flex; gap: 20px; flex-wrap: wrap; margin-top: 10px;">
            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.88rem; cursor: pointer;">
                <input type="checkbox" id="config-header" checked onchange="updateEmbedPreview()" style="accent-color: var(--color-primary); width: 16px; height: 16px;">
                <span>แสดงหัวข้อวิดเจ็ต (Show Header)</span>
            </label>
            <label style="display: flex; align-items: center; gap: 8px; font-size: 0.88rem; cursor: pointer;">
                <input type="checkbox" id="config-badge" checked onchange="updateEmbedPreview()" style="accent-color: var(--color-primary); width: 16px; height: 16px;">
                <span>แสดงป้าย MSC Research (Show Badge)</span>
            </label>
        </div>
    </div>

    <!-- Right Column: Live Preview & Generated Code -->
    <div>
        <!-- Live Preview Header -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; flex-wrap: wrap; gap: 10px;">
            <div style="font-size: 1.1rem; font-weight: 700; color: var(--color-text-main); display: flex; align-items: center; gap: 8px;">
                <i class="fa-solid fa-eye" style="color: #3b82f6;"></i>
                <span>ตัวอย่างการแสดงผลจริง (Live Preview)</span>
            </div>
            <div style="display: flex; gap: 6px; align-items: center;">
                <span style="font-size: 0.75rem; color: var(--color-text-muted);">จำลองพื้นหลัง:</span>
                <button type="button" class="tab-pill active" id="bg-btn-light" onclick="setPreviewBg('light')">ขาว</button>
                <button type="button" class="tab-pill" id="bg-btn-dark" onclick="setPreviewBg('dark')">มืด</button>
            </div>
        </div>

        <!-- Live Preview Frame Container -->
        <div class="preview-frame-wrapper" id="preview-wrapper" style="min-height: 380px; margin-bottom: 24px;">
            <iframe id="embed-preview-frame" src="" width="100%" height="450" frameborder="0" style="border: none; display: block; transition: height 0.2s;"></iframe>
        </div>

        <!-- Generated Embed Code Box -->
        <div class="glass-panel" style="padding: 22px; border-radius: 14px;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px;">
                <label style="font-size: 0.92rem; font-weight: 700; color: var(--color-text-main); display: flex; align-items: center; gap: 8px;">
                    <i class="fa-solid fa-code" style="color: #f59e0b;"></i>
                    โค้ด HTML สำหรับนำไปวางในเว็บหลัก
                </label>
                <button type="button" class="btn-premium" onclick="copyEmbedCode()" style="padding: 6px 14px; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 6px;">
                    <i class="fa-regular fa-copy" id="copy-icon"></i>
                    <span id="copy-text">คัดลอกโค้ด (Copy)</span>
                </button>
            </div>

            <div class="code-box" id="code-output"></div>

            <!-- Helpful Integration Instructions -->
            <div style="margin-top: 16px; font-size: 0.8rem; color: var(--color-text-muted); line-height: 1.6; border-top: 1px dashed rgba(255,255,255,0.1); padding-top: 14px;">
                <div style="font-weight: 600; color: var(--color-text-main); margin-bottom: 4px;">
                    <i class="fa-solid fa-circle-info" style="color: #10b981; margin-right: 4px;"></i> วิธีนำไปติดตั้งในเว็บไซต์หลัก (Integration Guide):
                </div>
                <ul style="margin-left: 20px; list-style-type: disc;">
                    <li><strong>WordPress:</strong> เพิ่มบล็อก <code>Custom HTML (HTML ที่กำหนดเอง)</code> แล้วนำโค้ดด้านบนไปวาง</li>
                    <li><strong>Elementor:</strong> ลากวิดเจ็ต <code>HTML</code> มาวางในตำแหน่งที่ต้องการ แล้วแปะโค้ดนี้</li>
                    <li><strong>เว็บไซต์ทั่วไป / Joomla:</strong> นำโค้ดไปวางในส่วน <code>&lt;body&gt;</code> ของหน้าที่ต้องการแสดงผล</li>
                    <li><em>หมายเหตุ:</em> สคริปต์ <code>embed.js</code> จะช่วยปรับขนาดความสูงของ iframe ให้พอดีกับเนื้อหาโดยอัตโนมัติ ไม่เกิดแถบเลื่อน (No double scrollbar)</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
const SYSTEM_BASE_URL = "<?php echo $system_url; ?>";

function onWidgetTypeChange() {
    const type = document.getElementById('config-type').value;
    const groupResearcher = document.getElementById('group-researcher');
    const groupDept = document.getElementById('group-department');
    const groupLimit = document.getElementById('group-limit');
    const groupQuartile = document.getElementById('group-quartile');

    groupResearcher.style.display = (type === 'researcher') ? 'block' : 'none';
    groupDept.style.display = (type === 'department') ? 'block' : 'none';
    
    // Stats overview doesn't need limit and quartile
    groupLimit.style.display = (type === 'stats') ? 'none' : 'block';
    groupQuartile.style.display = (type === 'stats') ? 'none' : 'block';

    updateEmbedPreview();
}

function updateEmbedPreview() {
    const type = document.getElementById('config-type').value;
    const theme = document.getElementById('config-theme').value;
    const limit = document.getElementById('config-limit').value;
    const quartile = document.getElementById('config-quartile').value;
    const showHeader = document.getElementById('config-header').checked ? '1' : '0';
    const showBadge = document.getElementById('config-badge').checked ? '1' : '0';

    let queryParams = new URLSearchParams();
    queryParams.set('type', type);
    queryParams.set('theme', theme);
    if (type !== 'stats') {
        queryParams.set('limit', limit);
        if (quartile) queryParams.set('quartile', quartile);
    }
    if (type === 'researcher') {
        const resId = document.getElementById('config-researcher').value;
        queryParams.set('id', resId);
    } else if (type === 'department') {
        const dept = document.getElementById('config-department').value;
        queryParams.set('dept', dept);
    }
    if (showHeader === '0') queryParams.set('header', '0');
    if (showBadge === '0') queryParams.set('badge', '0');

    const embedUrl = SYSTEM_BASE_URL + '/embed.php?' + queryParams.toString();

    // Update iframe src
    const iframe = document.getElementById('embed-preview-frame');
    iframe.src = embedUrl;

    // Generate Code snippet
    const codeSnippet = `<!-- MSC Research Embed Widget -->\n` +
`<iframe class="msc-research-widget" src="${embedUrl}" width="100%" height="450" frameborder="0" style="border:none; width:100%; overflow:hidden;"></iframe>\n` +
`<script src="${SYSTEM_BASE_URL}/embed.js"><\/script>`;

    document.getElementById('code-output').textContent = codeSnippet;
}

function setPreviewBg(mode) {
    const wrapper = document.getElementById('preview-wrapper');
    const btnLight = document.getElementById('bg-btn-light');
    const btnDark = document.getElementById('bg-btn-dark');

    if (mode === 'dark') {
        wrapper.classList.add('preview-dark-bg');
        btnDark.classList.add('active');
        btnLight.classList.remove('active');
    } else {
        wrapper.classList.remove('preview-dark-bg');
        btnLight.classList.add('active');
        btnDark.classList.remove('active');
    }
}

function copyEmbedCode() {
    const code = document.getElementById('code-output').textContent;
    navigator.clipboard.writeText(code).then(() => {
        const icon = document.getElementById('copy-icon');
        const text = document.getElementById('copy-text');
        icon.className = 'fa-solid fa-check';
        text.textContent = 'คัดลอกเรียบร้อยแล้ว!';
        setTimeout(() => {
            icon.className = 'fa-regular fa-copy';
            text.textContent = 'คัดลอกโค้ด (Copy)';
        }, 2500);
    }).catch(err => {
        alert('กรุณาคัดลอกโค้ดด้วยตนเอง');
    });
}

// Listen to postMessage from embed preview iframe for auto-resize
window.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'msc-widget-resize') {
        const iframe = document.getElementById('embed-preview-frame');
        if (iframe && event.data.height > 50) {
            iframe.style.height = (parseInt(event.data.height, 10) + 15) + 'px';
        }
    }
});

// Initial run
document.addEventListener('DOMContentLoaded', () => {
    onWidgetTypeChange();
});
</script>

<?php
require_once __DIR__ . '/admin_footer.php';
?>
