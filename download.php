<?php
// 文件下载 - 需要登录且是对话成员
require_once __DIR__ . '/auth.php';
if (!isLoggedIn()) { http_response_code(403); exit('Forbidden'); }

$path = $_GET['path'] ?? '';
if (empty($path) || str_contains($path, '..') || str_contains($path, '\\')) {
    http_response_code(400);
    exit('Bad request');
}

$fullPath = UPLOAD_DIR . $path;
if (!file_exists($fullPath)) {
    http_response_code(404);
    exit('Not found');
}

// 验证用户有权访问此文件（检查消息所属对话）
$db = getDB();
$currentUser = getCurrentUser();
$stmt = $db->prepare("
    SELECT 1 FROM messages m
    JOIN conversation_members cm ON cm.conversation_id = m.conversation_id
    WHERE m.file_path = ? AND cm.user_id = ?
");
$stmt->execute([$path, $currentUser['id']]);
if (!$stmt->fetch()) {
    http_response_code(403);
    exit('Forbidden');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $fullPath);
finfo_close($finfo);

header("Content-Type: $mime");
header('Content-Length: ' . filesize($fullPath));
header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
readfile($fullPath);
