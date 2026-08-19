<?php
// check_orcid.php
// Diagnostic tool to check ORCID sync specifically for Kritpaphat Tantiamornkul

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/functions.php';

echo "<h2>ระบบตรวจสอบการซิงค์ ORCID รายบุคคล</h2>";
echo "<hr>";

// 1. Find researcher in database
try {
    $stmt = $pdo->prepare("SELECT * FROM `researchers` WHERE orcid_id LIKE '%0000-0002-7797-1919%' OR last_name_en LIKE '%Tantiamornkul%'");
    $stmt->execute();
    $r = $stmt->fetch();
    
    if (!$r) {
        echo "<p style='color:red;'><strong>ไม่พบนักวิจัยชื่อ Kritpaphat Tantiamornkul หรือรหัส ORCID 0000-0002-7797-1919 ในฐานข้อมูล!</strong></p>";
        echo "<p>กรุณาตรวจสอบว่าคุณได้เพิ่มประวัตินักวิจัยรายนี้ในหน้า Admin/Researchers แล้วหรือยัง</p>";
        exit;
    }
    
    echo "<h3>พบข้อมูลนักวิจัยในระบบ:</h3>";
    echo "<ul>";
    echo "<li>ID ในระบบ: <strong>{$r['id']}</strong></li>";
    echo "<li>ชื่อ: <strong>{$r['title_th']} {$r['first_name_th']} {$r['last_name_th']}</strong></li>";
    echo "<li>Name (EN): <strong>{$r['title_en']} {$r['first_name_en']} {$r['last_name_en']}</strong></li>";
    echo "<li>ORCID ID: <strong>'{$r['orcid_id']}'</strong></li>";
    echo "</ul>";
    
    // 2. Fetch from ORCID API
    echo "<h3>ทดสอบเรียก ORCID API (0000-0002-7797-1919):</h3>";
    $pubs = fetch_orcid_publications(trim($r['orcid_id']));
    
    echo "<p>จำนวนผลงานที่ดึงมาจาก ORCID API: <strong>" . count($pubs) . " รายการ</strong></p>";
    
    if (!empty($pubs)) {
        echo "<h4>รายการผลงานที่ดึงมาได้:</h4>";
        echo "<ol>";
        foreach ($pubs as $idx => $pub) {
            echo "<li>";
            echo "<strong>Title:</strong> " . htmlspecialchars($pub['title']) . "<br>";
            echo "<strong>Year:</strong> " . htmlspecialchars($pub['publish_year']) . "<br>";
            echo "<strong>Journal:</strong> " . htmlspecialchars($pub['journal_name']) . "<br>";
            echo "<strong>DOI:</strong> " . ($pub['doi'] ? htmlspecialchars($pub['doi']) : "<span style='color:red;'>ไม่มี (NULL)</span>") . "<br>";
            
            // Try saving
            echo "<strong>สถานะการบันทึก:</strong> ";
            $pubId = add_or_update_publication($pdo, $pub, $r['id']);
            if ($pubId) {
                echo "<span style='color:green;'>บันทึกสำเร็จ (ID: {$pubId})</span>";
            } else {
                echo "<span style='color:red;'>ล้มเหลว</span>";
            }
            echo "</li><br>";
        }
        echo "</ol>";
    } else {
        echo "<p style='color:red;'>ดึงข้อมูลจาก ORCID API ไม่สำเร็จ (ได้ข้อมูล 0 รายการ)</p>";
        echo "<p>ความเป็นไปได้:</p>";
        echo "1. รหัส ORCID ID ที่กรอกมีช่องว่างแฝงอยู่ (เช่น มี Space นำหน้าหรือต่อท้าย)<br>";
        echo "2. ผลงานใน ORCID Profile ของนักวิจัยท่านนี้ถูกตั้งค่าความเป็นส่วนตัวไว้เป็น Private (ต้องตั้งค่าผลงานเป็น Public ในเว็บ orcid.org เพื่อให้ API ดึงได้)<br>";
    }
    
} catch (Exception $e) {
    echo "<p style='color:red;'>เกิดข้อผิดพลาด: " . $e->getMessage() . "</p>";
}
