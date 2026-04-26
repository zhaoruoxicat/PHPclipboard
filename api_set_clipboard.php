<?php
// api_set_clipboard.php — 安卓等客户端上传剪切板内容到云端
// 认证方式：仅 Token（api_tokens.token）
// 输出：JSON

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// 禁止缓存
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

date_default_timezone_set('Asia/Shanghai');

require __DIR__ . '/db.php'; // 只需要数据库连接

/**
 * 统一输出 JSON 并结束。
 */
function json_response(int $httpCode, array $data): void
{
    http_response_code($httpCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * 获取真实客户端 IP。
 */
function get_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        return $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    }
    return '0.0.0.0';
}

// 仅允许 POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(405, [
        'success' => false,
        'error'   => 'method_not_allowed',
        'message' => '请使用 POST 请求此接口。',
    ]);
}

// ========== 1. 读取 Token ==========

$token = '';
if (!empty($_SERVER['HTTP_X_API_TOKEN'])) {
    $token = trim((string)$_SERVER['HTTP_X_API_TOKEN']);
} elseif (isset($_GET['token'])) {
    $token = trim((string)$_GET['token']);
}

if ($token === '') {
    json_response(401, [
        'success' => false,
        'error'   => 'missing_token',
        'message' => '请求中缺少 token。',
    ]);
}

// ========== 2. 验证 Token ==========

try {
    $stmt = $pdo->prepare("
        SELECT *
        FROM api_tokens
        WHERE token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $apiToken = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    json_response(500, [
        'success' => false,
        'error'   => 'db_error',
        'message' => '读取 token 信息失败。',
    ]);
}

if (!$apiToken) {
    json_response(401, [
        'success' => false,
        'error'   => 'invalid_token',
        'message' => '提供的 token 无效。',
    ]);
}

if ((int)$apiToken['is_active'] !== 1) {
    json_response(403, [
        'success' => false,
        'error'   => 'token_inactive',
        'message' => '该 token 已被停用。',
    ]);
}

// 检查是否过期 (使用 PHP 处理，兼容性更好)
if (!empty($apiToken['expires_at'])) {
    $now = time();
    $exp = strtotime((string)$apiToken['expires_at']);
    if ($exp > 0 && $now > $exp) {
        json_response(403, [
            'success' => false,
            'error'   => 'token_expired',
            'message' => '该 token 已过期。',
        ]);
    }
}

$clientIp = get_client_ip();

// ========== 3. 读取并校验上传内容 ==========

$contentType = trim((string)($_POST['content_type'] ?? ''));
$content     = trim((string)($_POST['content'] ?? ''));
$mimeType    = isset($_POST['mime_type']) ? trim((string)$_POST['mime_type']) : null;

if ($contentType === '' || $content === '') {
    json_response(400, [
        'success' => false,
        'error'   => 'missing_params',
        'message' => '缺少必要的内容或类型参数。',
    ]);
}

if ($contentType !== 'text' && $contentType !== 'image') {
    json_response(400, [
        'success' => false,
        'error'   => 'invalid_content_type',
        'message' => 'content_type 仅支持 text 或 image。',
    ]);
}

if ($contentType === 'text') {
    if (mb_strlen($content) > 5000) {
        json_response(400, [
            'success' => false,
            'error'   => 'text_too_long',
            'message' => '文本不能超过 5000 字。',
        ]);
    }
    $mimeType = 'text/plain';
} else {
    if ($mimeType === null || $mimeType === '') {
        $mimeType = 'image/png';
    }
    if (!preg_match('/^[A-Za-z0-9+\/=]+$/', $content)) {
        json_response(400, [
            'success' => false,
            'error'   => 'invalid_base64',
            'message' => '图片 Base64 格式不正确。',
        ]);
    }
    if (strlen($content) > 2 * 1024 * 1024) {
        json_response(400, [
            'success' => false,
            'error'   => 'image_too_large',
            'message' => '图片不能超过 2MB。',
        ]);
    }
}

// ========== 4. 写入 cloud_clipboard (SQLite 适配) ==========

try {
    // 将 NOW() 替换为 SQLite 兼容函数
    $stmt = $pdo->prepare("
        UPDATE cloud_clipboard
        SET content_type = :ctype,
            content      = :content,
            mime_type    = :mime,
            updated_at   = datetime('now', 'localtime')
        WHERE id = 1
    ");
    $stmt->execute([
        ':ctype'  => $contentType,
        ':content'=> $content,
        ':mime'   => $mimeType,
    ]);
} catch (Throwable $e) {
    json_response(500, [
        'success' => false,
        'error'   => 'db_error',
        'message' => '写入失败。',
    ]);
}

// ========== 5. 更新 Token 使用统计 (SQLite 适配) ==========

try {
    $stmtUpdate = $pdo->prepare("
        UPDATE api_tokens
        SET usage_count  = usage_count + 1,
            last_used_at = datetime('now', 'localtime'),
            last_used_ip = :ip
        WHERE id = :id
    ");
    $stmtUpdate->execute([
        ':ip' => $clientIp,
        ':id' => (int)$apiToken['id'],
    ]);
} catch (Throwable $e) {
    // 统计失败不报错
}

// ========== 6. 输出结果 ==========

json_response(200, [
    'success'      => true,
    'message'      => '云剪切板已更新。',
    'content_type' => $contentType,
    'updated_at'   => date('Y-m-d H:i:s'),
    'server_time'  => date('Y-m-d H:i:s'),
    'token_name'   => (string)$apiToken['name'],
]);