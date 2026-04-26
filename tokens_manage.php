<?php
// tokens_manage.php — API Token 管理
declare(strict_types=1);

require __DIR__ . '/auth.php'; // 提供 $authUser, $pdo

$userId = (int)$authUser['id'];
$errors = [];
$success = '';

// CSRF
if (empty($_SESSION['csrf_token_tokens'])) {
    $_SESSION['csrf_token_tokens'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token_tokens'];

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $postedToken)) {
        $errors[] = '表单已失效，请刷新页面后重试。';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name        = trim((string)($_POST['name'] ?? ''));
            $allowedIps  = trim((string)($_POST['allowed_ips'] ?? ''));
            $expireDays  = trim((string)($_POST['expire_days'] ?? ''));
            $customToken = trim((string)($_POST['token_value'] ?? ''));

            if ($name === '') {
                $errors[] = 'Token 名称不能为空。';
            } elseif (mb_strlen($name) > 100) {
                $errors[] = 'Token 名称不能超过 100 个字符。';
            }

            if ($allowedIps !== '' && mb_strlen($allowedIps) > 255) {
                $errors[] = '允许 IP 字段过长，请适当精简。';
            }

            // 处理自定义 Token 值（可选）
            $tokenToUse = '';
            if ($customToken !== '') {
                if (mb_strlen($customToken) > 128) {
                    $errors[] = '自定义 Token 过长，请控制在 128 个字符以内。';
                } else {
                    $tokenToUse = $customToken;
                }
            }

            $expiresAt = null;
            if ($expireDays !== '') {
                if (!ctype_digit($expireDays) || (int)$expireDays <= 0) {
                    $errors[] = '过期天数必须是大于 0 的整数。';
                } else {
                    $dt = new DateTimeImmutable();
                    $dt = $dt->modify('+' . (int)$expireDays . ' days');
                    $expiresAt = $dt->format('Y-m-d H:i:s');
                }
            }

            if (!$errors) {
                try {
                    // 如果未提供自定义 Token，则自动生成
                    if ($tokenToUse === '') {
                        $tokenToUse = bin2hex(random_bytes(32)); // 64位 hex
                    }

                    $stmtIns = $pdo->prepare("
                        INSERT INTO api_tokens
                          (user_id, name, token, is_active, allowed_ips, created_at, expires_at, usage_count)
                        VALUES
                          (:uid, :name, :token, 1, :ips, datetime('now', 'localtime'), :expires_at, 0)
                    ");
                    $stmtIns->execute([
                        ':uid'        => $userId,
                        ':name'       => $name,
                        ':token'      => $tokenToUse,
                        ':ips'        => $allowedIps !== '' ? $allowedIps : null,
                        ':expires_at' => $expiresAt,
                    ]);
                    $success = '已创建新的 API Token，请注意保存好 Token 值。';
                } catch (Throwable $e) {
                    $errors[] = '创建 Token 时发生错误：' . $e->getMessage();
                }
            }
        } elseif ($action === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = '无效的 Token ID。';
            } else {
                try {
                    // 先查当前状态
                    $stmt = $pdo->prepare("SELECT is_active FROM api_tokens WHERE id = :id AND user_id = :uid");
                    $stmt->execute([':id' => $id, ':uid' => $userId]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$row) {
                        $errors[] = '未找到对应的 Token。';
                    } else {
                        $newStatus = ((int)$row['is_active'] === 1) ? 0 : 1;
                        $stmtUp = $pdo->prepare("
                            UPDATE api_tokens
                            SET is_active = :active
                            WHERE id = :id AND user_id = :uid
                        ");
                        $stmtUp->execute([
                            ':active' => $newStatus,
                            ':id'     => $id,
                            ':uid'    => $userId,
                        ]);
                        $success = $newStatus ? '已启用该 Token。' : '已禁用该 Token。';
                    }
                } catch (Throwable $e) {
                    $errors[] = '更新 Token 状态时发生错误。';
                }
            }
        } elseif ($action === 'delete') {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) {
                $errors[] = '无效的 Token ID。';
            } else {
                try {
                    $stmtDel = $pdo->prepare("DELETE FROM api_tokens WHERE id = :id AND user_id = :uid");
                    $stmtDel->execute([':id' => $id, ':uid' => $userId]);
                    $success = '已删除该 Token。';
                } catch (Throwable $e) {
                    $errors[] = '删除 Token 时发生错误。';
                }
            }
        }
    }
}

// 查询当前用户的所有 Token
$tokens = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM api_tokens WHERE user_id = :uid ORDER BY id DESC");
    $stmt->execute([':uid' => $userId]);
    $tokens = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $errors[] = '读取 Token 列表失败：' . $e->getMessage();
}

function mask_token(string $token): string {
    if (mb_strlen($token) <= 8) {
        return $token;
    }
    return mb_substr($token, 0, 4) . '...' . mb_substr($token, -4);
}
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8"/>
    <title>API Token 管理 - 云剪切板</title>
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
                <a href="/tokens_manage.php" class="dropdown-item active">API Token 管理</a>
                <a href="/devices_manage.php" class="dropdown-item">登录设备管理</a>
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
                <h2 class="page-title">API Token 管理</h2>
                <div class="text-muted mt-1">
                  用于安卓 App 等客户端访问云剪切板接口的访问凭证。
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
              <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?></div>
          <?php endif; ?>

          <div class="row row-cards mt-3">
            <div class="col-lg-5">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title mb-0">创建新 Token</h3>
                </div>
                <div class="card-body">
                  <form method="post" autocomplete="off" id="form-create-token">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="action" value="create">

                    <div class="mb-2">
                      <label class="form-label required">Token 名称</label>
                      <input type="text" name="name" class="form-control" maxlength="100" required>
                      <div class="form-hint">例如：Android 手机、TV 盒子 等。</div>
                    </div>

                    <div class="mb-2">
                      <label class="form-label">Token 值（可选）</label>
                      <div class="input-group">
                        <input type="text" name="token_value" id="token_value" class="form-control" maxlength="128" placeholder="留空则自动生成随机 Token">
                        <button class="btn btn-outline-secondary" type="button" id="btn-generate-token">
                          生成随机 Token
                        </button>
                      </div>
                      <div class="form-hint">
                        可自定义 Token 字符串（建议随机、足够复杂）。不填则自动生成 64 位十六进制字符串。
                      </div>
                    </div>

                    <div class="mb-2">
                      <label class="form-label">允许 IP（可选）</label>
                      <input type="text" name="allowed_ips" class="form-control" maxlength="255">
                      <div class="form-hint">
                        可填 IP 或网段列表（自定义格式），留空表示不限制。
                      </div>
                    </div>

                    <div class="mb-3">
                      <label class="form-label">过期天数（可选）</label>
                      <input type="number" name="expire_days" class="form-control" min="1" inputmode="numeric">
                      <div class="form-hint">
                        留空表示不过期；建议为客户端设置合适的过期时间。
                      </div>
                    </div>

                    <div class="form-footer">
                      <button type="submit" class="btn btn-primary w-100">
                        创建 Token
                      </button>
                    </div>
                  </form>
                </div>
              </div>
            </div>

            <div class="col-lg-7">
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title mb-0">已有 Token 列表</h3>
                </div>
                <div class="card-body p-0">
                  <div class="table-responsive">
                    <table class="table table-striped mb-0">
                      <thead>
                        <tr>
                          <th>名称</th>
                          <th>Token</th>
                          <th>状态</th>
                          <th>创建时间</th>
                          <th>过期时间</th>
                          <th>最近使用</th>
                          <th>次数</th>
                          <th>操作</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (!$tokens): ?>
                          <tr>
                            <td colspan="8" class="text-center text-muted">
                              暂无 Token，可在左侧创建。
                            </td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($tokens as $t): ?>
                            <tr>
                              <td>
                                <?php echo htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8'); ?>
                                <?php if (!empty($t['allowed_ips'])): ?>
                                  <div class="small text-muted">
                                    IP: <?php echo htmlspecialchars($t['allowed_ips'], ENT_QUOTES, 'UTF-8'); ?>
                                  </div>
                                <?php endif; ?>
                              </td>
                              <td>
                                <?php
                                  $fullToken = (string)$t['token'];
                                  $masked    = mask_token($fullToken);
                                ?>
                                <span class="font-monospace d-block">
                                  <span
                                    class="token-display"
                                    data-full="<?php echo htmlspecialchars($fullToken, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-masked="<?php echo htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-visible="masked"
                                  >
                                    <?php echo htmlspecialchars($masked, ENT_QUOTES, 'UTF-8'); ?>
                                  </span>
                                </span>
                                <button type="button" class="btn btn-link px-0 token-toggle small">
                                  显示完整
                                </button>
                              </td>
                              <td>
                                <?php if ((int)$t['is_active'] === 1): ?>
                                  <span class="badge bg-success-lt">启用</span>
                                <?php else: ?>
                                  <span class="badge bg-secondary-lt">停用</span>
                                <?php endif; ?>
                              </td>
                              <td><?php echo htmlspecialchars((string)$t['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                              <td>
                                <?php echo $t['expires_at'] ? htmlspecialchars((string)$t['expires_at'], ENT_QUOTES, 'UTF-8') : '—'; ?>
                              </td>
                              <td>
                                <?php echo $t['last_used_at'] ? htmlspecialchars((string)$t['last_used_at'], ENT_QUOTES, 'UTF-8') : '—'; ?>
                                <?php if (!empty($t['last_used_ip'])): ?>
                                  <div class="small text-muted">
                                    IP: <?php echo htmlspecialchars((string)$t['last_used_ip'], ENT_QUOTES, 'UTF-8'); ?>
                                  </div>
                                <?php endif; ?>
                              </td>
                              <td><?php echo htmlspecialchars((string)$t['usage_count'], ENT_QUOTES, 'UTF-8'); ?></td>
                              <td>
                                <div class="btn-group btn-group-sm">
                                  <form method="post" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                    <button type="submit" class="btn btn-outline-secondary">
                                      <?php echo (int)$t['is_active'] === 1 ? '停用' : '启用'; ?>
                                    </button>
                                  </form>
                                  <form method="post" class="d-inline" onsubmit="return confirm('确定要删除该 Token 吗？此操作不可恢复。');">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$t['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger">
                                      删除
                                    </button>
                                  </form>
                                </div>
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
      </div>
    </div>

    <script>
      (function () {
        // 生成随机 Token（64 位十六进制）
        const btnGen = document.getElementById('btn-generate-token');
        const inputToken = document.getElementById('token_value');

        function randomHex(len) {
          const chars = '0123456789abcdef';
          let out = '';
          const array = new Uint8Array(len);
          if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(array);
            for (let i = 0; i < len; i++) {
              out += chars[array[i] % 16];
            }
          } else {
            // 退化方案
            for (let i = 0; i < len; i++) {
              out += Math.floor(Math.random() * 16).toString(16);
            }
          }
          return out;
        }

        if (btnGen && inputToken) {
          btnGen.addEventListener('click', function () {
            inputToken.value = randomHex(64);
          });
        }

        // 列表中 Token 显示/隐藏完整值
        const toggleButtons = document.querySelectorAll('.token-toggle');
        toggleButtons.forEach(function (btn) {
          btn.addEventListener('click', function () {
            const td = btn.closest('td');
            if (!td) return;
            const span = td.querySelector('.token-display');
            if (!span) return;

            const visible = span.getAttribute('data-visible') || 'masked';
            const masked  = span.getAttribute('data-masked') || '';
            const full    = span.getAttribute('data-full') || '';

            if (visible === 'masked') {
              span.textContent = full;
              span.setAttribute('data-visible', 'full');
              btn.textContent = '隐藏';
            } else {
              span.textContent = masked;
              span.setAttribute('data-visible', 'masked');
              btn.textContent = '显示完整';
            }
          });
        });
      })();
    </script>
  </body>
</html>