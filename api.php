<?php
// XGChat API 接口
require_once __DIR__ . '/auth.php';
header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => '未登录']);
    exit;
}

$currentUser = getCurrentUser();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$db = getDB();

switch ($action) {

    // ==========================================
    // 获取联系人列表
    // ==========================================
    case 'contacts':
        $filter = $_GET['filter'] ?? 'all';
        $search = trim($_GET['search'] ?? '');

        // 查找用户参与的所有对话
        $sql = "
            SELECT 
                c.id as conversation_id,
                c.type,
                c.name as group_name,
                c.avatar_letter as group_avatar,
                c.avatar_color as group_color,
                c.group_avatar_url,
                cm.pinned,
                cm.muted,
                cm.last_read_at,
                (
                    SELECT COUNT(*) FROM messages m 
                    WHERE m.conversation_id = c.id 
                    AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01')
                    AND m.sender_id != :uid
                ) as unread_count
            FROM conversation_members cm
            JOIN conversations c ON c.id = cm.conversation_id
            WHERE cm.user_id = :uid AND (cm.hidden = 0 OR 
                (SELECT COUNT(*) FROM messages m2 WHERE m2.conversation_id = c.id 
                 AND m2.created_at > COALESCE(cm.last_read_at, '1970-01-01')
                 AND m2.sender_id != :uid2) > 0)
            ORDER BY cm.pinned DESC, 
                (SELECT MAX(created_at) FROM messages WHERE conversation_id = c.id) DESC
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':uid' => $currentUser['id'], ':uid2' => $currentUser['id']]);
        $conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $contacts = [];
        foreach ($conversations as $conv) {
            $groupAvatarUrl = null;
            // 获取对话的对方用户（私聊）或群信息
            if ($conv['type'] === 'direct') {
                $stmt2 = $db->prepare("
                    SELECT u.id, u.nickname, u.avatar_letter, u.avatar_color, u.avatar_url, u.status, u.bio
                    FROM conversation_members cm
                    JOIN users u ON u.id = cm.user_id
                    WHERE cm.conversation_id = ? AND cm.user_id != ?
                ");
                $stmt2->execute([$conv['conversation_id'], $currentUser['id']]);
                $other = $stmt2->fetch(PDO::FETCH_ASSOC);
                if (!$other) continue;

                $name = $other['nickname'];
                $avatar = $other['avatar_letter'];
                $color = $other['avatar_color'];
                $online = ($other['status'] === 'online');
                $isGroup = false;
                $memberCount = 0;
            } else {
                $name = $conv['group_name'] ?? '群聊';
                $avatar = $conv['group_avatar'] ?? '群';
                $color = $conv['group_color'] ?? randomGradient();
                $groupAvatarUrl = $conv['group_avatar_url'] ?? null;
                $online = true;
                $isGroup = true;
                $stmt2 = $db->prepare('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?');
                $stmt2->execute([$conv['conversation_id']]);
                $memberCount = $stmt2->fetchColumn();
            }

            // 获取最后一条消息
            $stmt3 = $db->prepare("
                SELECT content, sender_id, created_at FROM messages 
                WHERE conversation_id = ? ORDER BY created_at DESC LIMIT 1
            ");
            $stmt3->execute([$conv['conversation_id']]);
            $lastMsg = $stmt3->fetch(PDO::FETCH_ASSOC);

            $lastMessage = '';
            $timeStr = '';
            if ($lastMsg) {
                $lastMessage = $lastMsg['content'];
                $timeStr = formatTime($lastMsg['created_at']);
                if ($isGroup && $lastMsg['sender_id'] != $currentUser['id']) {
                    $stmt4 = $db->prepare('SELECT nickname FROM users WHERE id = ?');
                    $stmt4->execute([$lastMsg['sender_id']]);
                    $senderName = $stmt4->fetchColumn();
                    $lastMessage = $senderName . ': ' . $lastMessage;
                }
            }

            // 搜索过滤
            if ($search && stripos($name, $search) === false) continue;

            // 分类过滤
            if ($filter === 'unread' && $conv['unread_count'] == 0) continue;
            if ($filter === 'pinned' && !$conv['pinned']) continue;
            if ($filter === 'groups' && !$isGroup) continue;

            $contacts[] = [
                'id' => $conv['conversation_id'],
                'user_id' => $other['id'] ?? null,
                'name' => $name,
                'avatar' => $avatar,
                'avatar_color' => $color,
                'avatar_url' => $other['avatar_url'] ?? null,
                'last_message' => $lastMessage,
                'time' => $timeStr,
                'unread' => (int)$conv['unread_count'],
                'online' => $online,
                'is_typing' => false,
                'pinned' => (bool)$conv['pinned'],
                'is_group' => $isGroup,
                'member_count' => $memberCount,
                'group_avatar_url' => $groupAvatarUrl ?? null,
            ];
        }

        echo json_encode(['contacts' => $contacts]);
        break;

    // ==========================================
    // 获取消息列表
    // ==========================================
    case 'messages':
        $convId = (int)($_GET['conversation_id'] ?? 0);
        $before = $_GET['before'] ?? null; // 分页用
        $limit = 50;

        // 验证用户是否在此对话中
        $stmt = $db->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => '无权访问此对话', 'messages' => []]);
            break;
        }

        // 更新已读时间并取消隐藏
        $stmt = $db->prepare('UPDATE conversation_members SET last_read_at = CURRENT_TIMESTAMP, hidden = 0 WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);

        $sql = "SELECT m.id, m.sender_id, m.content, m.type, m.file_path, m.file_name, m.file_size, m.created_at, u.nickname as sender_name, u.avatar_letter, u.avatar_color, u.avatar_url
                FROM messages m JOIN users u ON u.id = m.sender_id
                WHERE m.conversation_id = :conv_id";
        $params = [':conv_id' => $convId];

        if ($before) {
            $sql .= ' AND m.created_at < :before';
            $params[':before'] = $before;
        }

        $sql .= ' ORDER BY m.created_at ASC LIMIT ' . $limit;
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $messages = [];
        foreach ($rows as $row) {
            $ts = utcToTimestamp($row['created_at']);
            $date = date('Y-m-d', $ts);
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));

            if ($date === $today) $dateLabel = 'Today';
            elseif ($date === $yesterday) $dateLabel = 'Yesterday';
            else $dateLabel = date('n月j日', $ts);

            $msg = [
                'id' => $row['id'],
                'from' => (int)$row['sender_id'],
                'text' => $row['content'],
                'type' => $row['type'] ?? 'text',
                'time' => date('H:i', $ts),
                'date' => $dateLabel,
                'created_at' => $row['created_at'],
                'sender_name' => $row['sender_name'] ?? '',
                'sender_avatar' => $row['avatar_letter'] ?? '',
                'sender_avatar_color' => $row['avatar_color'] ?? '',
                'sender_avatar_url' => $row['avatar_url'] ?? null,
            ];
            if (!empty($row['file_path'])) {
                $msg['file_url'] = UPLOAD_URL . $row['file_path'];
                $msg['file_name'] = $row['file_name'] ?? '';
                $msg['file_size'] = (int)($row['file_size'] ?? 0);
            }
            $messages[] = $msg;
        }

        echo json_encode(['messages' => $messages]);
        break;

    // ==========================================
    // 发送消息
    // ==========================================
    case 'send':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $convId = (int)($input['conversation_id'] ?? 0);
        $content = trim($input['content'] ?? '');

        if (empty($content)) {
            echo json_encode(['error' => '消息不能为空']);
            break;
        }

        // 验证用户是否在此对话中
        $stmt = $db->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => '无权发送消息']);
            break;
        }

        $stmt = $db->prepare('INSERT INTO messages (conversation_id, sender_id, content) VALUES (?, ?, ?)');
        $stmt->execute([$convId, $currentUser['id'], $content]);
        $msgId = $db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => [
                'id' => (int)$msgId,
                'from' => $currentUser['id'],
                'text' => $content,
                'time' => date('H:i'),
                'date' => 'Today',
                'created_at' => date('Y-m-d H:i:s'),
            ]
        ]);
        break;

    // ==========================================
    // 创建新对话（私聊）
    // ==========================================
    case 'create_conversation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $targetUserId = (int)($input['user_id'] ?? 0);

        if ($targetUserId == $currentUser['id']) {
            echo json_encode(['error' => '不能和自己聊天']);
            break;
        }

        // 检查目标用户是否存在
        $stmt = $db->prepare('SELECT id, nickname FROM users WHERE id = ?');
        $stmt->execute([$targetUserId]);
        $targetUser = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$targetUser) {
            echo json_encode(['error' => '用户不存在']);
            break;
        }

        // 检查是否是好友
        $stmt = $db->prepare("SELECT status FROM friends WHERE user_id = ? AND friend_id = ? AND status = 'accepted'");
        $stmt->execute([$currentUser['id'], $targetUserId]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => 'need_friend', 'message' => '需要先添加好友']);
            break;
        }

        // 检查是否已有私聊
        $stmt = $db->prepare("
            SELECT cm1.conversation_id FROM conversation_members cm1
            JOIN conversation_members cm2 ON cm1.conversation_id = cm2.conversation_id
            JOIN conversations c ON c.id = cm1.conversation_id
            WHERE cm1.user_id = ? AND cm2.user_id = ? AND c.type = 'direct'
        ");
        $stmt->execute([$currentUser['id'], $targetUserId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            echo json_encode(['conversation_id' => (int)$existing['conversation_id']]);
            break;
        }

        // 创建新对话
        $db->exec("INSERT INTO conversations (type) VALUES ('direct')");
        $convId = $db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
        $stmt->execute([$convId, $currentUser['id']]);
        $stmt->execute([$convId, $targetUserId]);

        // 自动加为联系人
        $stmt = $db->prepare('INSERT OR IGNORE INTO contacts (user_id, contact_id) VALUES (?, ?)');
        $stmt->execute([$currentUser['id'], $targetUserId]);
        $stmt->execute([$targetUserId, $currentUser['id']]);

        echo json_encode(['conversation_id' => (int)$convId]);
        break;

    // ==========================================
    // 搜索用户（添加联系人用）
    // ==========================================
    case 'search_users':
        $query = trim($_GET['q'] ?? '');
        if (mb_strlen($query) < 1) {
            echo json_encode(['users' => []]);
            break;
        }

        $stmt = $db->prepare("
            SELECT id, username, nickname, avatar_letter, avatar_color, avatar_url, status 
            FROM users 
            WHERE id != :uid AND (username LIKE :q1 OR nickname LIKE :q2)
            LIMIT 20
        ");
        $stmt->execute([':uid' => $currentUser['id'], ':q1' => "%$query%", ':q2' => "%$query%"]);
        $users = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $users[] = [
                'id' => (int)$row['id'],
                'username' => $row['username'],
                'nickname' => $row['nickname'],
                'avatar' => $row['avatar_letter'],
                'avatar_color' => $row['avatar_color'],
                'online' => ($row['status'] === 'online'),
            ];
        }
        echo json_encode(['users' => $users]);
        break;

    // ==========================================
    // 置顶/取消置顶
    // ==========================================
    case 'toggle_pin':
        $input = json_decode(file_get_contents('php://input'), true);
        $convId = (int)($input['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
        $stmt = $db->prepare('UPDATE conversation_members SET pinned = NOT pinned WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);
        echo json_encode(['success' => true]);
        break;

    // ==========================================
    // 更新用户设置
    // ==========================================
    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $nickname = trim($input['nickname'] ?? '');
        $bio = trim($input['bio'] ?? '');
        $theme = $input['theme'] ?? '';

        if ($nickname) {
            $stmt = $db->prepare('UPDATE users SET nickname = ?, avatar_letter = ? WHERE id = ?');
            $stmt->execute([$nickname, mb_strtoupper(mb_substr($nickname, 0, 2)), $currentUser['id']]);
        }
        if ($bio !== '') {
            $stmt = $db->prepare('UPDATE users SET bio = ? WHERE id = ?');
            $stmt->execute([$bio, $currentUser['id']]);
        }
        if ($theme && in_array($theme, ['light', 'dark'])) {
            $stmt = $db->prepare('UPDATE users SET theme = ? WHERE id = ?');
            $stmt->execute([$theme, $currentUser['id']]);
        }
        echo json_encode(['success' => true]);
        break;

    // ==========================================
    // 获取当前用户信息
    // ==========================================
    case 'me':
        // 获取完整用户信息（包括 avatar_url）
        $stmt = $db->prepare('SELECT id, username, nickname, avatar_letter, avatar_color, avatar_url, bio, status, theme FROM users WHERE id = ?');
        $stmt->execute([$currentUser['id']]);
        $fullUser = $stmt->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['user' => $fullUser]);
        break;

    // ==========================================
    // 获取未读消息总数
    // ==========================================
    case 'unread_total':
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM messages m
            JOIN conversation_members cm ON cm.conversation_id = m.conversation_id
            WHERE cm.user_id = :uid 
            AND m.sender_id != :uid 
            AND m.created_at > COALESCE(cm.last_read_at, '1970-01-01')
        ");
        $stmt->execute([':uid' => $currentUser['id']]);
        echo json_encode(['unread' => (int)$stmt->fetchColumn()]);
        break;

    // ==========================================
    // 退出登录
    // ==========================================
    case 'logout':
        logoutUser();
        echo json_encode(['success' => true]);
        break;


    // ==========================================
    // 创建群聊
    // ==========================================
    case 'create_group':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $groupName = trim($input['name'] ?? '');
        $memberIds = $input['member_ids'] ?? [];

        if (empty($groupName)) {
            echo json_encode(['error' => '群名不能为空']);
            break;
        }

        if (count($memberIds) < 1) {
            echo json_encode(['error' => '至少选择一个成员']);
            break;
        }

        // 去重，确保包含当前用户
        $memberIds = array_unique(array_merge([$currentUser['id']], array_map('intval', $memberIds)));

        $db->exec("INSERT INTO conversations (type, name, avatar_letter, avatar_color) VALUES ('group', " . $db->quote($groupName) . ", " . $db->quote(mb_strtoupper(mb_substr($groupName, 0, 1))) . ", " . $db->quote(randomGradient()) . ")");
        $convId = $db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
        foreach ($memberIds as $uid) {
            $stmt->execute([$convId, $uid]);
        }

        echo json_encode(['conversation_id' => (int)$convId, 'success' => true]);
        break;

    // ==========================================
    // 群聊添加成员
    // ==========================================
    case 'add_member':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $convId = (int)($input['conversation_id'] ?? 0);
        $userId = (int)($input['user_id'] ?? 0);

        // 验证当前用户是群成员
        $stmt = $db->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => '你不是此群成员']);
            break;
        }

        // 检查目标用户是否已在群中
        $stmt = $db->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $userId]);
        if ($stmt->fetch()) {
            echo json_encode(['error' => '该用户已在群中']);
            break;
        }

        $stmt = $db->prepare('INSERT INTO conversation_members (conversation_id, user_id) VALUES (?, ?)');
        $stmt->execute([$convId, $userId]);

        echo json_encode(['success' => true]);
        break;

    // ==========================================
    // 上传文件
    // ==========================================
    case 'upload_file':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $convId = (int)($_POST['conversation_id'] ?? 0);

        // 验证用户是否在此对话中
        $stmt = $db->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => '无权发送文件']);
            break;
        }

        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => '文件上传失败']);
            break;
        }

        $file = $_FILES['file'];
        // 文件大小不限制

        // 创建上传目录
        $uploadDir = UPLOAD_DIR . date('Y/m');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        // 生成唯一文件名
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
        $filePath = $uploadDir . '/' . $safeName;
        $relativePath = date('Y/m') . '/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            echo json_encode(['error' => '保存文件失败']);
            break;
        }

        // 判断消息类型
        $mimeType = $file['type'];
        if (str_starts_with($mimeType, 'image/')) {
            $msgType = 'image';
        } elseif (str_starts_with($mimeType, 'video/')) {
            $msgType = 'video';
        } elseif (str_starts_with($mimeType, 'audio/')) {
            $msgType = 'audio';
        } else {
            $msgType = 'file';
        }

        // 插入消息
        $stmt = $db->prepare('INSERT INTO messages (conversation_id, sender_id, content, type, file_path, file_name, file_size) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$convId, $currentUser['id'], $file['name'], $msgType, $relativePath, $file['name'], $file['size']]);
        $msgId = $db->lastInsertId();

        echo json_encode([
            'success' => true,
            'message' => [
                'id' => (int)$msgId,
                'from' => $currentUser['id'],
                'text' => $file['name'],
                'type' => $msgType,
                'file_url' => UPLOAD_URL . $relativePath,
                'file_name' => $file['name'],
                'file_size' => $file['size'],
                'time' => date('H:i'),
                'date' => 'Today',
            ]
        ]);
        break;

    // ==========================================
    // 修改密码
    // ==========================================
    case 'change_password':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $oldPassword = $input['old_password'] ?? '';
        $newPassword = $input['new_password'] ?? '';

        if (strlen($newPassword) < 6) {
            echo json_encode(['error' => '新密码至少6个字符']);
            break;
        }

        // 验证旧密码
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$currentUser['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!password_verify($oldPassword, $user['password_hash'])) {
            echo json_encode(['error' => '旧密码错误']);
            break;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $stmt->execute([$newHash, $currentUser['id']]);

        echo json_encode(['success' => true, 'message' => '密码已修改']);
        break;

    // ==========================================
    // 获取群成员列表
    // ==========================================
    case 'group_members':
        $convId = (int)($_GET['conversation_id'] ?? 0);

        $stmt = $db->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => '无权查看']);
            break;
        }

        $stmt = $db->prepare("
            SELECT u.id, u.nickname, u.avatar_letter, u.avatar_color, u.avatar_url, u.status
            FROM conversation_members cm
            JOIN users u ON u.id = cm.user_id
            WHERE cm.conversation_id = ?
            ORDER BY u.nickname
        ");
        $stmt->execute([$convId]);
        $members = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $members[] = [
                'id' => (int)$row['id'],
                'nickname' => $row['nickname'],
                'avatar' => $row['avatar_letter'],
                'avatar_color' => $row['avatar_color'],
                'online' => ($row['status'] === 'online'),
            ];
        }
        echo json_encode(['members' => $members]);
        break;


    // ==========================================
    // 发送好友请求
    // ==========================================
    case 'send_friend_request':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $targetId = (int)($input['user_id'] ?? 0);

        if ($targetId == $currentUser['id']) {
            echo json_encode(['error' => '不能添加自己']);
            break;
        }

        // 检查目标用户是否存在
        $stmt = $db->prepare('SELECT id, nickname FROM users WHERE id = ?');
        $stmt->execute([$targetId]);
        $target = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$target) {
            echo json_encode(['error' => '用户不存在']);
            break;
        }

        // 检查是否已经是好友
        $stmt = $db->prepare('SELECT status FROM friends WHERE user_id = ? AND friend_id = ?');
        $stmt->execute([$currentUser['id'], $targetId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($existing) {
            if ($existing['status'] === 'accepted') {
                echo json_encode(['error' => '已经是好友了']);
            } else {
                echo json_encode(['error' => '已发送过请求']);
            }
            break;
        }

        // 检查对方是否已经向我发送请求
        $stmt = $db->prepare('SELECT status FROM friends WHERE user_id = ? AND friend_id = ?');
        $stmt->execute([$targetId, $currentUser['id']]);
        $reverse = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($reverse) {
            if ($reverse['status'] === 'pending') {
                // 对方已发请求，直接接受
                $db->prepare('UPDATE friends SET status = ? WHERE user_id = ? AND friend_id = ?')
                    ->execute(['accepted', $targetId, $currentUser['id']]);
                $db->prepare('INSERT OR IGNORE INTO friends (user_id, friend_id, status) VALUES (?, ?, ?)')
                    ->execute([$currentUser['id'], $targetId, 'accepted']);
                echo json_encode(['success' => true, 'message' => '已自动成为好友']);
                break;
            }
        }

        // 发送请求
        $stmt = $db->prepare('INSERT INTO friends (user_id, friend_id, status) VALUES (?, ?, ?)');
        $stmt->execute([$currentUser['id'], $targetId, 'pending']);

        echo json_encode(['success' => true, 'message' => '好友请求已发送']);
        break;

    // ==========================================
    // 处理好友请求（接受/拒绝）
    // ==========================================
    case 'handle_friend_request':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $fromUserId = (int)($input['user_id'] ?? 0);
        $action2 = $input['action2'] ?? ''; // 'accept' or 'decline'

        if (!in_array($action2, ['accept', 'decline'])) {
            echo json_encode(['error' => '无效操作']);
            break;
        }

        // 检查请求是否存在
        $stmt = $db->prepare('SELECT status FROM friends WHERE user_id = ? AND friend_id = ?');
        $stmt->execute([$fromUserId, $currentUser['id']]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request || $request['status'] !== 'pending') {
            echo json_encode(['error' => '请求不存在或已处理']);
            break;
        }

        if ($action2 === 'accept') {
            $db->prepare('UPDATE friends SET status = ? WHERE user_id = ? AND friend_id = ?')
                ->execute(['accepted', $fromUserId, $currentUser['id']]);
            $db->prepare('INSERT OR IGNORE INTO friends (user_id, friend_id, status) VALUES (?, ?, ?)')
                ->execute([$currentUser['id'], $fromUserId, 'accepted']);
            echo json_encode(['success' => true, 'message' => '已接受好友请求']);
        } else {
            $db->prepare('DELETE FROM friends WHERE user_id = ? AND friend_id = ?')
                ->execute([$fromUserId, $currentUser['id']]);
            echo json_encode(['success' => true, 'message' => '已拒绝']);
        }
        break;

    // ==========================================
    // 好友列表
    // ==========================================
    case 'friends':
        $stmt = $db->prepare("
            SELECT u.id, u.nickname, u.username, u.avatar_letter, u.avatar_color, u.status, u.bio
            FROM friends f
            JOIN users u ON u.id = f.friend_id
            WHERE f.user_id = ? AND f.status = 'accepted'
            ORDER BY u.nickname
        ");
        $stmt->execute([$currentUser['id']]);
        $friends = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $friends[] = [
                'id' => (int)$row['id'],
                'nickname' => $row['nickname'],
                'username' => $row['username'],
                'avatar' => $row['avatar_letter'],
                'avatar_color' => $row['avatar_color'],
                'online' => ($row['status'] === 'online'),
                'bio' => $row['bio'],
            ];
        }
        echo json_encode(['friends' => $friends]);
        break;

    // ==========================================
    // 好友请求列表（收到的待处理请求）
    // ==========================================
    case 'friend_requests':
        $stmt = $db->prepare("
            SELECT u.id, u.nickname, u.username, u.avatar_letter, u.avatar_color, u.status, f.created_at
            FROM friends f
            JOIN users u ON u.id = f.user_id
            WHERE f.friend_id = ? AND f.status = 'pending'
            ORDER BY f.created_at DESC
        ");
        $stmt->execute([$currentUser['id']]);
        $requests = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $requests[] = [
                'id' => (int)$row['id'],
                'nickname' => $row['nickname'],
                'username' => $row['username'],
                'avatar' => $row['avatar_letter'],
                'avatar_color' => $row['avatar_color'],
                'online' => ($row['status'] === 'online'),
                'time' => formatTime($row['created_at']),
            ];
        }
        echo json_encode(['requests' => $requests]);
        break;

    // ==========================================
    // 删除好友
    // ==========================================
    case 'remove_friend':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $targetId = (int)($input['user_id'] ?? 0);

        $db->prepare('DELETE FROM friends WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)')
            ->execute([$currentUser['id'], $targetId, $targetId, $currentUser['id']]);

        echo json_encode(['success' => true]);
        break;

    // ==========================================
    // 查看用户资料
    // ==========================================
    case 'user_profile':
        $userId = (int)($_GET['user_id'] ?? 0);
        $stmt = $db->prepare('SELECT id, nickname, username, avatar_letter, avatar_color, avatar_url, bio, status, created_at FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            echo json_encode(['error' => '用户不存在']);
            break;
        }

        // 检查好友关系
        $stmt = $db->prepare('SELECT status FROM friends WHERE user_id = ? AND friend_id = ?');
        $stmt->execute([$currentUser['id'], $userId]);
        $friendship = $stmt->fetch(PDO::FETCH_ASSOC);
        $friendStatus = $friendship ? $friendship['status'] : 'none';

        echo json_encode([
            'user' => [
                'id' => (int)$user['id'],
                'nickname' => $user['nickname'],
                'username' => $user['username'],
                'avatar' => $user['avatar_letter'],
                'avatar_color' => $user['avatar_color'],
                'bio' => $user['bio'] ?? '',
                'online' => ($user['status'] === 'online'),
                'joined' => $user['created_at'],
            ],
            'friend_status' => $friendStatus,
        ]);
        break;

    // ==========================================
    // 群聊头像上传
    // ==========================================
    case 'upload_group_avatar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $convId = (int)($_POST['conversation_id'] ?? 0);

        // 验证是群成员
        $stmt = $db->prepare("SELECT type FROM conversations WHERE id = ?");
        $stmt->execute([$convId]);
        $conv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$conv || $conv['type'] !== 'group') {
            echo json_encode(['error' => '不是群聊']);
            break;
        }

        $stmt = $db->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => '你不是群成员']);
            break;
        }

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => '上传失败']);
            break;
        }

        $file = $_FILES['avatar'];
        if ($file['size'] > 2 * 1024 * 1024) {
            echo json_encode(['error' => '头像最大2MB']);
            break;
        }

        $uploadDir = UPLOAD_DIR . 'avatars';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $safeName = 'group_' . $convId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $filePath = $uploadDir . '/' . $safeName;
        $relativePath = 'avatars/' . $safeName;

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            echo json_encode(['error' => '保存失败']);
            break;
        }

        $db->prepare('UPDATE conversations SET group_avatar_url = ? WHERE id = ?')
            ->execute([$relativePath, $convId]);

        echo json_encode(['success' => true, 'avatar_url' => UPLOAD_URL . $relativePath]);
        break;


    // ==========================================
    // 上传个人头像
    // ==========================================
    case 'upload_avatar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => '上传失败']);
            break;
        }

        $file = $_FILES['avatar'];
        if ($file['size'] > 2 * 1024 * 1024) {
            echo json_encode(['error' => '头像最大2MB']);
            break;
        }

        $uploadDir = UPLOAD_DIR . 'avatars';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $safeName = 'user_' . $currentUser['id'] . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
        $filePath = $uploadDir . '/' . $safeName;
        $relativePath = 'avatars/' . $safeName;

        // 删除旧头像
        $stmt = $db->prepare('SELECT avatar_url FROM users WHERE id = ?');
        $stmt->execute([$currentUser['id']]);
        $oldUrl = $stmt->fetchColumn();
        if ($oldUrl && str_starts_with($oldUrl, 'avatars/')) {
            @unlink(UPLOAD_DIR . $oldUrl);
        }

        if (!move_uploaded_file($file['tmp_name'], $filePath)) {
            echo json_encode(['error' => '保存失败']);
            break;
        }

        $db->prepare('UPDATE users SET avatar_url = ? WHERE id = ?')
            ->execute([$relativePath, $currentUser['id']]);

        echo json_encode(['success' => true, 'avatar_url' => UPLOAD_URL . $relativePath]);
        break;


    // ==========================================
    // 删除对话
    // ==========================================
    case 'delete_conversation':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $convId = (int)($input['conversation_id'] ?? 0);

        // 验证用户在此对话中
        $stmt = $db->prepare('SELECT 1 FROM conversation_members WHERE conversation_id = ? AND user_id = ?');
        $stmt->execute([$convId, $currentUser['id']]);
        if (!$stmt->fetch()) {
            echo json_encode(['error' => '无权操作']);
            break;
        }

        // 隐藏对话（不退出，仍可收到消息）
        // 更新 last_read_at 为当前时间，这样只有隐藏后的新消息才会恢复对话
        $db->prepare("UPDATE conversation_members SET hidden = 1, last_read_at = datetime('now', '+1 second') WHERE conversation_id = ? AND user_id = ?")
            ->execute([$convId, $currentUser['id']]);

        echo json_encode(['success' => true]);
        break;

    // ==========================================
    // 删除账号
    // ==========================================
    case 'delete_account':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            http_response_code(405);
            echo json_encode(['error' => '方法不允许']);
            break;
        }

        $input = json_decode(file_get_contents('php://input'), true);
        $password = $input['password'] ?? '';

        if (empty($password)) {
            echo json_encode(['error' => '请输入密码']);
            break;
        }

        // 验证密码
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
        $stmt->execute([$currentUser['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            echo json_encode(['error' => '密码错误']);
            break;
        }

        $uid = $currentUser['id'];

        // 删除用户的所有消息
        $db->prepare('DELETE FROM messages WHERE sender_id = ?')->execute([$uid]);
        // 删除好友关系
        $db->prepare('DELETE FROM friends WHERE user_id = ? OR friend_id = ?')->execute([$uid, $uid]);
        // 删除群聊请求
        $db->prepare('DELETE FROM group_requests WHERE user_id = ? OR invited_by = ?')->execute([$uid, $uid]);
        // 从对话中移除
        $db->prepare('DELETE FROM conversation_members WHERE user_id = ?')->execute([$uid]);
        // 删除用户创建的私聊对话
        $db->prepare("DELETE FROM conversations WHERE type = 'direct' AND id NOT IN (SELECT conversation_id FROM conversation_members)")->execute();
        // 删除用户
        $db->prepare('DELETE FROM users WHERE id = ?')->execute([$uid]);

        // 退出登录
        logoutUser();

        echo json_encode(['success' => true, 'message' => '账号已删除']);
        break;

    default:
        http_response_code(400);
        echo json_encode(['error' => '未知操作: ' . $action]);
}

// ==========================================
// 辅助函数
// ==========================================
// UTC时间转本地时间戳（SQLite CURRENT_TIMESTAMP 存的是UTC）
function utcToTimestamp(string $utcDatetime): int {
    return (new DateTime($utcDatetime, new DateTimeZone('UTC')))->getTimestamp();
}

function formatTime(string $datetime): string {
    $ts = utcToTimestamp($datetime);
    $now = time();
    $diff = $now - $ts;

    if ($diff < 60) return '刚刚';
    if ($diff < 3600) return floor($diff / 60) . '分钟前';
    if ($diff < 86400 && date('Y-m-d', $ts) === date('Y-m-d')) return date('H:i', $ts);
    if ($diff < 172800 && date('Y-m-d', $ts) === date('Y-m-d', strtotime('-1 day'))) return '昨天';
    if ($diff < 604800) return ['周日','周一','周二','周三','周四','周五','周六'][date('w', $ts)];
    return date('m/d', $ts);
}
