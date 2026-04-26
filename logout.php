<?php
// logout.php — 退出登录
declare(strict_types=1);
session_start();

require __DIR__ . '/db.php';

// 与 auth.php / login.php 保持一致
const AUTH_TOKEN_COOKIE = 'copy_auth_token';

// 如果有 Cookie token，尝试标记为 revoked
if (!empty($_COOKIE[AUTH_TOKEN_COOKIE])) {
    $rawToken = (string)$_COOKIE[AUTH_TOKEN_COOKIE];

    if (preg_match('/^[a-f0-9]{64}$/i', $rawToken)) {
        try {
            $stmt = $pdo->prepare("UPDATE user_tokens SET is_revoked = 1 WHERE token = :token");
            $stmt->execute([':token' => $rawToken]);
        } catch (Throwable $e) {
            // 标记失败无所谓，只要清掉 Cookie 和 Session 即可
        }
    }

    // 清 Cookie
    setcookie(
        AUTH_TOKEN_COOKIE,
        '',
        [
            'expires'  => time() - 3600,
            'path'     => '/',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]
    );
    unset($_COOKIE[AUTH_TOKEN_COOKIE]);
}

// 清 Session
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        [
            'expires'  => time() - 42000,
            'path'     => $params['path'],
            'domain'   => $params['domain'],
            'secure'   => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]
    );
}
session_destroy();

// 退出后统一跳登录页
header('Location: /login.php');
exit;
