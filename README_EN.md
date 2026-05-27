# 💬 XGChat

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.0+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/SQLite-3-003B57?style=flat-square&logo=sqlite&logoColor=white" alt="SQLite">
  <img src="https://img.shields.io/badge/License-MIT-blue?style=flat-square" alt="License">
</p>

<p align="center">
  <a href="README.md">中文</a> | <strong>English</strong>
</p>

---

A lightweight web chat system built with PHP + SQLite. Supports private messaging, group chats, friend system, and file sharing. No MySQL required — just drop and go.

## ✨ Features

### User Side
- 🚀 **One-click deploy** — copy files and run
- 💬 **Real-time chat** — private & group messaging, 3s polling
- 👥 **Friend system** — search users, send/accept friend requests
- 📎 **File sharing** — images, videos, audio, documents
- 😊 **Emoji picker** — built-in emoji selection
- 📌 **Pin conversations** — long press/right-click to pin
- 🔒 **Hide conversations** — hidden chats reappear on new messages
- 🌙 **Dark mode** — one-click theme toggle

### Admin Panel
- 📊 **Statistics** — user count, message count, conversation count
- 👥 **User management** — view all users, delete accounts, reset passwords
- 🔑 **Security** — set admin password on first use

## 📁 Project Structure

```
xgchat/
├── index.php              # Main page (chat interface)
├── login.php              # Login page
├── register.php           # Registration page
├── api.php                # RESTful API endpoints
├── app.js                 # Frontend logic
├── styles.css             # Stylesheet
├── auth.php               # Authentication & session management
├── db.php                 # Database init & migrations
├── config.php             # Configuration
├── download.php           # Authenticated file download
├── cleanup.php            # Auto-cleanup expired files
├── admin/
│   ├── index.php          # Admin login (first-time setup)
│   └── dashboard.php      # Admin dashboard
└── data/
    ├── .htaccess          # Directory protection
    ├── chat.db            # SQLite database (auto-generated)
    └── sessions/          # PHP sessions (auto-generated)
```

## 🚀 Quick Start

### Requirements

| Dependency | Minimum Version |
|-----------|----------------|
| PHP | 8.0+ (PDO SQLite, Session) |
| Web Server | Nginx / Apache / PHP built-in |

### Local Development

```bash
cd xgchat
php -S 0.0.0.0:8080
# Open http://localhost:8080
```

### Deploy to Server

```bash
# Upload files
scp -r xgchat/ user@server:/var/www/xgchat/

# Set permissions
chown -R www-data:www-data /var/www/xgchat/
chmod -R 755 /var/www/xgchat/
chmod -R 777 /var/www/xgchat/data/

# PHP session config
echo "session.save_path = /var/www/xgchat/data/sessions" > /etc/php/8.x/fpm/conf.d/session.ini
systemctl restart php8.x-fpm
```

### Nginx Configuration

```nginx
server {
    listen 443 ssl http2;
    server_name chat.example.com;
    root /var/www/xgchat;
    index index.php;

    location ~ ^/(config\.php|data/) {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 📖 How to Use

1. Visit the site → Register → Login
2. Click **+** (top right) → **Add Friend** → Search username
3. Other user: **+** → **Friend Requests** → Accept
4. Once friends, start chatting or create groups
5. Click 💬 icon (top left) → Admin panel (set password on first use)

## 🔒 Security

| Feature | Description |
|---------|-------------|
| **bcrypt password hashing** | Irreversible password storage |
| **Session authentication** | Server-side session management |
| **Hidden conversations** | Delete = hide, reappears on new messages |
| **Authenticated file downloads** | Verify conversation membership |
| **Auto cleanup** | Expired uploads automatically deleted |

## 🤝 Contributing

1. Fork → Create branch → Commit → Push → Pull Request

## 📄 License

[MIT License](LICENSE)

---

<p align="center">If you find this helpful, give it a ⭐ Star!</p>
