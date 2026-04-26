<?php
/**
 * install.php - 云剪切板程序初始化安装工具
 * 修正版：适配 login.php 的字段名 (password_hash) 与 状态位 (is_active)
 */

declare(strict_types=1);
date_default_timezone_set('Asia/Shanghai');

$errors = [];
$success = false;

// 1. 引入数据库配置
if (!file_exists(__DIR__ . '/db.php')) {
    die("未找到 db.php 配置文件。");
}
require __DIR__ . '/db.php';

// 2. 检测是否已经安装（检查 users 表是否存在且有管理员）
try {
    $check = $pdo->query("SELECT COUNT(*) FROM users");
    if ($check && (int)$check->fetchColumn() > 0) {
        die("程序检测到数据库已初始化。如需重装，请手动清空数据库。");
    }
} catch (PDOException $e) {
    // 表不存在，继续安装
}

// 3. 处理安装提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminUser = trim($_POST['username'] ?? '');
    $adminPass = $_POST['password'] ?? '';
    
    if (strlen($adminUser) < 3) $errors[] = "管理员账号至少 3 位。";
    if (strlen($adminPass) < 6) $errors[] = "管理员密码至少 6 位。";

    if (empty($errors)) {
        try {
            $pdo->beginTransaction();

            // A. 初始化表结构
            // 注意：这里增加了 is_active 字段，并将 password 改为 password_hash
            $sqls = [
                "CREATE TABLE IF NOT EXISTS users (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    username TEXT UNIQUE,
                    password_hash TEXT, 
                    display_name TEXT,
                    is_active INTEGER DEFAULT 1,
                    created_at DATETIME
                )",
                "CREATE TABLE IF NOT EXISTS cloud_clipboard (
                    id INTEGER PRIMARY KEY CHECK (id = 1),
                    content_type TEXT,
                    content TEXT,
                    mime_type TEXT,
                    updated_at DATETIME
                )",
                "CREATE TABLE IF NOT EXISTS user_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER,
                    token TEXT UNIQUE,
                    device_name TEXT,
                    is_revoked INTEGER DEFAULT 0,
                    last_ip TEXT,
                    user_agent TEXT,
                    created_at DATETIME,
                    last_used_at DATETIME,
                    expires_at DATETIME
                )",
                "CREATE TABLE IF NOT EXISTS api_tokens (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT,
                    token TEXT UNIQUE,
                    is_active INTEGER DEFAULT 1,
                    usage_count INTEGER DEFAULT 0,
                    last_used_at DATETIME,
                    expires_at DATETIME
                )"
            ];

            foreach ($sqls as $sql) {
                $pdo->exec($sql);
            }

            // B. 创建管理员
            $hashedPass = password_hash($adminPass, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, display_name, is_active, created_at) VALUES (?, ?, ?, 1, ?)");
            $stmt->execute([$adminUser, $hashedPass, '管理员', date('Y-m-d H:i:s')]);

            // C. 初始化剪切板
            $pdo->exec("INSERT OR IGNORE INTO cloud_clipboard (id, content_type, content, updated_at) VALUES (1, 'text', '欢迎使用云剪切板！', '" . date('Y-m-d H:i:s') . "')");

            $pdo->commit();
            
            // 生成安装锁
            file_put_contents(__DIR__ . '/install.lock', date('Y-m-d H:i:s'));
            $success = true;

        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $errors[] = "安装失败: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8"/>
    <title>系统安装 - 云剪切板</title>
    <link href="/style/tabler.min.css" rel="stylesheet"/>
    <style>
        body { background-color: #f4f6fa; }
        .container-tight { max-width: 400px; margin: 10vh auto; }
    </style>
</head>
<body>
    <div class="container-tight py-4">
        <div class="card card-md">
            <div class="card-body">
                <h2 class="card-title text-center mb-4">初次使用初始化</h2>

                <?php if ($success): ?>
                    <div class="alert alert-success">
                        <h4>安装成功！</h4>
                        <p>请删除install.php确保安全</p>
                        <a href="/login.php" class="btn btn-primary w-100">前往登录</a>
                    </div>
                <?php else: ?>
                    <form method="post">
                        <?php if ($errors): ?>
                            <div class="alert alert-danger">
                                <?php echo implode('<br>', $errors); ?>
                            </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label">管理员账号</label>
                            <input type="text" name="username" class="form-control" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">管理员密码</label>
                            <input type="password" name="password" class="form-control" required>
                        </div>
                        <div class="form-footer">
                            <button type="submit" class="btn btn-primary w-100">执行初始化</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>