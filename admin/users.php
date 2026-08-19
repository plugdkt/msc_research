<?php
// admin/users.php
// Manage SSO admin whitelist — username only, no password stored

$current_page = 'admin_users';
$page_title = 'จัดการสิทธิ์แอดมิน - Admin Panel';

require_once __DIR__ . '/admin_header.php';
require_once __DIR__ . '/../config/db.php';

// SECURITY: this page grants/revokes admin access and sets roles (including
// superadmin) for ANY username — without this gate, any logged-in 'admin'
// could grant themselves 'superadmin' or add/remove other admins, making
// the two-tier role model meaningless. Only superadmin may manage users.
if (($_SESSION['admin_role'] ?? '') !== 'superadmin') {
    http_response_code(403);
    die('เฉพาะ Superadmin เท่านั้นที่มีสิทธิ์จัดการผู้ใช้งาน');
}

$message = '';
$message_type = 'success';
$current_username = $_SESSION['admin_username'] ?? '';

// ── Handle Delete ────────────────────────────────────────────────────────────
if (isset($_GET['delete'])) {
    $del_id = (int)$_GET['delete'];
    $chk = $pdo->prepare("SELECT username FROM `users` WHERE id = ?");
    $chk->execute([$del_id]);
    $target = $chk->fetchColumn();

    if ($target === $current_username) {
        $message = 'ไม่สามารถลบสิทธิ์ของตัวเองได้';
        $message_type = 'error';
    } elseif ($target) {
        $pdo->prepare("DELETE FROM `users` WHERE id = ?")->execute([$del_id]);
        $message = "ถอนสิทธิ์ผู้ใช้ <strong>" . htmlspecialchars($target) . "</strong> เรียบร้อยแล้ว";
    }
}

// ── Handle Add ───────────────────────────────────────────────────────────────
if (isset($_POST['save_user'])) {
    $username = trim($_POST['username'] ?? '');
    $role     = in_array($_POST['role'] ?? '', ['admin', 'superadmin']) ? $_POST['role'] : 'admin';

    if (empty($username)) {
        $message      = 'กรุณากรอก Username ของผู้ใช้ SSO';
        $message_type = 'error';
    } else {
        try {
            // password_hash ยังคงอยู่ในตารางเพื่อ backward-compat local login แต่ใส่ค่า random ที่ไม่มีใครรู้
            $pdo->prepare("INSERT INTO `users` (username, password_hash, role) VALUES (?, ?, ?)")
                ->execute([$username, password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT), $role]);
            $message = "เพิ่มสิทธิ์ให้ <strong>" . htmlspecialchars($username) . "</strong> เรียบร้อยแล้ว — เมื่อผู้ใช้นี้ Login ผ่าน SSO จะสามารถเข้าระบบได้ทันที";
        } catch (PDOException $e) {
            $message      = 'Username นี้มีสิทธิ์อยู่แล้วในระบบ';
            $message_type = 'error';
        }
    }
}

// ── Handle Role Update ───────────────────────────────────────────────────────
if (isset($_POST['update_role'])) {
    $uid  = (int)$_POST['user_id'];
    $role = in_array($_POST['role'] ?? '', ['admin', 'superadmin']) ? $_POST['role'] : 'admin';
    $pdo->prepare("UPDATE `users` SET role = ? WHERE id = ?")->execute([$role, $uid]);
    $message = "อัปเดตสิทธิ์เรียบร้อยแล้ว";
}

// ── Fetch all users ───────────────────────────────────────────────────────
$users = $pdo->query("SELECT id, username, role, created_at FROM `users` ORDER BY id ASC")->fetchAll();

$show_add_modal = isset($_GET['add_new']) || (!empty($message) && $message_type === 'error' && isset($_POST['save_user']));
?>

<style>
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    display: flex; align-items: center; justify-content: center;
    z-index: 1000; animation: fadeIn 0.2s ease;
}
.modal-content {
    width: 100%; max-width: 400px; padding: 30px;
    border-radius: 16px; position: relative; animation: slideUp 0.25s ease;
}
.modal-close-btn {
    position: absolute; top: 14px; right: 18px;
    font-size: 1.6rem; color: var(--color-text-muted);
    text-decoration: none; line-height: 1; transition: color 0.2s;
}
.modal-close-btn:hover { color: var(--color-error); }
@keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
@keyframes slideUp { from { transform: translateY(30px); opacity:0; } to { transform: translateY(0); opacity:1; } }
</style>

<div class="hero glass-panel animate-fade-in" style="padding: 30px 20px; margin-bottom: 30px;">
    <h2>จัดการสิทธิ์แอดมิน (SSO Whitelist)</h2>
    <p>เพิ่มหรือถอนสิทธิ์เข้าถึงระบบหลังบ้านโดยระบุ <strong>Username SSO</strong> ของบุคลากร — ผู้ที่ไม่มีชื่อในรายการนี้จะไม่สามารถเข้าระบบได้แม้จะผ่านการยืนยันตัวตน SSO สำเร็จ</p>
</div>

<?php if ($message): ?>
    <div class="glass-panel animate-fade-in" style="padding: 15px 20px; border-radius: 12px; margin-bottom: 25px;
         background: <?php echo $message_type === 'success' ? 'rgba(16,185,129,0.15)' : 'rgba(239,68,68,0.15)'; ?>;
         border-color: <?php echo $message_type === 'success' ? '#10b981' : '#ef4444'; ?>;
         color: <?php echo $message_type === 'success' ? '#34d399' : '#f87171'; ?>;">
        <i class="fa-solid <?php echo $message_type === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
        <?php echo $message; ?>
    </div>
<?php endif; ?>

<!-- Users Table -->
<div class="glass-panel animate-fade-in" style="padding: 30px;">
    <div style="display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
        <h3 style="margin: 0; font-weight: 600;">
            <i class="fa-solid fa-shield-halved" style="color: var(--color-accent); margin-right: 8px;"></i>
            รายชื่อผู้มีสิทธิ์เข้าระบบ
        </h3>
        <a href="users.php?add_new=1" class="btn-premium" style="padding: 10px 20px; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; font-weight: 600;">
            <i class="fa-solid fa-user-plus"></i> เพิ่มสิทธิ์แอดมิน
        </a>
    </div>

    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: collapse; font-size: 0.9rem;">
            <thead>
                <tr style="border-bottom: 2px solid var(--border-glass); color: var(--color-text-muted);">
                    <th style="padding: 12px 8px;">#</th>
                    <th style="padding: 12px 8px;">Username (SSO)</th>
                    <th style="padding: 12px 8px; width: 200px;">สิทธิ์ (Role)</th>
                    <th style="padding: 12px 8px; width: 160px;">วันที่เพิ่ม</th>
                    <th style="padding: 12px 8px; text-align: center; width: 100px;">จัดการ</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="5" style="padding: 40px; text-align: center; color: var(--color-text-muted);">
                            <i class="fa-regular fa-folder-open" style="font-size: 3rem; display: block; margin-bottom: 15px;"></i>
                            ไม่พบผู้ใช้ในระบบ
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $u): ?>
                        <tr style="border-bottom: 1px solid rgba(255,255,255,0.03); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.02)'" onmouseout="this.style.background='none'">
                            <td style="padding: 12px 8px; color: var(--color-text-muted); font-family: var(--font-eng);"><?php echo $u['id']; ?></td>
                            <td style="padding: 12px 8px; font-weight: 600; font-family: var(--font-eng);">
                                <i class="fa-solid fa-circle-user" style="color: var(--color-primary); margin-right: 8px;"></i>
                                <?php echo htmlspecialchars($u['username']); ?>
                                <?php if ($u['username'] === $current_username): ?>
                                    <span style="font-size: 0.7rem; background: rgba(16,185,129,0.2); color: #34d399; border: 1px solid rgba(16,185,129,0.3); padding: 2px 8px; border-radius: 20px; margin-left: 8px; font-weight: 500; font-family: var(--font-main);">คุณ</span>
                                <?php endif; ?>
                            </td>
                            <td style="padding: 12px 8px;">
                                <!-- Inline role update form -->
                                <form method="POST" action="users.php" style="display: flex; gap: 8px; align-items: center;">
                                    <input type="hidden" name="user_id" value="<?php echo $u['id']; ?>">
                                    <select name="role" class="search-input" style="padding: 5px 10px; height: auto; background: rgba(0,0,0,0.25); font-size: 0.82rem; flex: 1;">
                                        <option value="admin" <?php echo $u['role'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                                        <option value="superadmin" <?php echo $u['role'] === 'superadmin' ? 'selected' : ''; ?>>Superadmin</option>
                                    </select>
                                    <button type="submit" name="update_role" class="btn-premium" style="padding: 5px 10px; font-size: 0.75rem; box-shadow: none; white-space: nowrap;" title="บันทึกสิทธิ์">
                                        <i class="fa-solid fa-check"></i>
                                    </button>
                                </form>
                            </td>
                            <td style="padding: 12px 8px; color: var(--color-text-muted); font-size: 0.82rem; font-family: var(--font-eng);">
                                <?php echo date('d/m/Y H:i', strtotime($u['created_at'])); ?>
                            </td>
                            <td style="padding: 12px 8px; text-align: center;">
                                <?php if ($u['username'] !== $current_username): ?>
                                    <a href="users.php?delete=<?php echo $u['id']; ?>"
                                       onclick="return confirm('ถอนสิทธิ์ <?php echo htmlspecialchars($u['username']); ?>? ผู้ใช้นี้จะไม่สามารถเข้าระบบได้อีก')"
                                       class="btn-logout" style="padding: 6px 10px; font-size: 0.75rem; border-radius: 8px;" title="ถอนสิทธิ์">
                                        <i class="fa-solid fa-trash"></i>
                                    </a>
                                <?php else: ?>
                                    <span style="color: var(--color-text-muted); font-size: 0.75rem;">-</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Admin Modal -->
<div class="modal-overlay" style="display: <?php echo $show_add_modal ? 'flex' : 'none'; ?>;">
    <div class="modal-content glass-panel">
        <a href="users.php" class="modal-close-btn">&times;</a>
        <h3 style="margin-bottom: 8px; font-weight: 600; border-bottom: 1px solid var(--border-glass); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
            <i class="fa-solid fa-user-shield" style="color: var(--color-primary);"></i>
            เพิ่มสิทธิ์แอดมิน
        </h3>
        <p style="font-size: 0.82rem; color: var(--color-text-muted); margin-bottom: 20px;">
            ระบุ Username SSO ของบุคลากรที่ต้องการให้เข้าถึงระบบหลังบ้านได้
        </p>
        <form method="POST" action="users.php?add_new=1" style="display: flex; flex-direction: column; gap: 14px;">
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">Username SSO *</label>
                <input type="text" name="username" class="search-input" style="padding: 9px 12px;"
                       required placeholder="เช่น wittaya.su" autocomplete="off"
                       value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div>
                <label style="font-size: 0.8rem; color: var(--color-text-muted);">สิทธิ์ (Role)</label>
                <select name="role" class="search-input" style="padding: 9px 12px; height: auto; background: rgba(0,0,0,0.25);">
                    <option value="admin">Admin</option>
                    <option value="superadmin">Superadmin</option>
                </select>
            </div>
            <div style="display: flex; gap: 10px; margin-top: 6px;">
                <button type="submit" name="save_user" class="btn-premium" style="flex: 1; padding: 12px; justify-content: center; font-size: 0.95rem; font-weight: 600;">
                    <i class="fa-solid fa-plus"></i> เพิ่มสิทธิ์
                </button>
                <a href="users.php" class="btn-premium" style="flex: 1; padding: 12px; text-align: center; text-decoration: none; background: rgba(255,255,255,0.05); border-color: var(--border-glass); color: var(--color-text-main); font-size: 0.95rem; font-weight: 600;">
                    ยกเลิก
                </a>
            </div>
        </form>
    </div>
</div>

<?php require_once __DIR__ . '/admin_footer.php'; ?>
