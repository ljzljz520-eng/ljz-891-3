<?php
/**
 * 后台登录：账密 + 图形算术验证码 + IP 维度失败限流；
 * 成功后下发 HttpOnly + SameSite=Strict 会话 Cookie，并返回 CSRF 令牌。
 */
require_once __DIR__ . '/../../../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail('method_not_allowed', '请求方法不允许', 405);
}

$pdo = DB::pdo();
$rl = new RateLimiter($pdo);
$captcha = new Captcha($pdo);
$ip = client_ip();

$in = request_input();
$username = input($in, 'username');
$password = input($in, 'password');
$captchaId = input($in, 'captcha_id');
$captchaAnswer = input($in, 'captcha_answer');

$bucket = 'login-fail:' . $ip . ':' . mb_substr($username, 0, 64);
$ipBucket = 'login-fail-ip:' . $ip;

if ($rl->count($ipBucket, LOGIN_WINDOW) >= LOGIN_MAX_FAIL) {
    $retry = $rl->retryAfter($ipBucket, LOGIN_WINDOW);
    fail('locked', '登录失败次数过多，请 ' . ceil($retry / 60) . ' 分钟后再试', 429, ['retry_after' => $retry]);
}

if ($username === '' || $password === '' || !$captcha->verify($captchaId, $captchaAnswer, $ip)) {
    $rl->bump($ipBucket);
    $rl->hit($bucket);
    fail('invalid', '验证码错误、已过期，或账号密码为空', 400);
}

$auth = new AdminAuth($pdo);
$result = $auth->login($username, $password);
if (!$result) {
    $n = $rl->bump($ipBucket);
    $rl->hit($bucket);
    $pdo->prepare('INSERT INTO query_logs (ip, order_no, phone_last4, result, code, user_agent, admin_id)
        VALUES (?,?,?,?,?,?,NULL)')
        ->execute([$ip, '', '', 'login_fail', 'bad_credentials', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255)]);
    $left = max(0, LOGIN_MAX_FAIL - $n);
    if ($n >= LOGIN_MAX_FAIL) {
        fail('locked', '登录失败次数过多，请 15 分钟后再试', 429, ['retry_after' => LOGIN_WINDOW]);
    }
    fail('unauthorized', '账号或密码错误' . ($left > 0 ? "，剩余尝试次数 {$left} 次" : ''), 401);
}

[$sid, $csrf, $adminId] = $result;
AdminAuth::setSessionCookie($sid);
$rl->reset($ipBucket, LOGIN_WINDOW);
$rl->reset($bucket, LOGIN_WINDOW);

$pdo->prepare('INSERT INTO query_logs (ip, result, code, user_agent, admin_id) VALUES (?,?,?,?,?)')
    ->execute([$ip, 'login_success', 'ok', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $adminId]);

ok(['username' => $username, 'csrf_token' => $csrf], '登录成功');
