<?php
// db.php — 数据库连接配置 (SQLite 版)
declare(strict_types=1);

// =======================
// 数据库配置
// =======================
// 指定 SQLite 数据库文件的路径
// __DIR__ 表示当前文件所在目录，这里将数据库命名为 data.db
$sqlitePath = __DIR__ . '/data.db';

// =======================
// 创建 PDO 实例
// =======================
// SQLite 的 DSN 格式非常简单：sqlite:文件路径
$dsn = "sqlite:{$sqlitePath}";

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,      // 以异常方式报告错误
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,            // 默认返回关联数组
    PDO::ATTR_EMULATE_PREPARES   => false,                       // 禁用模拟预处理
];

try {
    $pdo = new PDO($dsn, null, null, $options);
    
    // 启用 SQLite 的外键约束（可选，建议开启）
    $pdo->exec('PRAGMA foreign_keys = ON;');
    
} catch (Throwable $e) {
    // 如果数据库连接失败（例如权限问题），直接停止运行
    exit('数据库连接失败：' . $e->getMessage());
}