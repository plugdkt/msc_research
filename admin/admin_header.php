<?php
// admin/admin_header.php
// Admin area header

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? $page_title . ' - Admin Panel' : 'ระบบจัดการข้อมูลวิจัย - Admin Panel'; ?></title>
    
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Sarabun:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&display=swap" rel="stylesheet">
    
    <!-- FontAwesome Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Admin stylesheet overrides -->
    <link rel="stylesheet" href="../assets/css/style.css?v=<?php echo time(); ?>">
    <style>
        .admin-nav-menu {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        .btn-logout {
            background: rgba(239, 68, 68, 0.15);
            color: #f87171;
            border: 1px solid rgba(239, 68, 68, 0.3);
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 0.9rem;
            transition: var(--transition-smooth);
        }
        .btn-logout:hover {
            background: rgba(239, 68, 68, 0.3);
            color: white;
            box-shadow: 0 4px 12px rgba(239, 68, 68, 0.2);
        }
        
        /* Dropdown Submenu CSS */
        .nav-dropdown {
            position: relative;
            display: inline-block;
        }
        .dropdown-menu {
            display: none;
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            background: #0f172a;
            border: 1px solid var(--border-glass);
            border-radius: 12px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.6);
            min-width: 180px;
            z-index: 9999;
            padding: 8px 0;
            margin-top: 8px;
            animation: dropdownFadeIn 0.2s ease;
        }
        .dropdown-menu::before {
            content: '';
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-width: 6px;
            border-style: solid;
            border-color: transparent transparent var(--border-glass) transparent;
        }
        .nav-dropdown:hover .dropdown-menu {
            display: block;
        }
        .dropdown-item {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 16px;
            color: var(--color-text-muted);
            text-decoration: none;
            font-size: 0.88rem;
            transition: var(--transition-smooth);
            text-align: left;
        }
        .dropdown-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: var(--color-primary);
        }
        .dropdown-item.active {
            color: var(--color-primary);
            background: rgba(139, 92, 246, 0.1);
            font-weight: 500;
        }
        @keyframes dropdownFadeIn {
            from { opacity: 0; transform: translate(-50%, 10px); }
            to { opacity: 1; transform: translate(-50%, 0); }
        }
    </style>
</head>
<body>
    <!-- Ambient Glow effects for modern UI depth -->
    <div class="ambient-glow-1"></div>
    <div class="ambient-glow-2"></div>

    <header>
        <div class="container nav-container">
            <a href="index.php" class="brand">
                <img src="../Logo MedSci_Inter.png" alt="Logo" class="nav-logo" style="height: 48px; object-fit: contain;">
                <div class="brand-text">
                    <h1>MSC Aggregator <span style="color: var(--color-accent);">Admin</span></h1>
                    <p>ระบบจัดการหลังบ้านสำหรับผู้ดูแลระบบ</p>
                </div>
            </a>
            
            <div class="nav-actions">
                <button id="mobile-menu-toggle" class="mobile-toggle" aria-label="Toggle Menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>
            
            <nav class="admin-nav-menu">
                <a href="../index.php" class="nav-link" target="_blank">
                    <i class="fa-solid fa-eye"></i> หน้าเว็บหลัก
                </a>
                
                <div class="nav-dropdown">
                    <a href="index.php" class="nav-link <?php echo in_array($current_page, ['admin_dashboard', 'admin_researchers', 'admin_publications', 'admin_corresponding', 'admin_users', 'admin_import', 'admin_sync', 'admin_sdgs', 'admin_topics']) ? 'active' : ''; ?>">
                        <i class="fa-solid fa-gauge"></i> แผงควบคุม <i class="fa-solid fa-chevron-down" style="font-size: 0.7rem; margin-left: 2px;"></i>
                    </a>
                    <div class="dropdown-menu">
                        <a href="researchers.php" class="dropdown-item <?php echo $current_page === 'admin_researchers' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-user-gear"></i> จัดการนักวิจัย
                        </a>
                        <a href="publications.php" class="dropdown-item <?php echo $current_page === 'admin_publications' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-book"></i> จัดการผลงาน
                        </a>
                        <a href="corresponding_publications.php" class="dropdown-item <?php echo $current_page === 'admin_corresponding' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-envelope-open-text"></i> จัดการผู้แต่งประสานงาน
                        </a>
                        <a href="import.php" class="dropdown-item <?php echo $current_page === 'admin_import' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-file-import"></i> นำเข้าแมนนวล
                        </a>
                        <a href="sync.php" class="dropdown-item <?php echo $current_page === 'admin_sync' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-rotate"></i> Sync API
                        </a>
                        <?php if (($_SESSION['admin_role'] ?? '') === 'superadmin'): ?>
                        <a href="users.php" class="dropdown-item <?php echo $current_page === 'admin_users' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-users-cog"></i> จัดการแอดมิน
                        </a>
                        <?php endif; ?>
                        <a href="scopus_quartiles.php" class="dropdown-item <?php echo $current_page === 'admin_scimago' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-award"></i> จัดการ Quartiles (Scopus)
                        </a>
                        <a href="sdg_import.php" class="dropdown-item <?php echo $current_page === 'admin_sdgs' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-leaf"></i> จัดการ SDGs (ผลงานวิจัย)
                        </a>
                        <a href="topics_import.php" class="dropdown-item <?php echo $current_page === 'admin_topics' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-diagram-project"></i> Topic Prominence &amp; Trends
                        </a>
                    </div>
                </div>

                <a href="logout.php" class="btn-logout">
                    <i class="fa-solid fa-sign-out-alt"></i> ออกระบบ
                </a>
            </nav>
        </div>
    </header>

    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const toggleBtn = document.getElementById('mobile-menu-toggle');
        const navMenu = document.querySelector('.admin-nav-menu');
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
