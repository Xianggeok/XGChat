<?php
require_once __DIR__ . '/auth.php';
if (isLoggedIn()) { header('Location: index.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = '请填写用户名和密码';
    } else {
        $db = getDB();
        $stmt = $db->prepare('SELECT id, password_hash FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            loginUser($user['id']);
            header('Location: index.php');
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - XGChat</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            background: #f0f2f7;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        body::before {
            content: '';
            position: fixed;
            top: -30%; right: -20%;
            width: 70vw; height: 70vw;
            background: radial-gradient(circle, rgba(102,126,234,0.3) 0%, transparent 70%);
            pointer-events: none;
            animation: bgFloat 15s ease-in-out infinite;
        }
        body::after {
            content: '';
            position: fixed;
            bottom: -25%; left: -15%;
            width: 60vw; height: 60vw;
            background: radial-gradient(circle, rgba(118,75,162,0.2) 0%, transparent 70%);
            pointer-events: none;
            animation: bgFloat 18s ease-in-out infinite reverse;
        }
        @keyframes bgFloat {
            0%, 100% { transform: translate(0,0) scale(1); }
            50% { transform: translate(-2%,2%) scale(0.95); }
        }
        .login-container {
            position: relative; z-index: 1;
            background: rgba(255,255,255,0.85);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-radius: 24px;
            padding: 48px 40px;
            width: min(420px, 90vw);
            box-shadow: 0 12px 40px rgba(0,0,0,0.08), 0 4px 12px rgba(0,0,0,0.04);
            border: 1px solid rgba(255,255,255,0.5);
        }
        .logo { text-align: center; margin-bottom: 32px; }
        .logo-icon {
            width: 64px; height: 64px;
            border-radius: 18px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 28px; color: #fff;
            margin-bottom: 12px;
            box-shadow: 0 4px 14px rgba(102,126,234,0.35);
        }
        .logo h1 { font-size: 24px; color: #1a1d2e; font-weight: 700; }
        .logo p { font-size: 13px; color: #6b7280; margin-top: 4px; }
        .error-msg {
            background: #fef2f2; color: #ef4444;
            padding: 10px 16px; border-radius: 12px;
            font-size: 13px; margin-bottom: 16px;
            border: 1px solid #fecaca;
        }
        .form-group { margin-bottom: 16px; }
        .form-group label {
            display: block; font-size: 13px; font-weight: 600;
            color: #374151; margin-bottom: 6px;
        }
        .form-group input {
            width: 100%; padding: 12px 16px;
            border: 1.5px solid rgba(0,0,0,0.06);
            border-radius: 14px; font-size: 14px;
            background: #f8f9fc; color: #1a1d2e;
            transition: all 0.3s ease; font-family: inherit;
        }
        .form-group input:focus {
            outline: none; border-color: #667eea;
            box-shadow: 0 0 0 4px rgba(102,126,234,0.15);
            background: #fff;
        }
        .submit-btn {
            width: 100%; padding: 13px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: #fff; border: none; border-radius: 14px;
            font-size: 15px; font-weight: 600;
            cursor: pointer; transition: all 0.25s ease;
            box-shadow: 0 4px 14px rgba(102,126,234,0.35);
            margin-top: 8px; font-family: inherit;
        }
        .submit-btn:hover { transform: scale(1.02); box-shadow: 0 6px 22px rgba(102,126,234,0.5); }
        .submit-btn:active { transform: scale(0.98); }
        .links { text-align: center; margin-top: 20px; }
        .links a { color: #667eea; text-decoration: none; font-size: 13px; font-weight: 500; }
        .links a:hover { text-decoration: underline; }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="logo">
            <a href="/admin/" class="logo-icon" style="text-decoration:none;cursor:pointer;display:inline-flex;">💬</a>
            <h1>XGChat</h1>
            <p>登录你的账户开始聊天</p>
        </div>
        <?php if ($error): ?>
        <div class="error-msg"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <form method="POST" action="">
            <div class="form-group">
                <label>用户名</label>
                <input type="text" name="username" placeholder="输入用户名" required autofocus value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>">
            </div>
            <div class="form-group">
                <label>密码</label>
                <input type="password" name="password" placeholder="输入密码" required>
            </div>
            <button type="submit" class="submit-btn">登 录</button>
        </form>
        <div class="links">
            <p>还没有账户？<a href="register.php">立即注册</a></p>
        </div>
    </div>
</body>
</html>
