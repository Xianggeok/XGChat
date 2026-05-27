<?php
// 文件自动清理 - 删除超过1天的上传文件
// 可通过 cron 运行: php /path/to/cleanup.php
// 或每次访问时触发（轻量级检查）

require_once __DIR__ . '/config.php';

$maxAge = 86400; // 1天 = 86400秒
$uploadDir = UPLOAD_DIR;
$deleted = 0;

if (!is_dir($uploadDir)) {
    exit(0);
}

function cleanDir($dir, $maxAge, &$deleted) {
    $items = scandir($dir);
    if (!$items) return;

    foreach ($items as $item) {
        if ($item === '.' || $item === '..' || $item === '.htaccess') continue;

        $path = $dir . '/' . $item;

        if (is_dir($path)) {
            cleanDir($path, $maxAge, $deleted);
            // 删除空目录
            if (count(scandir($path)) <= 2) {
                rmdir($path);
            }
        } elseif (is_file($path)) {
            if (time() - filemtime($path) > $maxAge) {
                // 删除数据库中的引用
                $relativePath = str_replace($uploadDir, '', $path);
                $relativePath = ltrim($relativePath, '/');

                try {
                    $db = new PDO('sqlite:' . DB_PATH);
                    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                    $stmt = $db->prepare('UPDATE messages SET file_path = NULL, file_name = NULL WHERE file_path = ?');
                    $stmt->execute([$relativePath]);
                } catch (Exception $e) {
                    // 忽略数据库错误
                }

                unlink($path);
                $deleted++;
            }
        }
    }
}

cleanDir($uploadDir, $maxAge, $deleted);

// 如果是命令行运行，输出结果
if (php_sapi_name() === 'cli') {
    echo "Cleaned up $deleted files\n";
}
