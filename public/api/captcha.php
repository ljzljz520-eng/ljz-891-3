<?php
require_once __DIR__ . '/../../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail('method_not_allowed', '请求方法不允许', 405);
}

$ip = client_ip();
$rl = new RateLimiter(DB::pdo());

// 验证码接口本身也要限流，防止被刷
$bucket = 'captcha:' . $ip;
if ($rl->count($bucket, 60) >= CAPTCHA_LIMIT_MAX) {
    fail('rate_limited', '操作过于频繁，请稍后再试', 429);
}
$rl->hit($bucket);

$captcha = new Captcha(DB::pdo());
$c = $captcha->issue($ip);
ok(['captcha_id' => $c['id'], 'question' => $c['question'], 'ttl' => CAPTCHA_TTL]);
