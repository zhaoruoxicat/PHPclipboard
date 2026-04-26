<?php
// index.php — 登录后的首页 + 云剪切板操作
declare(strict_types=1);

require __DIR__ . '/auth.php'; // 会校验登录并提供 $authUser
require __DIR__ . '/db.php';   // 数据库连接（已切换为 SQLite）

// =======================
// 配置项（可在此自定义限制）
// =======================
$maxImageSizeMB = 10;      // 图片最大限制 (MB)
$maxTextLength  = 10000;  // 文本最大字符数限制

$displayName = $authUser['display_name'] ?? $authUser['username'] ?? '用户';
$errors  = [];
$success = '';

// ========== 处理表单提交：保存内容到云剪切板 ==========
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
            // 校验 Base64 格式
            if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $rawContent)) {
                $errors[] = '图片内容格式非法。';
            }
            // 自定义大小校验
            $maxBytes = $maxImageSizeMB * 1024 * 1024;
            if (strlen($rawContent) > $maxBytes) {
                $errors[] = "图片过大，Base64 长度超过了 {$maxImageSizeMB}MB 的限制。";
            }
        }
    } else {
        // 文本模式校验
        if ($rawContent === '') {
            $errors[] = '内容不能为空。';
        } elseif (mb_strlen($rawContent) > $maxTextLength) {
            $errors[] = "内容过长，请控制在 {$maxTextLength} 字以内。";
        }
        $mimeType = 'text/plain';
    }

    if (!$errors) {
        try {
            // 使用 SQLite 兼容的日期函数 datetime('now', 'localtime')
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

            $success = '云剪切板已更新。';
        } catch (Throwable $e) {
            $errors[] = '保存失败：' . $e->getMessage();
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
        $currentMime    = $row['mime_type'] ?: 'image/png';
        $currentUpdated = $row['updated_at'];
    }
} catch (Throwable $e) {
    $errors[] = '读取失败：' . $e->getMessage();
}

$initialTextarea = ($currentType === 'text') ? $currentContent : '';
?>
<!doctype html>
<html lang="zh-CN">
  <head>
    <meta charset="utf-8"/>
    <title>云剪切板 - 首页</title>
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"/>
    <meta name="color-scheme" content="light dark"/>
    <link href="/style/tabler.min.css" rel="stylesheet"/>
    <script src="/style/tabler.min.js" defer></script>
    <link rel="manifest" href="/manifest.webmanifest">
  </head>
  <body>
    <div class="page">
      <?php require __DIR__ . '/navbar.php'; ?>

      <div class="page-body">
        <div class="container-xl">
          <div class="page-header d-print-none">
            <div class="row align-items-center">
              <div class="col">
                <h2 class="page-title">云剪切板</h2>
                <div class="text-muted mt-1">
                    欢迎，<?php echo htmlspecialchars($displayName, ENT_QUOTES, 'UTF-8'); ?>。
                    (图片限制: <?php echo $maxImageSizeMB; ?>MB)
                </div>
              </div>
            </div>
          </div>

          <?php if ($errors): ?>
            <div class="alert alert-danger mt-3">
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
                <div class="card-header"><h3 class="card-title">当前内容</h3></div>
                <div class="card-body">
                  <div class="mb-2">
                    <span class="badge bg-primary-lt">类型：<?php echo $currentType === 'image' ? '图片' : '文本'; ?></span>

                  </div>

                  <?php if ($currentContent === ''): ?>
                    <div class="text-muted">暂无内容。</div>
                  <?php else: ?>
                    <?php if ($currentType === 'text'): ?>
                      <pre class="mb-3" style="white-space: pre-wrap; word-break: break-all; max-height: 400px; overflow:auto;"><?php echo htmlspecialchars($currentContent, ENT_QUOTES, 'UTF-8'); ?></pre>
                      <button type="button" class="btn btn-outline-primary" id="btnCopyFromCloud">复制到本机剪切板</button>
                    <?php elseif ($currentType === 'image'): ?>
                      <div class="mb-2">
                        <img src="data:<?php echo htmlspecialchars($currentMime, ENT_QUOTES, 'UTF-8'); ?>;base64,<?php echo $currentContent; ?>" 
                             style="max-width:100%; max-height:400px; object-fit:contain; border:1px solid #ddd;"/>
                      </div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="col-md-6">
              <div class="card">
                <div class="card-header"><h3 class="card-title">更新云剪切板</h3></div>
                <div class="card-body">
                  <form method="post" id="clipboardForm">
                    <input type="hidden" name="content_type" id="content_type" value="text">
                    <input type="hidden" name="mime_type" id="mime_type" value="text/plain">
                    <div class="mb-3">
                      <textarea name="clipboard_text" id="clipboard_text" class="form-control" rows="10" placeholder="在此粘贴文本或图片..."><?php echo htmlspecialchars($initialTextarea, ENT_QUOTES, 'UTF-8'); ?></textarea>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                      <button type="button" class="btn btn-outline-secondary" id="btnPasteFromClipboard">从系统剪切板读取并保存</button>
                      <button type="submit" class="btn btn-primary">手动保存文本</button>
                    </div>
                  </form>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script>
      (function () {
        const textarea = document.getElementById('clipboard_text');
        const btnPaste = document.getElementById('btnPasteFromClipboard');
        const btnCopy  = document.getElementById('btnCopyFromCloud');
        const form     = document.getElementById('clipboardForm');
        const typeF    = document.getElementById('content_type');
        const mimeF    = document.getElementById('mime_type');

        // 粘贴图片处理
        textarea.addEventListener('paste', function (e) {
          const items = (e.clipboardData || e.originalEvent.clipboardData).items;
          for (let item of items) {
            if (item.type.indexOf('image') === 0) {
              e.preventDefault();
              const blob = item.getAsFile();
              const reader = new FileReader();
              reader.onload = function (event) {
                const base64 = event.target.result.split(',')[1];
                textarea.value = base64;
                typeF.value = 'image';
                mimeF.value = item.type;
                // 粘贴图片后自动提交以方便同步
                form.submit();
              };
              reader.readAsDataURL(blob);
              break;
            }
          }
        });

        // 按钮读取剪切板
        if (btnPaste) {
          btnPaste.addEventListener('click', async () => {
            try {
              const items = await navigator.clipboard.read();
              for (let item of items) {
                const imageType = item.types.find(type => type.startsWith('image/'));
                if (imageType) {
                  const blob = await item.getType(imageType);
                  const reader = new FileReader();
                  reader.onload = (e) => {
                    textarea.value = e.target.result.split(',')[1];
                    typeF.value = 'image';
                    mimeF.value = imageType;
                    form.submit();
                  };
                  reader.readAsDataURL(blob);
                  return;
                }
              }
              // 回退文本
              const text = await navigator.clipboard.readText();
              if (text) {
                textarea.value = text;
                typeF.value = 'text';
                mimeF.value = 'text/plain';
                form.submit();
              }
            } catch (err) {
              alert('无法访问剪切板，请确保使用 HTTPS 访问并授予权限。');
            }
          });
        }

        // 复制云端文本到本机
        if (btnCopy) {
          btnCopy.addEventListener('click', async () => {
            const text = textarea.value; 
            if (text && navigator.clipboard) {
              await navigator.clipboard.writeText(text);
              alert('已复制到本机！');
            }
          });
        }
      })();
    </script>
  </body>
</html>