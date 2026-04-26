<?php
// profile.php — 账号信息 / 修改密码
declare(strict_types=1);

require __DIR__ . '/auth.php'; // 提供 $authUser, $pdo

$userId = (int)$authUser['id'];
$errors = [];
$success = '';

// CSRF 保护
if (empty($_SESSION['csrf_token_profile'])) {
    $_SESSION['csrf_token_profile'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token_profile'];

// 重新查询最新用户信息，确保数据实时
try {
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id LIMIT 1");
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$user) {
        $errors[] = '未找到当前用户信息。';
    }
} catch (Throwable $e) {
    $errors[] = '读取用户信息失败：' . $e->getMessage();
    $user = $authUser;
}

// 处理 POST 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = '表单已失效，请刷新页面后重试。';
    } else {
        $action = $_POST['action'] ?? '';
        
        // 1. 更新基本信息
        if ($action === 'profile') {
            $displayName = trim((string)($_POST['display_name'] ?? ''));
            if ($displayName === '') {
                $errors[] = '显示名称不能为空。';
            } elseif (mb_strlen($displayName) > 100) {
                $errors[] = '显示名称不能超过 100 个字符。';
            } else {
                try {
                    // SQLite 兼容：使用 datetime('now', 'localtime') 替代 NOW()
                    $stmtUp = $pdo->prepare("
                        UPDATE users 
                        SET display_name = :dn, updated_at = datetime('now', 'localtime') 
                        WHERE id = :id
                    ");
                    $stmtUp->execute([
                        ':dn' => $displayName,
                        ':id' => $userId,
                    ]);
                    $success = '显示名称已更新。';
                    $user['display_name'] = $displayName;
                    $authUser['display_name'] = $displayName;
                } catch (Throwable $e) {
                    $errors[] = '更新显示名称时发生错误。';
                }
            }
        } 
        
        // 2. 修改密码
        elseif ($action === 'password') {
            $oldPassword = (string)($_POST['old_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $newPassword2= (string)($_POST['new_password_confirm'] ?? '');

            if ($oldPassword === '' || $newPassword === '' || $newPassword2 === '') {
                $errors[] = '请完整填写原密码和新密码。';
            } elseif ($newPassword !== $newPassword2) {
                $errors[] = '两次输入的新密码不一致。';
            } elseif (strlen($newPassword) < 6) {
                $errors[] = '新密码长度至少 6 位。';
            } else {
                // 校验原密码
                if (empty($user['password_hash']) || !password_verify($oldPassword, (string)$user['password_hash'])) {
                    $errors[] = '原密码错误。';
                } else {
                    try {
                        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
                        // SQLite 兼容：使用 datetime('now', 'localtime') 替代 NOW()
                        $stmtUp = $pdo->prepare("
                            UPDATE users 
                            SET password_hash = :hash, updated_at = datetime('now', 'localtime') 
                            WHERE id = :id
                        ");
                        $stmtUp->execute([
                            ':hash' => $hash,
                            ':id'   => $userId,
                        ]);
                        $success = '密码已成功修改。';
                    } catch (Throwable $e) {
                        $errors[] = '修改密码时发生错误。';
                    }
                }
            }
        }
    }
}
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8"/>
    <title>账号信息 / 修改密码 - 云剪切板</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="color-scheme" content="light dark"/>
    <link href="/style/tabler.min.css" rel="stylesheet"/>
    <script src="/style/tabler.min.js" defer></script>
  </head>
  <body>
    <div class="page">
      <header class="navbar navbar-expand-md navbar-light d-print-none">
        <div class="container-xl">
          <a href="/" class="navbar-brand">
            <span class="navbar-brand-text">云剪切板</span>
          </a>

          <div class="navbar-nav flex-row order-md-last">
            <div class="nav-item dropdown">
              <a href="#" class="nav-link d-flex lh-1 text-reset" data-bs-toggle="dropdown" aria-label="Open user menu">
                <span class="avatar avatar-sm">
                  <?php echo htmlspecialchars(mb_substr($authUser['display_name'] ?? $authUser['username'], 0, 1), ENT_QUOTES, 'UTF-8'); ?>
                </span>
                <div class="d-none d-xl-block ps-2">
                  <div><?php echo htmlspecialchars($authUser['display_name'] ?? $authUser['username'], ENT_QUOTES, 'UTF-8'); ?></div>
                  <div class="mt-1 small text-muted">已登录</div>
                </div>
              </a>
              <div class="dropdown-menu dropdown-menu-end dropdown-menu-arrow">
                <a href="/tokens_manage.php" class="dropdown-item">API Token 管理</a>
                <a href="/devices_manage.php" class="dropdown-item">登录设备管理</a>
                <a href="/profile.php" class="dropdown-item active">账号信息 / 修改密码</a>
                <div class="dropdown-divider"></div>
                <a href="/logout.php" class="dropdown-item text-danger">退出登录</a>
              </div>
            </div>
          </div>

          <div class="collapse navbar-collapse" id="navbar-menu">
            <div class="navbar-nav">
              <a class="nav-link" href="/index.php">
                <span class="nav-link-title">首页</span>
              </a>
            </div>
          </div>
        </div>
      </header>

      <div class="page-body">
        <div class="container-xl">
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col">
                <h2 class="page-title">账号信息 / 修改密码</h2>
              </div>
            </div>
          </div>

          <?php if ($errors): ?>
            <div class="alert alert-danger mt-3">
              <div class="fw-bold mb-2">操作失败：</div>
              <ul class="mb-0">
                <?php foreach ($errors as $err): ?>
                  <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endif; ?>

          <?php if ($success): ?>
            <div class="alert alert-success mt-3">
              <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
            </div>
          <?php endif; ?>

          <div class="row row-cards mt-3">
            <div class="col-md-6">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title mb-0">基本信息</h3>
                </div>
                <div class="card-body">
                  <dl class="row">
                    <dt class="col-4">用户名</dt>
                    <dd class="col-8">
                      <span class="font-monospace"><?php echo htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8'); ?></span>
                    </dd>
                    <dt class="col-4">显示名称</dt>
                    <dd class="col-8"><?php echo htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8'); ?></dd>
                    <dt class="col-4">创建时间</dt>
                    <dd class="col-8"><?php echo htmlspecialchars((string)$user['created_at'], ENT_QUOTES, 'UTF-8'); ?></dd>
                  </dl>
                  <hr>
                  <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="profile">
                    <div class="mb-2">
                      <label class="form-label required">显示名称</label>
                      <input type="text" name="display_name" class="form-control" maxlength="100" value="<?php echo htmlspecialchars((string)$user['display_name'], ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="form-footer">
                      <button type="submit" class="btn btn-primary">更新显示名称</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title mb-0">修改密码</h3>
                </div>
                <div class="card-body">
                  <form method="post" autocomplete="off">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="password">
                    <div class="mb-2">
                      <label class="form-label required">原密码</label>
                      <input type="password" name="old_password" class="form-control" required>
                    </div>
                    <div class="mb-2">
                      <label class="form-label required">新密码</label>
                      <input type="password" name="new_password" class="form-control" minlength="6" required>
                    </div>
                    <div class="mb-3">
                      <label class="form-label required">确认新密码</label>
                      <input type="password" name="new_password_confirm" class="form-control" minlength="6" required>
                    </div>
                    <div class="form-footer">
                      <button type="submit" class="btn btn-primary">修改密码</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </body>
</html>