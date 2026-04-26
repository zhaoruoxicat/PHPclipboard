<?php
// api_get_clipboard.php — 云剪切板数据读取 API（安卓等客户端使用）
// 认证方式：仅 Token（api_tokens.token）
// 输出：JSON

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// 禁止浏览器缓存
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
 * 尽量获取真实客户端 IP。
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
        'message' => '数据库查询失败。',
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

// 检查是否过期
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

// ========== 3. 读取云剪切板内容 ==========

try {
    $stmt = $pdo->prepare("
        SELECT content_type, content, mime_type, updated_at
        FROM cloud_clipboard
        WHERE id = 1
        LIMIT 1
    ");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    json_response(500, [
        'success' => false,
        'error'   => 'db_error',
        'message' => '读取内容失败。',
    ]);
}

if (!$row) {
    json_response(200, [
        'success'      => true,
        'content_type' => 'text',
        'mime_type'    => 'text/plain',
        'content'      => '',
        'updated_at'   => null,
        'server_time'  => date('Y-m-d H:i:s'),
    ]);
}

$contentType = (string)$row['content_type'];
$content     = (string)$row['content'];
$mimeType    = $row['mime_type'] !== null ? (string)$row['mime_type'] : null;
$updatedAt   = $row['updated_at'] !== null ? (string)$row['updated_at'] : null;

// ========== 4. 更新 api_tokens 使用统计 (SQLite 适配) ==========

try {
    // 将 MySQL 的 NOW() 改为 SQLite 的 datetime 函数
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
    // 忽略统计更新错误
}

// ========== 5. 输出 JSON ==========

json_response(200, [
    'success'      => true,
    'content_type' => $contentType,
    'mime_type'    => $mimeType,
    'content'      => $content,
    'updated_at'   => $updatedAt,
    'server_time'  => date('Y-m-d H:i:s'),
    'token_name'   => (string)$apiToken['name'],
]);