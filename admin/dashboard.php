<?php
ob_start();
session_save_path(__DIR__ . '/../data/sessions');
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

// 检查登录
if (!isset($_SESSION['admin_logged_in']) || !$_SESSION['admin_logged_in']) {
    header('Location: index.php');
    exit;
}

$adminFile = __DIR__ . '/../data/admin.json';
if (!file_exists($adminFile)) {
    header('Location: index.php');
    exit;
}

$db = getDB();
$message = '';
$messageType = '';

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'delete_user') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid > 0) {
            $db->prepare('DELETE FROM messages WHERE sender_id = ?')->execute([$uid]);
            $db->prepare('DELETE FROM friends WHERE user_id = ? OR friend_id = ?')->execute([$uid, $uid]);
            $db->prepare('DELETE FROM group_requests WHERE user_id = ? OR invited_by = ?')->execute([$uid, $uid]);
            $db->prepare('DELETE FROM conversation_members WHERE user_id = ?')->execute([$uid]);
            $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);
            $message = '用户已删除';
            $messageType = 'success';
        }
    }

    if ($action === 'reset_password') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $newPwd = $_POST['new_password'] ?? '';
        if ($uid > 0 && strlen($newPwd) >= 6) {
            $hash = password_hash($newPwd, PASSWORD_DEFAULT);
            $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$hash, $uid]);
            $message = '密码已重置';
            $messageType = 'success';
        } else {
            $message = '密码至少6个字符';
            $messageType = 'error';
        }
    }

    if ($action === 'change_admin_pwd') {
        $oldPwd = $_POST['old_password'] ?? '';
        $newPwd = $_POST['new_password'] ?? '';
        $admin = json_decode(file_get_contents($adminFile), true);
        if (!password_verify($oldPwd, $admin['password_hash'])) {
            $message = '旧密码错误';
            $messageType = 'error';
        } elseif (strlen($newPwd) < 6) {
            $message = '新密码至少6个字符';
            $messageType = 'error';
        } else {
            $admin['password_hash'] = password_hash($newPwd, PASSWORD_DEFAULT);
            file_put_contents($adminFile, json_encode($admin));
            $message = '管理员密码已修改';
            $messageType = 'success';
        }
    }

    if ($action === 'logout') {
        session_destroy();
        header('Location: index.php');
        exit;
    }
}

// 查询所有用户
$users = $db->query('SELECT * FROM users ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC);

// 统计
$totalUsers = count($users);
$totalMessages = $db->query('SELECT COUNT(*) FROM messages')->fetchColumn();
$totalConversations = $db->query('SELECT COUNT(*) FROM conversations')->fetchColumn();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理后台 - XGChat</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', -apple-system, system-ui, sans-serif; background: #f0f2f7; color: #1a1d2e; }
        .navbar {
            background: #fff; border-bottom: 1px solid #e2e8f0; padding: 0 24px;
            display: flex; align-items: center; height: 56px; position: sticky; top: 0; z-index: 100;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .nav-brand { font-weight: 700; font-size: 1.1rem; color: #1a1d2e; text-decoration: none; display: flex; align-items: center; gap: 8px; }
        .nav-brand em { font-style: normal; background: linear-gradient(135deg, #667eea, #764ba2); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
        .nav-right { margin-left: auto; display: flex; gap: 8px; }
        .btn-nav { padding: 6px 14px; border-radius: 8px; font-size: 0.85rem; cursor: pointer; border: none; font-family: inherit; transition: all 0.15s; }
        .btn-nav:hover { opacity: 0.85; }
        .btn-logout { background: #fee2e2; color: #dc2626; }
        .btn-home { background: #f1f5f9; color: #334155; text-decoration: none; }
        .container { max-width: 1100px; margin: 0 auto; padding: 24px 16px; }

        /* Stats */
        .stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-bottom: 24px; }
        .stat-card { background: #fff; border-radius: 16px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); }
        .stat-label { font-size: 0.8rem; color: #64748b; margin-bottom: 4px; }
        .stat-value { font-size: 1.8rem; font-weight: 700; color: #1a1d2e; }

        /* Panel */
        .panel { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 1px 3px rgba(0,0,0,0.06); margin-bottom: 20px; }
        .panel h3 { font-size: 1rem; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

        /* Table */
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 10px 12px; text-align: left; border-bottom: 1px solid #f1f5f9; font-size: 0.85rem; }
        th { font-weight: 600; color: #64748b; font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.5px; }
        tr:hover td { background: #f8f9fc; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 6px; font-size: 0.7rem; font-weight: 600; }
        .badge-online { background: #dcfce7; color: #16a34a; }
        .badge-offline { background: #f1f5f9; color: #64748b; }

        /* Actions */
        .actions { display: flex; gap: 4px; }
        .btn-sm { padding: 4px 10px; border-radius: 6px; font-size: 0.75rem; cursor: pointer; border: none; font-family: inherit; transition: all 0.15s; }
        .btn-danger { background: #fee2e2; color: #dc2626; }
        .btn-danger:hover { background: #fecaca; }
        .btn-primary { background: #eff6ff; color: #2563eb; }
        .btn-primary:hover { background: #dbeafe; }

        /* Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.4); z-index: 200; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-box { background: #fff; border-radius: 20px; padding: 28px; width: min(400px, 90vw); box-shadow: 0 20px 60px rgba(0,0,0,0.2); }
        .modal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; }
        .modal-header h3 { font-size: 1.05rem; }
        .modal-close { background: none; border: none; font-size: 1.3rem; cursor: pointer; color: #94a3b8; padding: 4px 8px; border-radius: 8px; }
        .modal-close:hover { background: #f1f5f9; }
        .form-group { margin-bottom: 14px; }
        .form-group label { display: block; font-weight: 600; font-size: 0.8rem; color: #475569; margin-bottom: 5px; }
        .form-control { width: 100%; padding: 10px 14px; border: 1.5px solid #e2e8f0; border-radius: 10px; font-size: 0.9rem; outline: none; font-family: inherit; transition: all 0.2s; }
        .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.12); }
        .btn { padding: 10px 20px; border-radius: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; border: none; font-family: inherit; transition: all 0.15s; }
        .btn-gradient { background: linear-gradient(135deg, #667eea, #764ba2); color: #fff; box-shadow: 0 4px 12px rgba(102,126,234,0.3); }
        .btn-gradient:hover { box-shadow: 0 6px 18px rgba(102,126,234,0.4); }
        .btn-secondary { background: #f1f5f9; color: #334155; }
        .modal-btn-row { display: flex; gap: 8px; margin-top: 16px; }
        .modal-btn-row .btn { flex: 1; }

        /* Message */
        .msg { padding: 10px 14px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 16px; }
        .msg-success { background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; }
        .msg-error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; }

        /* Responsive */
        @media (max-width: 640px) {
            .container { padding: 12px 8px; }
            th, td { padding: 8px 6px; font-size: 0.75rem; }
            .stats { grid-template-columns: repeat(3, 1fr); gap: 8px; }
            .stat-card { padding: 12px; }
            .stat-value { font-size: 1.3rem; }
            .actions { flex-direction: column; }
        }
    </style>
</head>
<body>
    <nav class="navbar">
        <a href="/" class="nav-brand">⚙️ <em>XGChat</em> 管理后台</a>
        <div class="nav-right">
            <a href="/" class="btn-nav btn-home">← 前台</a>
            <form method="POST" style="display:inline"><input type="hidden" name="action" value="logout"><button class="btn-nav btn-logout">退出</button></form>
        </div>
    </nav>

    <div class="container">
        <?php if ($message): ?>
        <div class="msg msg-<?= $messageType ?>"><?= htmlspecialchars($message) ?></div>
        <?php endif; ?>

        <!-- 统计 -->
        <div class="stats">
            <div class="stat-card"><div class="stat-label">注册用户</div><div class="stat-value"><?= $totalUsers ?></div></div>
            <div class="stat-card"><div class="stat-label">消息总数</div><div class="stat-value"><?= $totalMessages ?></div></div>
            <div class="stat-card"><div class="stat-label">对话数</div><div class="stat-value"><?= $totalConversations ?></div></div>
        </div>

        <!-- 用户列表 -->
        <div class="panel">
            <h3>👥 注册用户 (<?= $totalUsers ?>)</h3>
            <?php if (empty($users)): ?>
            <p style="color:#94a3b8;text-align:center;padding:20px">暂无注册用户</p>
            <?php else: ?>
            <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr><th>ID</th><th>用户名</th><th>昵称</th><th>状态</th><th>注册时间</th><th>操作</th></tr>
                </thead>
                <tbody>
                <?php foreach ($users as $u): ?>
                <tr>
                    <td><?= $u['id'] ?></td>
                    <td><strong><?= htmlspecialchars($u['username']) ?></strong></td>
                    <td><?= htmlspecialchars($u['nickname']) ?></td>
                    <td><span class="badge <?= $u['status']==='online'?'badge-online':'badge-offline' ?>"><?= $u['status']==='online'?'在线':'离线' ?></span></td>
                    <td><?= $u['created_at'] ?? '-' ?></td>
                    <td class="actions">
                        <button class="btn-sm btn-primary" onclick="showResetPwd(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">重置密码</button>
                        <button class="btn-sm btn-danger" onclick="confirmDelete(<?= $u['id'] ?>, '<?= htmlspecialchars($u['username']) ?>')">删除</button>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </div>

        <!-- 修改管理员密码 -->
        <div class="panel">
            <h3>🔑 修改管理员密码</h3>
            <form method="POST" style="max-width:400px">
                <input type="hidden" name="action" value="change_admin_pwd">
                <div class="form-group"><label>旧密码</label><input type="password" name="old_password" class="form-control" required></div>
                <div class="form-group"><label>新密码</label><input type="password" name="new_password" class="form-control" required minlength="6"></div>
                <button type="submit" class="btn btn-gradient">修改密码</button>
            </form>
        </div>
    </div>

    <!-- 删除确认弹窗 -->
    <div class="modal-overlay" id="deleteModal">
        <div class="modal-box">
            <div class="modal-header"><h3>⚠️ 删除用户</h3><button class="modal-close" onclick="document.getElementById('deleteModal').style.display='none'">&times;</button></div>
            <p style="color:#64748b;margin-bottom:12px">确定要删除用户 <strong id="deleteUserName"></strong> 吗？</p>
            <p style="color:#dc2626;font-size:0.85rem;margin-bottom:16px">该用户的所有聊天记录、好友关系将被永久删除，此操作不可撤销。</p>
            <form method="POST">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" id="deleteUserId">
                <div class="modal-btn-row">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('deleteModal').style.display='none'">取消</button>
                    <button type="submit" class="btn btn-gradient" style="background:linear-gradient(135deg,#ef4444,#dc2626)">确认删除</button>
                </div>
            </form>
        </div>
    </div>

    <!-- 重置密码弹窗 -->
    <div class="modal-overlay" id="resetModal">
        <div class="modal-box">
            <div class="modal-header"><h3>🔑 重置密码</h3><button class="modal-close" onclick="document.getElementById('resetModal').style.display='none'">&times;</button></div>
            <p style="color:#64748b;margin-bottom:16px">为用户 <strong id="resetUserName"></strong> 设置新密码</p>
            <form method="POST">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="resetUserId">
                <div class="form-group"><label>新密码</label><input type="password" name="new_password" class="form-control" required minlength="6" placeholder="至少6个字符"></div>
                <div class="modal-btn-row">
                    <button type="button" class="btn btn-secondary" onclick="document.getElementById('resetModal').style.display='none'">取消</button>
                    <button type="submit" class="btn btn-gradient">重置密码</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function confirmDelete(id, name) {
            document.getElementById('deleteUserId').value = id;
            document.getElementById('deleteUserName').textContent = name;
            document.getElementById('deleteModal').style.display = 'flex';
        }
        function showResetPwd(id, name) {
            document.getElementById('resetUserId').value = id;
            document.getElementById('resetUserName').textContent = name;
            document.getElementById('resetModal').style.display = 'flex';
        }
        // 点击遮罩关闭
        document.querySelectorAll('.modal-overlay').forEach(m => {
            m.addEventListener('click', e => { if (e.target === m) m.style.display = 'none'; });
        });
    </script>
</body>
</html>