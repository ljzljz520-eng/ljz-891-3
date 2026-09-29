<?php
require_once __DIR__ . '/../../../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail('method_not_allowed', '请求方法不允许', 405);
}
$auth = new AdminAuth(DB::pdo());
$session = $auth->check();
if ($session) {
    DB::pdo()->prepare('INSERT INTO query_logs (ip, result, code, user_agent, admin_id) VALUES (?,?,?,?,?)')
        ->execute([client_ip(), 'logout', 'ok', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $session['admin_id']]);
}
$auth->logout();
ok([], '已退出登录');
