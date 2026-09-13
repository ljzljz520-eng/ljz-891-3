<?php
declare(strict_types=1);

/**
 * 后台登录  POST /admin/api/login.php  {"username":"...","password":"..."}
 */
require __DIR__ . '/../../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => '仅支持 POST'], 405);
}

session_start_secure();
$in = body_json();
$username = trim((string)($in['username'] ?? ''));
$password = (string)($in['password'] ?? '');

if ($username === '' || $password === '') {
    json_out(['ok' => false, 'error' => '请输入账号和密码'], 400);
}

$pdo = db();

if ($msg = login_rate_check($pdo, $username)) {
    log_query($pdo, '', 'rate_locked', 'login_locked', "user=$username");
    json_out(['ok' => false, 'error' => $msg], 429);
}

$st = $pdo->prepare('SELECT * FROM admins WHERE username = ?');
$st->execute([$username]);
$admin = $st->fetch();

$ok = $admin && password_verify($password, $admin['password_hash']);

// 记录登录审计
$pdo->prepare('INSERT INTO login_attempts (ip, username, success, created_at) VALUES (?,?,?,' . nowExpr() . ')')
    ->execute([client_ip(), $username, $ok ? 1 : 0]);

if (!$ok) {
    login_record_fail($pdo, $username);
    log_query($pdo, '', 'error', 'login_fail', "user=$username");
    // 固定文案，防止用户名枚举
    json_out(['ok' => false, 'error' => '账号或密码错误'], 401);
}

if (password_needs_rehash($admin['password_hash'], PASSWORD_BCRYPT)) {
    $pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
        ->execute([password_hash($password, PASSWORD_BCRYPT), $admin['id']]);
}

login_clear_fails($pdo, $username);
session_regenerate_id(true);
$_SESSION['admin_id']   = (int)$admin['id'];
$_SESSION['admin_name'] = $admin['username'];
$csrf = csrf_token();

log_query($pdo, '', 'ok', 'login_ok', "user=$username", (int)$admin['id']);

json_out(['ok' => true, 'csrf' => $csrf, 'username' => $admin['username']]);
