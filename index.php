<?php
require_once __DIR__ . '/auth.php';
if (!isLoggedIn()) { header('Location: login.php'); exit; }

$currentUser = getCurrentUser();
if (!$currentUser) { header('Location: login.php'); exit; }
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>XGChat - Modern Messaging</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body data-theme="<?php echo htmlspecialchars($currentUser['theme'] ?? 'light'); ?>">

    <div class="chat-app" id="chatApp">
        <!-- ===== 侧边栏 ===== -->
        <aside class="sidebar" id="sidebar">
            <!-- 头部用户信息 -->
            <div class="sidebar-header">
                <div class="user-info">
                    <div class="avatar-wrapper">
                        <div class="avatar" style="background:<?php echo htmlspecialchars($currentUser['avatar_color']); ?>;">
                            <?php echo htmlspecialchars($currentUser['avatar_letter']); ?>
                        </div>
                        <span class="online-dot"></span>
                    </div>
                    <div class="user-details">
                        <span class="user-name"><?php echo htmlspecialchars($currentUser['nickname']); ?></span>
                        <span class="user-status">● Online</span>
                    </div>
                </div>
                <div class="header-actions">
                    <button class="icon-btn theme-toggle" id="themeToggle" title="切换主题" aria-label="切换主题">
                        <svg class="sun-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/>
                        </svg>
                        <svg class="moon-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/>
                        </svg>
                    </button>
                    <button class="icon-btn" id="newChatBtn" title="新对话" aria-label="新对话">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 5v14M5 12h14"/>
                        </svg>
                    </button>
                    <button class="icon-btn" id="logoutBtn" title="退出登录" aria-label="退出登录">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- 搜索栏 -->
            <div class="search-wrapper">
                <div class="search-bar">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                    </svg>
                    <input type="text" id="searchInput" placeholder="搜索对话或联系人..." autocomplete="off">
                </div>
            </div>

            <!-- 筛选标签 -->
            <div class="filter-tabs">
                <span class="filter-tab active" data-filter="all">全部</span>
                <span class="filter-tab" data-filter="unread">未读</span>
                <span class="filter-tab" data-filter="pinned">置顶</span>
                <span class="filter-tab" data-filter="groups">群组</span>
                <span class="filter-tab" data-filter="friends">好友</span>
            </div>

            <!-- 联系人列表（由 JS 动态填充） -->
            <div class="contacts-list" id="contactsList">
                <div class="contacts-loading" style="text-align:center;padding:40px;color:var(--text-tertiary);">
                    加载中...
                </div>
            </div>
        </aside>

        <!-- ===== 主聊天区域 ===== -->
        <main class="chat-main" id="chatMain">
            <!-- 聊天头部 -->
            <div class="chat-header">
                <button class="back-btn" id="backBtn" aria-label="返回">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="15 18 9 12 15 6"/>
                    </svg>
                </button>
                <div class="avatar-wrapper">
                    <div class="avatar avatar-sm" id="chatAvatar" style="background:linear-gradient(135deg, #667eea, #764ba2);">
                        ?
                    </div>
                </div>
                <div class="chat-header-info">
                    <div class="chat-header-name" id="chatHeaderName">选择对话</div>
                    <div class="chat-header-status" id="chatHeaderStatus"></div>
                </div>
                <div class="header-actions-right">
                    <button class="icon-btn" id="chatSettingsBtn" title="对话设置" aria-label="对话设置">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- 消息区域 -->
            <div class="messages-area" id="messagesArea">
                <div class="empty-state">
                    <div class="empty-icon">💬</div>
                    <h3>开始对话</h3>
                    <p>选择一个联系人或发送第一条消息来开始愉快的交流</p>
                </div>
            </div>

            <!-- 输入区域 -->
            <div class="input-area">
                <button class="attach-btn" title="添加附件" aria-label="添加附件">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"/>
                    </svg>
                </button>
                <div class="input-wrapper">
                    <textarea id="messageInput" rows="1" placeholder="输入消息..." autocomplete="off"></textarea>
                    <button class="emoji-btn" title="表情" aria-label="表情">😊</button>
                </div>
                <button class="send-btn" id="sendBtn" title="发送消息" aria-label="发送消息">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/>
                    </svg>
                </button>
            </div>
        </main>
    </div>

<!-- 新对话搜索弹窗 -->
<div class="modal-overlay" id="newChatModal" style="display:none;">
    <div class="modal-box">
        <div class="modal-header">
            <h3>新对话</h3>
            <button class="modal-close" id="modalClose">&times;</button>
        </div>
        <input type="text" id="userSearchInput" placeholder="搜索用户名或昵称..." autocomplete="off">
        <div id="userSearchResults" class="search-results"></div>
    </div>
</div>

<!-- PHP 数据传递给 JS -->
<script>
    window.__CHAT_DATA__ = {
        currentUserId: <?php echo json_encode((int)$currentUser['id']); ?>,
        currentUsername: <?php echo json_encode($currentUser['username']); ?>,
        currentNickname: <?php echo json_encode($currentUser['nickname']); ?>
    };
</script>
<script src="app.js"></script>
</body>
