<?php
// 数据库连接和初始化
require_once __DIR__ . '/config.php';

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $db = new PDO('sqlite:' . DB_PATH);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec('PRAGMA foreign_keys=ON');
    }
    return $db;
}

function initDB(): void {
    $db = getDB();
    $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            nickname TEXT NOT NULL,
            avatar_letter TEXT NOT NULL,
            avatar_color TEXT NOT NULL DEFAULT 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            bio TEXT DEFAULT '',
            status TEXT DEFAULT 'online',
            theme TEXT DEFAULT 'light',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS conversations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            type TEXT NOT NULL DEFAULT 'direct',
            name TEXT DEFAULT NULL,
            avatar_letter TEXT DEFAULT NULL,
            avatar_color TEXT DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        );

        CREATE TABLE IF NOT EXISTS conversation_members (
            conversation_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            pinned INTEGER DEFAULT 0,
            last_read_at DATETIME DEFAULT NULL,
            muted INTEGER DEFAULT 0,
            PRIMARY KEY (conversation_id, user_id),
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            content TEXT NOT NULL,
            type TEXT DEFAULT 'text',
            file_path TEXT DEFAULT NULL,
            file_name TEXT DEFAULT NULL,
            file_size INTEGER DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS contacts (
            user_id INTEGER NOT NULL,
            contact_id INTEGER NOT NULL,
            status TEXT DEFAULT 'accepted',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, contact_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (contact_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_messages_conversation ON messages(conversation_id, created_at);
        CREATE INDEX IF NOT EXISTS idx_messages_sender ON messages(sender_id);
        CREATE INDEX IF NOT EXISTS idx_conv_members_user ON conversation_members(user_id);
        CREATE INDEX IF NOT EXISTS idx_contacts_user ON contacts(user_id);

        CREATE TABLE IF NOT EXISTS friends (
            user_id INTEGER NOT NULL,
            friend_id INTEGER NOT NULL,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, friend_id),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (friend_id) REFERENCES users(id) ON DELETE CASCADE
        );

        CREATE INDEX IF NOT EXISTS idx_friends_user ON friends(user_id);
        CREATE INDEX IF NOT EXISTS idx_friends_friend ON friends(friend_id);

        CREATE TABLE IF NOT EXISTS group_requests (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            conversation_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            invited_by INTEGER NOT NULL,
            status TEXT DEFAULT 'pending',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (conversation_id) REFERENCES conversations(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            FOREIGN KEY (invited_by) REFERENCES users(id) ON DELETE CASCADE
        );
    ");
}

// 自动初始化
initDB();

// 数据库迁移 - 添加新列
function migrateDB(): void {
    $db = getDB();
    
    // conversations 表
    $cols = $db->query('PRAGMA table_info(conversations)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('group_avatar_url', $cols)) {
        $db->exec('ALTER TABLE conversations ADD COLUMN group_avatar_url TEXT DEFAULT NULL');
    }
    
    // users 表
    $cols = $db->query('PRAGMA table_info(users)')->fetchAll(PDO::FETCH_COLUMN, 1);
    if (!in_array('avatar_url', $cols)) {
        $db->exec('ALTER TABLE users ADD COLUMN avatar_url TEXT DEFAULT NULL');
    }
    
    // messages 表
    $cols = $db->query('PRAGMA table_info(messages)')->fetchAll(PDO::FETCH_COLUMN, 1);
    // conversation_members 表
        $cmCols = $db->query('PRAGMA table_info(conversation_members)')->fetchAll(PDO::FETCH_COLUMN, 1);
        if (!in_array('hidden', $cmCols)) {
            $db->exec('ALTER TABLE conversation_members ADD COLUMN hidden INTEGER DEFAULT 0');
        }
    
        // messages 表
        if (!in_array('file_path', $cols)) {
        $db->exec('ALTER TABLE messages ADD COLUMN file_path TEXT DEFAULT NULL');
        $db->exec('ALTER TABLE messages ADD COLUMN file_name TEXT DEFAULT NULL');
        $db->exec('ALTER TABLE messages ADD COLUMN file_size INTEGER DEFAULT 0');
    }
}
migrateDB();
