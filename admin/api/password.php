<?php
declare(strict_types=1);
/**
 * 修改自己的登录密码 POST {"old_password":"","new_password":""}
 */
require __DIR__ . '/../../lib/bootstrap.php';
$admin = require_admin();
require_csrf();

$in = body_json();
$old = (string)($in['old_password'] ?? '');
$new = (string)($in['new_password'] ?? '');

if (strlen($new) < 10) {
    json_out(['ok' => false, 'error' => '新密码至少 10 位'], 422);
}
if (!preg_match('/[a-zA-Z]/', $new) || !preg_match('/\d/', $new)) {
    json_out(['ok' => false, 'error' => '新密码需同时包含字母和数字'], 422);
}

$pdo = db();
$st = $pdo->prepare('SELECT password_hash FROM admins WHERE id = ?');
$st->execute([$admin['id']]);
$hash = $st->fetchColumn();
if (!password_verify($old, (string)$hash)) {
    json_out(['ok' => false, 'error' => '原密码错误'], 401);
}

$pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
    ->execute([password_hash($new, PASSWORD_BCRYPT), $admin['id']]);
log_query($pdo, '', 'ok', 'change_password', '', $admin['id']);
json_out(['ok' => true]);
