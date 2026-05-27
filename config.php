<?php
// XGChat 配置文件
define('DB_PATH', __DIR__ . '/data/chat.db');
define('UPLOAD_DIR', __DIR__ . '/uploads/');
define('UPLOAD_URL', '/uploads/');
define('MAX_FILE_SIZE', 0); // 0 = 不限制
define('SITE_NAME', 'XGChat');
define('SESSION_LIFETIME', 86400 * 7); // 7天

// 确保data目录存在
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0755, true);
}

// 自动清理：1%概率触发（约每100次请求清理一次）
if (rand(1, 100) === 1 && file_exists(__DIR__ . '/cleanup.php')) {
    @include __DIR__ . '/cleanup.php';
}
