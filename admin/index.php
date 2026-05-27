<?php
ob_start();
session_save_path(__DIR__ . '/../data/sessions');
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../db.php';

$adminFile = __DIR__ . '/../data/admin.json';
$isAdmin = file_exists($adminFile);
$error = '';
$success = '';

// 处理首次设置
if (!$isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'setup') {
    $pwd = $_POST['password'] ?? '';
    $pwd2 = $_POST['password2'] ?? '';
    if (strlen($pwd) < 6) {
        $error = '密码至少6个字符';
    } elseif ($pwd !== $pwd2) {
        $error = '两次密码不一致';
    } else {
        file_put_contents($adminFile, json_encode([
            'password_hash' => password_hash($pwd, PASSWORD_DEFAULT),
            'created_at' => date('Y-m-d H:i:s')
        ]));
        $_SESSION['admin_logged_in'] = true;
        session_write_close();
        echo '<script>location.href="dashboard.php";</script>';
        exit;
    }
}

// 处理登录
if ($isAdmin && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $pwd = $_POST['password'] ?? '';
    $admin = json_decode(file_get_contents($adminFile), true);
    if ($admin && password_verify($pwd, $admin['password_hash'])) {
        $_SESSION['admin_logged_in'] = true;
        session_write_close();
        echo '<script>location.href="dashboard.php";</script>';
        exit;
    } else {
        $error = '密码错误';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员登录 - XGChat</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', -apple-system, system-ui, sans-serif;
            background: linear-gradient(135deg, #0f1119 0%, #1a1d2e 50%, #252840 100%);
            min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 20px;
        }
        .card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 40px;
            width: min(420px, 90vw);
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
        }
        .logo { text-align: center; margin-bottom: 28px; }
        .logo-icon {
            width: 56px; height: 56px; border-radius: 16px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 24px; color: #fff; margin-bottom: 12px;
            box-shadow: 0 4px 14px rgba(102,126,234,0.35);
        }
        h1 { font-size: 1.3rem; color: #1a1d2e; margin-bottom: 4px; }
        .subtitle { font-size: 0.85rem; color: #64748b; }
        .error { background: #fef2f2; color: #dc2626; border: 1px solid #fecaca; padding: 10px 14px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 16px; }
        .form-group { margin-bottom: 16px; }
        .form-group label { display: block; font-weight: 600; font-size: 0.85rem; color: #334155; margin-bottom: 6px; }
        .form-control {
            width: 100%; padding: 12px 16px;
            border: 2px solid #e2e8f0; border-radius: 12px;
            font-size: 0.95rem; outline: none; transition: all 0.2s;
            font-family: inherit;
        }
        .form-control:focus { border-color: #667eea; box-shadow: 0 0 0 3px rgba(102,126,234,0.15); }
        .btn {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff; border: none; border-radius: 12px;
            font-size: 1rem; font-weight: 600; cursor: pointer;
            transition: all 0.2s; font-family: inherit;
            box-shadow: 0 4px 14px rgba(102,126,234,0.35);
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 6px 20px rgba(102,126,234,0.45); }
        .back-link { text-align: center; margin-top: 16px; }
        .back-link a { color: #667eea; text-decoration: none; font-size: 0.85rem; }
        .back-link a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <div class="logo-icon">⚙️</div>
            <h1>XGChat 管理后台</h1>
            <p class="subtitle"><?= !$isAdmin ? '首次使用，请设置管理员密码' : '请输入管理员密码' ?></p>
        </div>

        <?php if ($error): ?><div class="error"><?= htmlspecialchars($error) ?></div><?php endif; ?>

        <?php if (!$isAdmin): ?>
        <form method="POST">
            <input type="hidden" name="action" value="setup">
            <div class="form-group">
                <label>设置管理员密码</label>
                <input type="password" name="password" class="form-control" placeholder="至少6个字符" required minlength="6" autofocus>
            </div>
            <div class="form-group">
                <label>确认密码</label>
                <input type="password" name="password2" class="form-control" placeholder="再次输入密码" required>
            </div>
            <button type="submit" class="btn">设置并进入后台</button>
        </form>
        <?php else: ?>
        <form method="POST">
            <input type="hidden" name="action" value="login">
            <div class="form-group">
                <label>管理员密码</label>
                <input type="password" name="password" class="form-control" placeholder="输入管理员密码" required autofocus>
            </div>
            <button type="submit" class="btn">登录</button>
        </form>
        <?php endif; ?>

        <div class="back-link"><a href="/">← 返回 XGChat</a></div>
    </div>
</body>
</html>