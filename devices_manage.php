<?php
// devices_manage.php — 登录设备管理
declare(strict_types=1);

require __DIR__ . '/auth.php'; // 提供 $authUser, $pdo, 常量 AUTH_TOKEN_COOKIE

$userId = (int)$authUser['id'];
$errors = [];
$success = '';

// CSRF
if (empty($_SESSION['csrf_token_devices'])) {
    $_SESSION['csrf_token_devices'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token_devices'];

$currentCookieToken = $_COOKIE[AUTH_TOKEN_COOKIE] ?? '';

// 处理表单（注销设备）
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = '表单已失效，请刷新页面后重试。';
    } else {
        $action = $_POST['action'] ?? '';
        if ($action === 'revoke') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = '无效的设备 ID。';
            } else {
                try {
                    $stmt = $pdo->prepare("
                        UPDATE user_tokens
                        SET is_revoked = 1
                        WHERE id = :id AND user_id = :uid
                    ");
                    $stmt->execute([
                        ':id'  => $id,
                        ':uid' => $userId,
                    ]);
                    $success = '已注销该设备的长期登录凭证。';
                } catch (Throwable $e) {
                    $errors[] = '注销设备时发生错误。';
                }
            }
        }
    }
}

// 查询当前用户设备列表
$devices = [];
try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM user_tokens
        WHERE user_id = :uid
        ORDER BY is_revoked ASC, last_used_at DESC, created_at DESC
    ");
    $stmt->execute([':uid' => $userId]);
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = '读取设备列表失败：' . $e->getMessage();
}
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8"/>
    <title>登录设备管理 - 云剪切板</title>
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
                <a href="/devices_manage.php" class="dropdown-item active">登录设备管理</a>
                <a href="/profile.php" class="dropdown-item">账号信息 / 修改密码</a>
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
                <h2 class="page-title">登录设备管理</h2>
                <div class="text-muted mt-1">
                  查看并管理通过“保持登录”创建的长期登录凭证。
                </div>
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

          <div class="card mt-3">
            <div class="card-header">
              <h3 class="card-title mb-0">设备列表</h3>
            </div>
            <div class="card-body p-0">
              <div class="table-responsive">
                <table class="table table-striped mb-0">
                  <thead>
                    <tr>
                      <th>设备名称</th>
                      <th>状态</th>
                      <th>创建时间</th>
                      <th>最近使用</th>
                      <th>IP / UA</th>
                      <th>操作</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (!$devices): ?>
                      <tr>
                        <td colspan="6" class="text-center text-muted">
                          暂无长期登录设备记录。
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($devices as $d): ?>
                        <?php
                          $isRevoked = (int)$d['is_revoked'] === 1;
                          $isCurrent = ($currentCookieToken && hash_equals($currentCookieToken, (string)$d['token']));
                        ?>
                        <tr<?php echo $isCurrent ? ' class="table-active"' : ''; ?>>
                          <td>
                            <?php echo htmlspecialchars((string)$d['device_name'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($isCurrent): ?>
                              <span class="badge bg-primary ms-1">当前设备</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if ($isRevoked): ?>
                              <span class="badge bg-secondary-lt">已注销</span>
                            <?php else: ?>
                              <span class="badge bg-success-lt">有效</span>
                            <?php endif; ?>
                          </td>
                          <td><?php echo htmlspecialchars((string)$d['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                          <td>
                            <?php echo htmlspecialchars((string)$d['last_used_at'], ENT_QUOTES, 'UTF-8'); ?>
                            <?php if (!empty($d['expires_at'])): ?>
                              <div class="small text-muted">
                                过期：<?php echo htmlspecialchars((string)$d['expires_at'], ENT_QUOTES, 'UTF-8'); ?>
                              </div>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if (!empty($d['last_ip'])): ?>
                              <div>IP：<?php echo htmlspecialchars((string)$d['last_ip'], ENT_QUOTES, 'UTF-8'); ?></div>
                            <?php endif; ?>
                            <?php if (!empty($d['user_agent'])): ?>
                              <div class="small text-muted">
                                <?php echo htmlspecialchars(mb_substr((string)$d['user_agent'], 0, 60), ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (mb_strlen((string)$d['user_agent']) > 60) echo '...'; ?>
                              </div>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if (!$isRevoked): ?>
                              <form method="post" onsubmit="return confirm('确定要注销该设备的长期登录吗？下次在该设备访问需要重新登录。');">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                <input type="hidden" name="action" value="revoke">
                                <input type="hidden" name="id" value="<?php echo (int)$d['id']; ?>">
                                <button type="submit" class="btn btn-outline-danger btn-sm">
                                  注销
                                </button>
                              </form>
                            <?php else: ?>
                              <span class="text-muted">—</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </body>
</html>
