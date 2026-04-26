<?php
// auth.php — 登录状态验证 & 长期登录支持 (SQLite 版)
declare(strict_types=1);

session_start();

require __DIR__ . '/db.php'; // 提供 $pdo

// 统一的浏览器长期登录 Cookie 名
const AUTH_TOKEN_COOKIE = 'copy_auth_token';

$authUser = null;

/**
 * 获取客户端 IP
 */
function getClientIp(): string
{
    $keys = [
        'HTTP_X_FORWARDED_FOR',
        'HTTP_CLIENT_IP',
        'REMOTE_ADDR',
    ];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ips = explode(',', (string)$_SERVER[$key]);
            return trim($ips[0]);
        }
    }
    return '0.0.0.0';
}

/**
 * 根据用户 ID 加载用户
 */
function loadUserById(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id AND is_active = 1 LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/**
 * 清理登录状态
 */
function clearAuthState(): void
{
    unset($_SESSION['user_id']);
    if (isset($_COOKIE[AUTH_TOKEN_COOKIE])) {
        // 兼容 HTTPS 和 HTTP
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie(AUTH_TOKEN_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
        unset($_COOKIE[AUTH_TOKEN_COOKIE]);
    }
}

// ========== 1. 先尝试使用 Session 登录状态 ==========
if (isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    $userId = (int)$_SESSION['user_id'];
    if ($userId > 0) {
        $authUser = loadUserById($pdo, $userId);
        if (!$authUser) {
            clearAuthState();
        }
    } else {
        clearAuthState();
    }
}

// ========== 2. 如果没有 Session 用户，尝试用长期 token 自动登录 ==========
if ($authUser === null && !empty($_COOKIE[AUTH_TOKEN_COOKIE])) {
    $rawToken = (string)$_COOKIE[AUTH_TOKEN_COOKIE];

    if (preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
        // SQLite 兼容：使用 datetime('now', 'localtime') 代替 NOW()
        $stmt = $pdo->prepare("
            SELECT
                ut.id AS token_id,
                ut.user_id,
                ut.is_revoked,
                ut.expires_at,
                u.*
            FROM user_tokens ut
            JOIN users u ON ut.user_id = u.id
            WHERE ut.token = :token
              AND ut.is_revoked = 0
              AND (ut.expires_at IS NULL OR ut.expires_at > datetime('now', 'localtime'))
              AND u.is_active = 1
            LIMIT 1
        ");
        $stmt->execute([':token' => $rawToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $tokenId = (int)$row['token_id'];
            $userId  = (int)$row['user_id'];

            unset($row['token_id'], $row['user_id'], $row['is_revoked'], $row['expires_at']);
            $authUser = $row;

            $_SESSION['user_id'] = $userId;

            try {
                $ip = getClientIp();
                // SQLite 兼容：更新 last_used_at
                $stmtUpdate = $pdo->prepare("
                    UPDATE user_tokens
                    SET last_used_at = datetime('now', 'localtime'),
                        last_ip      = :ip
                    WHERE id = :id
                ");
                $stmtUpdate->execute([
                    ':ip' => $ip,
                    ':id' => $tokenId,
                ]);
            } catch (Throwable $e) {
                // 忽略更新错误
            }
        } else {
            clearAuthState();
        }
    } else {
        clearAuthState();
    }
}

// ========== 3. 如果仍然没有登录用户，则重定向到登录页 ==========
if ($authUser === null) {
    // 避免死循环：如果已经在登录页则不跳转
    $currentFile = basename($_SERVER['PHP_SELF']);
    if ($currentFile !== 'login.php') {
        header('Location: /login.php');
        exit;
    }
}