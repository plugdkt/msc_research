<?php
// includes/header.php
// Header layout with premium design assets and navbar
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - MSC Research' : 'MSC Research Publications Aggregator'; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Chart.js for premium analytics graphics -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <!-- Custom Premium Stylesheet -->
    <link rel="stylesheet" href="<?php echo $base_url ?? './'; ?>assets/css/style.css?v=<?php echo time(); ?>">
</head>
<body>
    <!-- Ambient Glow effects for modern UI depth -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <header>
        <div class="container nav-container">
            <a href="<?php echo $base_url ?? './'; ?>index.php" class="brand">
                <img src="<?php echo $base_url ?? './'; ?>Logo MedSci_Inter.png" alt="Logo" class="nav-logo" style="height: 48px; object-fit: contain;">
                <div class="brand-text">
                    <h1>Medical Sciences Research</h1>
                    <p>คณะวิทยาศาสตร์การแพทย์ มหาวิทยาลัยพะเยา</p>
                </div>
            </a>
            
            <div class="nav-actions">
                <button id="theme-toggle" class="theme-switch" title="เปลี่ยนโหมดสี">
                    <i id="theme-icon" class="fas fa-sun"></i>
                </button>
                <button id="mobile-menu-toggle" class="mobile-toggle" aria-label="Toggle Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
            
            <nav class="nav-menu">
                <a href="<?php echo $base_url ?? './'; ?>index.php" class="nav-link <?php echo ($current_page ?? '') === 'home' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-line"></i> แดชบอร์ด
                </a>
                <a href="<?php echo $base_url ?? './'; ?>publications_search.php" class="nav-link <?php echo ($current_page ?? '') === 'publications_search' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-book"></i> ค้นหางานวิจัย
                </a>
                <a href="<?php echo $base_url ?? './'; ?>researchers_list.php" class="nav-link <?php echo ($current_page ?? '') === 'researchers' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-users"></i> นักวิจัย
                </a>
                <a href="<?php echo $base_url ?? './'; ?>reports.php" class="nav-link <?php echo ($current_page ?? '') === 'reports' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-chart-pie"></i> รายงานสรุป
                </a>
                <a href="<?php echo $base_url ?? './'; ?>grants.php" class="nav-link <?php echo ($current_page ?? '') === 'grants' ? 'active' : ''; ?>">
                    <i class="fa-solid fa-hand-holding-dollar"></i> Grant Outputs
                </a>
                <a href="<?php echo $base_url ?? './'; ?>admin/index.php" class="nav-link">
                    <i class="fa-solid fa-lock"></i> ระบบ Admin
                </a>
            </nav>
        </div>
    </header>

    <?php
    // DASH-06: last-synced timestamp, shown on every public page. $pdo comes
    // from the including page (via includes/functions.php -> config/db.php).
    $header_last_sync = isset($pdo) ? get_last_sync_time($pdo) : null;
    ?>
    <div class="container" style="text-align: center; margin: 12px auto 0;">
        <span style="display: inline-flex; align-items: center; gap: 8px; font-size: 0.8rem; color: var(--color-text-muted); background: rgba(255,255,255,0.04); padding: 6px 16px; border-radius: 30px; border: 1px solid var(--border-glass);">
            <i class="fa-solid fa-rotate" style="color: var(--color-primary);"></i>
            <span>ซิงค์ข้อมูลล่าสุด: <strong><?php echo format_thai_datetime($header_last_sync); ?></strong></span>
        </span>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById('mobile-menu-toggle');
        const navMenu = document.querySelector('.nav-menu');
        if (toggleBtn && navMenu) {
            toggleBtn.addEventListener('click', () => {
                navMenu.classList.toggle('show');
                const icon = toggleBtn.querySelector('i');
                if (navMenu.classList.contains('show')) {
                    icon.className = 'fa-solid fa-xmark';
                } else {
                    icon.className = 'fa-solid fa-bars';
                }
            });
        }
    });
    </script>

    <main class="container">
