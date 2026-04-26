<?php
// login.php — 登录页面
declare(strict_types=1);
session_start();

require __DIR__ . '/db.php';

// 跟 auth.php 保持一致的 Cookie 名
const AUTH_TOKEN_COOKIE = 'copy_auth_token';

$errors  = [];
$success = '';

// 简单 CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// 如果已经有 Session 登录，直接跳到首页
if (!empty($_SESSION['user_id']) && is_numeric($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

// 处理提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = '表单已失效，请刷新页面后重试。';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $remember = isset($_POST['remember']) && $_POST['remember'] === '1';

        if ($username === '' || $password === '') {
            $errors[] = '用户名和密码不能为空。';
        } else {
            try {
                $stmt = $pdo->prepare("
                    SELECT id, username, password_hash, display_name, is_active
                    FROM users
                    WHERE username = :username
                    LIMIT 1
                ");
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);

                if (!$user || (int)$user['is_active'] !== 1) {
                    $errors[] = '用户名或密码错误。';
                } elseif (!password_verify($password, (string)$user['password_hash'])) {
                    $errors[] = '用户名或密码错误。';
                } else {
                    // 登录成功
                    $userId = (int)$user['id'];

                    // 防止 Session 固定攻击
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;

                    // 处理“保持登录”
                    if ($remember) {
                        $token      = bin2hex(random_bytes(32)); // 64位hex
                        $userAgent   = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
                        $ip         = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
                        $deviceName = 'Browser';

                        if (stripos($userAgent, 'Windows') !== false) {
                            $deviceName = 'Windows 浏览器';
                        } elseif (stripos($userAgent, 'Macintosh') !== false) {
                            $deviceName = 'macOS 浏览器';
                        } elseif (stripos($userAgent, 'Linux') !== false) {
                            $deviceName = 'Linux 浏览器';
                        }

                        // 设定一个默认过期时间，比如 90 天
                        $expiresAt = (new DateTimeImmutable('+90 days'))->format('Y-m-d H:i:s');

                        // SQLite 适配：将 NOW() 替换为 datetime('now', 'localtime')
                        $stmtInsert = $pdo->prepare("
                            INSERT INTO user_tokens (user_id, token, device_name, user_agent, last_ip, created_at, last_used_at, expires_at, is_revoked)
                            VALUES (:user_id, :token, :device_name, :user_agent, :last_ip, datetime('now', 'localtime'), datetime('now', 'localtime'), :expires_at, 0)
                        ");
                        $stmtInsert->execute([
                            ':user_id'    => $userId,
                            ':token'      => $token,
                            ':device_name'=> $deviceName,
                            ':user_agent' => $userAgent,
                            ':last_ip'    => $ip,
                            ':expires_at' => $expiresAt,
                        ]);

                        // 写 Cookie
                        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
                        setcookie(
                            AUTH_TOKEN_COOKIE,
                            $token,
                            [
                                'expires'  => time() + 90 * 24 * 60 * 60,  // 90天
                                'path'     => '/',
                                'secure'   => $isSecure,
                                'httponly' => true,
                                'samesite' => 'Lax',
                            ]
                        );
                    }

                    header('Location: /index.php');
                    exit;
                }
            } catch (Throwable $e) {
                $errors[] = '登录时发生错误。';
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8"/>
    <title>登录 - 云剪切板</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="color-scheme" content="light dark"/>
    <link href="/style/tabler.min.css" rel="stylesheet"/>
    <script src="/style/tabler.min.js" defer></script>
  </head>
  <body class="border-top-wide border-primary d-flex flex-column">
    <div class="page page-center">
      <div class="container-tight py-4">
        <div class="text-center mb-4">
          <h2 class="h2">云剪切板登录</h2>
        </div>

        <?php if ($errors): ?>
          <div class="alert alert-danger" role="alert">
            <ul class="mb-0">
              <?php foreach ($errors as $err): ?>
                <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="card card-md">
          <div class="card-body">
            <form method="post" autocomplete="off">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>"/>

              <div class="mb-3">
                <label class="form-label required">用户名</label>
                <input type="text" name="username" class="form-control" required maxlength="50" autofocus>
              </div>

              <div class="mb-2">
                <label class="form-label required">密码</label>
                <input type="password" name="password" class="form-control" required>
              </div>

              <div class="mb-3">
                <label class="form-check">
                  <input class="form-check-input" type="checkbox" name="remember" value="1">
                  <span class="form-check-label">在此设备上保持登录</span>
                </label>
              </div>

              <div class="form-footer">
                <button type="submit" class="btn btn-primary w-100">登录</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>