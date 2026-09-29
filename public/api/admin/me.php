<?php
require_once __DIR__ . '/../../../lib/bootstrap.php';

$session = (new AdminAuth(DB::pdo()))->check();
if (!$session) {
    fail('unauthorized', '未登录或会话已过期', 401);
}
ok([
    'username' => $session['username'],
    'csrf_token' => $session['csrf'],
]);
