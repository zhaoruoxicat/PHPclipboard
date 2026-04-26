<?php
// pwa.php — 云剪切板 PWA 简易版（手机端优先）
declare(strict_types=1);

require __DIR__ . '/auth.php';
// require __DIR__ . '/db.php'; // 假设 auth.php 或 db.php 中已初始化 $pdo

$displayName = $authUser['display_name'] ?? $authUser['username'] ?? '用户';

$errors  = [];
$success = '';

// ========== 保存到云剪切板（兼容 SQLite） ==========
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $contentType = $_POST['content_type'] ?? 'text';
    $contentType = $contentType === 'image' ? 'image' : 'text';

    $rawContent = trim((string)($_POST['clipboard_text'] ?? ''));
    $mimeType   = null;

    if ($contentType === 'image') {
        $mimeType = trim((string)($_POST['mime_type'] ?? 'image/png'));
        if ($rawContent === '') {
            $errors[] = '图片 Base64 内容不能为空。';
        } else {
            if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $rawContent)) {
                $errors[] = '图片 Base64 内容格式不正确。';
            }
            if (strlen($rawContent) > 10 * 1024 * 1024) {
                $errors[] = '图片过大，Base64 长度超过 10MB 限制。';
            }
        }
    } else {
        if ($rawContent === '') {
            $errors[] = '云剪切板内容不能为空。';
        } else {
            if (mb_strlen($rawContent) > 5000) {
                $errors[] = '文本内容过长，请控制在 5000 字以内。';
            }
        }
        $mimeType = 'text/plain';
    }

    if (!$errors) {
        try {
            // 兼容 SQLite：将 NOW() 更改为 datetime('now', 'localtime')
            $stmt = $pdo->prepare("
                UPDATE cloud_clipboard
                SET content_type = :ctype,
                    content      = :content,
                    mime_type    = :mime,
                    updated_at   = datetime('now', 'localtime')
                WHERE id = 1
            ");
            $stmt->execute([
                ':ctype'   => $contentType,
                ':content' => $rawContent,
                ':mime'    => $mimeType,
            ]);
            $success = $contentType === 'image' ? '图片已更新到云剪切板。' : '文本已更新到云剪切板。';
        } catch (Throwable $e) {
            $errors[] = '保存云剪切板内容时发生错误。';
        }
    }
}

// ========== 读取当前云剪切板内容 ==========
$currentType    = 'text';
$currentContent = '';
$currentMime    = null;
$currentUpdated = null;

try {
    $stmt = $pdo->prepare("SELECT content_type, content, mime_type, updated_at FROM cloud_clipboard WHERE id = 1 LIMIT 1");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $currentType    = (string)$row['content_type'];
        $currentContent = (string)$row['content'];
        $currentMime    = $row['mime_type'] !== null ? (string)$row['mime_type'] : null;
        $currentUpdated = $row['updated_at'] !== null ? (string)$row['updated_at'] : null;
    }
} catch (Throwable $e) {
    $errors[] = '读取云剪切板内容失败：' . $e->getMessage();
}

$mime = $currentMime ?: 'image/png';
$dataUrl = ($currentType === 'image' && $currentContent !== '')
    ? ('data:' . $mime . ';base64,' . $currentContent)
    : '';
?>
<!doctype html>
<html lang="zh-CN">
<head>
  <meta charset="utf-8"/>
  <title>云剪切板（PWA）</title>
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
  <meta name="color-scheme" content="light dark"/>
  <link href="/style/tabler.min.css" rel="stylesheet"/>
  <link rel="manifest" href="/manifest.webmanifest">
  <style>
    /* 自定义剪切板图片显示高度 */
    .clipboard-img-container {
        max-height: 220px; /* 您可以在这里统一修改图片显示高度 */
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        background-color: rgba(0,0,0,0.03);
    }
    .clipboard-img-container img {
        max-width: 100%;
        max-height: 220px; /* 需与容器保持一致 */
        object-fit: contain;
    }
  </style>
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {});
      });
    }
  </script>
  <script src="/style/tabler.min.js" defer></script>
</head>
<body>
<div class="page">
  <div class="page-body">
    <div class="container-fluid p-3" style="max-width: 520px;">
      <div class="d-flex align-items-center justify-content-between mb-3">
        <div>
          <div class="h2 mb-0">云剪切板</div>
          <div class="text-muted small">Hi，<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?></div>
        </div>
        <div class="dropdown">
          <button class="btn btn-outline-secondary btn-icon" data-bs-toggle="dropdown" aria-label="menu">
            ☰
          </button>
          <div class="dropdown-menu dropdown-menu-end">
            <a class="dropdown-item" href="/tokens_manage.php">API Token 管理</a>
            <a class="dropdown-item" href="/devices_manage.php">登录设备管理</a>
            <a class="dropdown-item" href="/profile.php">账号信息 / 修改密码</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item text-danger" href="/logout.php">退出登录</a>
          </div>
        </div>
      </div>

      <div id="toastArea" class="mb-3" style="display:none;"></div>

      <?php if ($errors): ?>
        <div class="alert alert-danger" role="alert">
          <div class="small">
            <?php foreach ($errors as $err): ?>
              <div>• <?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($success): ?>
        <div class="alert alert-success" role="alert">
          <?php echo htmlspecialchars($success, ENT_QUOTES, 'UTF-8'); ?>
        </div>
      <?php endif; ?>

      <div class="card mb-3">
        <div class="card-body">
          <div class="d-flex align-items-center justify-content-between">
            <div>
              <div class="fw-bold">当前云剪切板</div>
              <div class="text-muted small">
                <?php echo $currentType === 'image' ? '类型：图片' : '类型：文本'; ?>
              </div>
            </div>
            <span class="badge bg-primary-lt"><?php echo $currentType === 'image' ? 'IMAGE' : 'TEXT'; ?></span>
          </div>

          <div class="mt-3">
            <?php if ($currentContent === ''): ?>
              <div class="text-muted small">当前云剪切板为空。</div>
            <?php else: ?>
              <?php if ($currentType === 'text'): ?>
                <div class="border rounded p-2 small" style="max-height: 220px; overflow:auto; white-space: pre-wrap; word-break: break-word;">
                  <?php echo htmlspecialchars($currentContent, ENT_QUOTES, 'UTF-8'); ?>
                </div>
              <?php else: ?>
                <div class="border rounded clipboard-img-container">
                  <img
                    src="<?php echo htmlspecialchars($dataUrl, ENT_QUOTES, 'UTF-8'); ?>"
                    alt="云剪切板图片"
                  />
                </div>
                <div class="text-muted text-center small mt-2">MIME: <?php echo htmlspecialchars($mime, ENT_QUOTES, 'UTF-8'); ?></div>
              <?php endif; ?>
            <?php endif; ?>
          </div>

          <div class="mt-3 d-grid gap-2">
            <button type="button" class="btn btn-primary btn-lg" id="btnCopyFromCloud">
              复制到本机剪切板
            </button>
            <button type="button" class="btn btn-outline-secondary btn-lg" id="btnUpdateCloud">
              更新云剪切板（粘贴上传）
            </button>
          </div>
        </div>
      </div>

      <form method="post" id="hiddenForm" style="display:none;">
        <input type="hidden" name="content_type" id="content_type" value="text">
        <input type="hidden" name="mime_type" id="mime_type" value="text/plain">
        <input type="hidden" name="clipboard_text" id="clipboard_text" value="">
      </form>

      <div class="text-muted small text-center mt-3">
        PWA 简易版 · 适合手机快速同步
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  const toastArea = document.getElementById('toastArea');
  const btnCopyFromCloud = document.getElementById('btnCopyFromCloud');
  const btnUpdateCloud   = document.getElementById('btnUpdateCloud');
  const form      = document.getElementById('hiddenForm');
  const typeField = document.getElementById('content_type');
  const mimeField = document.getElementById('mime_type');
  const textField = document.getElementById('clipboard_text');

  const currentType = <?php echo json_encode($currentType); ?>;
  const currentText = <?php echo json_encode($currentType === 'text' ? $currentContent : ''); ?>;

  function showToast(message, type) {
    toastArea.style.display = '';
    toastArea.innerHTML = `
      <div class="alert alert-${type || 'info'} mb-0" role="alert" style="padding:.6rem .75rem;">
        <div class="d-flex align-items-center justify-content-between">
          <div class="small">${escapeHtml(message)}</div>
          <button type="button" class="btn btn-sm btn-ghost-secondary" id="toastClose">✕</button>
        </div>
      </div>
    `;
    const closeBtn = document.getElementById('toastClose');
    if (closeBtn) closeBtn.addEventListener('click', () => { toastArea.style.display = 'none'; });
    
    window.clearTimeout(showToast._t);
    showToast._t = window.setTimeout(() => { toastArea.style.display = 'none'; }, 2500);
  }

  function escapeHtml(str) {
    return String(str).replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
  }

  async function tryReadImageFromClipboard() {
    if (!navigator.clipboard || !navigator.clipboard.read) return null;
    try {
      const items = await navigator.clipboard.read();
      for (const item of items) {
        const imgType = item.types.find(t => t.startsWith('image/'));
        if (imgType) {
          const blob = await item.getType(imgType);
          return new Promise((resolve) => {
            const reader = new FileReader();
            reader.onload = (e) => resolve({ base64: e.target.result.split(',')[1], mime: imgType });
            reader.readAsDataURL(blob);
          });
        }
      }
    } catch (e) { console.warn(e); }
    return null;
  }

  if (btnCopyFromCloud) {
    btnCopyFromCloud.addEventListener('click', async () => {
      if (currentType !== 'text' || !currentText) {
        showToast('当前非文本内容，无法复制。', 'danger');
        return;
      }
      try {
        await navigator.clipboard.writeText(currentText);
        showToast('已成功复制到本机 ✅', 'success');
      } catch (e) {
        showToast('复制失败，请检查浏览器权限。', 'danger');
      }
    });
  }

  if (btnUpdateCloud && form) {
    btnUpdateCloud.addEventListener('click', async () => {
      showToast('正在读取剪切板...', 'info');
      
      const img = await tryReadImageFromClipboard();
      if (img) {
        typeField.value = 'image';
        mimeField.value = img.mime;
        textField.value = img.base64;
        form.submit();
        return;
      }

      try {
        const text = await navigator.clipboard.readText();
        if (text) {
          typeField.value = 'text';
          mimeField.value = 'text/plain';
          textField.value = text;
          form.submit();
        } else {
          showToast('剪切板中没有可读取的内容。', 'danger');
        }
      } catch (e) {
        showToast('读取失败，请点击页面后再试（需用户触发）。', 'danger');
      }
    });
  }
})();
</script>
</body>
</html>