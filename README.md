# 💬 XGChat

<p align="center">
  <img src="https://img.shields.io/badge/PHP-8.0+-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/SQLite-3-003B57?style=flat-square&logo=sqlite&logoColor=white" alt="SQLite">
  <img src="https://img.shields.io/badge/License-MIT-blue?style=flat-square" alt="License">
</p>

<p align="center">
  <strong>中文</strong> | <a href="README_EN.md">English</a>
</p>

---

一个基于 PHP + SQLite 的轻量级网页聊天系统，支持私聊、群聊、好友系统、文件发送。无需 MySQL，开箱即用。

## ✨ 功能特性

### 用户端
- 🚀 **一键部署** — 复制文件即可运行
- 💬 **实时聊天** — 私聊 + 群聊，3 秒轮询刷新
- 👥 **好友系统** — 搜索用户、发送好友请求、接受/拒绝
- 📎 **文件发送** — 支持图片、视频、音频、文档
- 😊 **表情选择** — 内置 Emoji 选择器
- 📌 **置顶对话** — 长按/右键置顶重要对话
- 🔒 **删除对话** — 隐藏对话，收到新消息自动恢复
- 🌙 **深色模式** — 一键切换亮色/暗色主题

### 管理后台
- 📊 **数据统计** — 用户数、消息数、对话数
- 👥 **用户管理** — 查看所有用户、删除账号、重置密码
- 🔑 **安全管理** — 首次使用设置管理员密码

## 📁 项目结构

```
xgchat/
├── index.php              # 主页（聊天界面）
├── login.php              # 登录页
├── register.php           # 注册页
├── api.php                # RESTful API 接口
├── app.js                 # 前端逻辑
├── styles.css             # 样式表
├── auth.php               # 认证 + 会话管理
├── db.php                 # 数据库初始化 + 迁移
├── config.php             # 配置文件
├── download.php           # 认证文件下载
├── cleanup.php            # 自动清理过期文件
├── admin/
│   ├── index.php          # 管理员登录（首次设置密码）
│   └── dashboard.php      # 管理后台
└── data/
    ├── .htaccess          # 目录保护
    ├── chat.db            # SQLite 数据库（自动生成）
    └── sessions/          # PHP Session（自动生成）
```

## 🚀 快速开始

### 环境要求

| 依赖 | 最低版本 |
|------|---------|
| PHP  | 8.0+（需 PDO SQLite、Session） |
| Web 服务器 | Nginx / Apache / PHP 内置服务器 |

### 本地运行

```bash
cd xgchat
php -S 0.0.0.0:8080
# 浏览器访问 http://localhost:8080
```

### 部署到服务器

```bash
# 上传文件
scp -r xgchat/ user@server:/var/www/xgchat/

# 设置权限
chown -R www-data:www-data /var/www/xgchat/
chmod -R 755 /var/www/xgchat/
chmod -R 777 /var/www/xgchat/data/

# PHP session 配置
echo "session.save_path = /var/www/xgchat/data/sessions" > /etc/php/8.x/fpm/conf.d/session.ini
systemctl restart php8.x-fpm
```

### Nginx 配置

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

## 📖 使用流程

1. 访问网站 → 注册账号 → 登录
2. 点击右上角 **+** → **添加好友** → 搜索用户名
3. 对方在 **+** → **好友申请** 中接受请求
4. 成为好友后即可私聊、创建群组
5. 点击 💬 图标（左上角）→ 进入管理后台（首次设置密码）

## 🔒 安全设计

| 特性 | 说明 |
|------|------|
| **bcrypt 密码哈希** | 登录密码不可逆存储 |
| **Session 认证** | 服务端会话管理 |
| **隐藏对话** | 删除只是隐藏，收到新消息自动恢复 |
| **文件认证下载** | 下载文件需验证对话成员身份 |
| **自动清理** | 过期上传文件自动删除 |

## 🤝 贡献

1. Fork → 创建分支 → 提交 → Push → Pull Request

## 📄 开源协议

[MIT License](LICENSE)

---

<p align="center">如果对你有帮助，请给个 ⭐ Star！</p>
