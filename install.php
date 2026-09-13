<?php
declare(strict_types=1);
/**
 * 一次性安装脚本：
 *   php install.php            # 用 lib/config.php 的 driver 配置建库
 * 跑完请删除本文件或移出 web 目录。
 */
$GLOBALS['CFG'] = require __DIR__ . '/lib/config.php';
require __DIR__ . '/lib/db.php';

$driver = $GLOBALS['CFG']['db']['driver'];

if ($driver === 'mysql') {
    // MySQL 直接执行官方 schema，并把默认管理员密码改成真实 hash
    $sql = file_get_contents(__DIR__ . '/sql/schema.mysql.sql');
    $pdo = db();
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '' || str_starts_with($stmt, '--')) {
            continue;
        }
        $pdo->exec($stmt);
    }
    $a = $GLOBALS['CFG']['bootstrap_admin'];
    $chk = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
    $chk->execute([$a['user']]);
    if ((int)$chk->fetchColumn() === 0) {
        $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?,?)')
            ->execute([$a['user'], password_hash($a['pass'], PASSWORD_BCRYPT)]);
    }
    echo "MySQL 安装完成。管理员 {$a['user']} / {$a['pass']}（不存在时才创建，请立即改密）\n";
    exit(0);
}

/* ---------------- SQLite ---------------- */
$pdo = db();
$pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS admins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE TABLE IF NOT EXISTS rentals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_no TEXT NOT NULL UNIQUE,
  customer_name TEXT NOT NULL,
  phone_enc TEXT NOT NULL,
  phone_hash TEXT NOT NULL,
  deposit_amount REAL NOT NULL,
  deposit_status TEXT NOT NULL DEFAULT 'held'
    CHECK(deposit_status IN ('held','refunding','refunded','deducted','abnormal')),
  rent_start TEXT NOT NULL,
  rent_due TEXT NOT NULL,
  returned_at TEXT,
  refund_time TEXT,
  abnormal_flag INTEGER NOT NULL DEFAULT 0,
  abnormal_reason TEXT NOT NULL DEFAULT '',
  remark TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
  updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_phone_hash ON rentals(phone_hash);
CREATE INDEX IF NOT EXISTS idx_status ON rentals(deposit_status);
CREATE INDEX IF NOT EXISTS idx_abnormal ON rentals(abnormal_flag);
CREATE TABLE IF NOT EXISTS rental_items (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  rental_id INTEGER NOT NULL REFERENCES rentals(id) ON DELETE CASCADE,
  name TEXT NOT NULL,
  model TEXT NOT NULL DEFAULT '',
  sn TEXT NOT NULL DEFAULT '',
  qty INTEGER NOT NULL DEFAULT 1,
  unit_price REAL NOT NULL DEFAULT 0
);
CREATE INDEX IF NOT EXISTS idx_items_rental ON rental_items(rental_id);
CREATE TABLE IF NOT EXISTS query_logs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  order_no TEXT NOT NULL DEFAULT '',
  result TEXT NOT NULL,
  ip TEXT NOT NULL,
  ua TEXT NOT NULL DEFAULT '',
  admin_id INTEGER,
  action TEXT NOT NULL DEFAULT 'customer_query',
  detail TEXT NOT NULL DEFAULT '',
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_log_order ON query_logs(order_no);
CREATE INDEX IF NOT EXISTS idx_log_result ON query_logs(result);
CREATE INDEX IF NOT EXISTS idx_log_time ON query_logs(created_at);
CREATE TABLE IF NOT EXISTS login_attempts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ip TEXT NOT NULL,
  username TEXT NOT NULL DEFAULT '',
  success INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
);
CREATE INDEX IF NOT EXISTS idx_la_ip ON login_attempts(ip);
CREATE TABLE IF NOT EXISTS rate_limits (
  bucket TEXT NOT NULL,
  expires_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_rl_bucket ON rate_limits(bucket);
CREATE INDEX IF NOT EXISTS idx_rl_exp ON rate_limits(expires_at);
SQL);

$a = $GLOBALS['CFG']['bootstrap_admin'];
$chk = $pdo->prepare('SELECT COUNT(*) FROM admins WHERE username = ?');
$chk->execute([$a['user']]);
if ((int)$chk->fetchColumn() === 0) {
    $ins = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (?,?)');
    $ins->execute([$a['user'], password_hash($a['pass'], PASSWORD_BCRYPT)]);
}

echo "SQLite 安装完成: " . $GLOBALS['CFG']['db']['sqlite_path'] . "\n";
echo "管理员 {$a['user']} / {$a['pass']}（请立即修改！）\n";
