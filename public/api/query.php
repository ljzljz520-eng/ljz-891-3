<?php
/**
 * 客户押金查询接口（防订单号枚举的核心）：
 * 1. 必须同时提供：订单号 + 手机号 + 算术验证码；
 * 2. 订单号不连续、不可猜；手机号经 HMAC 哈希后恒定时间比较；
 * 3. 订单不存在时也执行一次假哈希比较，消除时序差异；
 * 4. 错误次数按 IP 与按订单双维度限流（5 次 / 15 分钟），不区分“订单不存在/手机号错误”；
 * 5. 成功不返回任何客户不需要的内部信息；所有尝试写入查询日志。
 */
require_once __DIR__ . '/../../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail('method_not_allowed', '请求方法不允许', 405);
}

$pdo = DB::pdo();
$rl = new RateLimiter($pdo);
$captcha = new Captcha($pdo);
$ip = client_ip();

$in = request_input();
$orderNo = strtoupper(input($in, 'order_no'));
$phone = normalize_phone(input($in, 'phone'));
$captchaId = input($in, 'captcha_id');
$captchaAnswer = input($in, 'captcha_answer');

$log = fn(string $result, string $code) => $pdo->prepare(
    'INSERT INTO query_logs (ip, order_no, phone_last4, result, code, user_agent)
     VALUES (?,?,?,?,?,?)'
)->execute([
    $ip,
    mb_substr($orderNo, 0, 32),
    mb_substr($phone, -4),
    $result,
    $code,
    mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
]);

$ipBucket = 'query-fail-ip:' . $ip;

// 先检查是否已处于锁定状态
if ($rl->count($ipBucket, QUERY_LOCK_WINDOW) >= QUERY_LOCK_MAX_FAIL) {
    $retry = $rl->retryAfter($ipBucket, QUERY_LOCK_WINDOW);
    $log('locked', 'ip_locked');
    fail('locked', '尝试次数过多，为保护账户安全请 ' . ceil($retry / 60) . ' 分钟后再试', 429, [
        'retry_after' => $retry,
    ]);
}

// 参数格式校验（不区分提示口径，避免暴露哪个字段有误）
if (!is_valid_order_no($orderNo) || !is_valid_phone($phone)) {
    $n = $rl->bump($ipBucket);
    $log('fail', 'invalid_format');
    if ($n >= QUERY_LOCK_MAX_FAIL) {
        fail('locked', '尝试次数过多，为保护账户安全请 15 分钟后再试', 429, ['retry_after' => QUERY_LOCK_WINDOW]);
    }
    fail('invalid', '订单号或手机号格式不正确，请核对后重新输入', 400);
}

// 验证码校验（一次性，失败也计数）
if (!$captcha->verify($captchaId, $captchaAnswer, $ip)) {
    $n = $rl->bump($ipBucket);
    $log('fail', 'bad_captcha');
    if ($n >= QUERY_LOCK_MAX_FAIL) {
        fail('locked', '尝试次数过多，为保护账户安全请 15 分钟后再试', 429, ['retry_after' => QUERY_LOCK_WINDOW]);
    }
    fail('bad_captcha', '验证码错误或已过期，请更换后重试', 400);
}

// 按订单维度的锁定（防止针对某订单逐个手机号爆破；不依赖 IP，因换 IP 同样受限）
$orderBucket = 'query-fail-order:' . $orderNo;
if ($rl->count($orderBucket, QUERY_LOCK_WINDOW) >= QUERY_LOCK_MAX_FAIL) {
    $retry = $rl->retryAfter($orderBucket, QUERY_LOCK_WINDOW);
    $log('locked', 'order_locked');
    fail('locked', '该订单尝试次数过多，请 ' . ceil($retry / 60) . ' 分钟后再试', 429, [
        'retry_after' => $retry,
    ]);
}

// 查找订单
$stmt = $pdo->prepare('SELECT o.*, c.name AS customer_name, c.phone_hash
    FROM orders o JOIN customers c ON c.id = o.customer_id
    WHERE o.order_no = ?');
$stmt->execute([$orderNo]);
$order = $stmt->fetch();

// 订单不存在：执行一次等价的哈希比较，保持响应时间与“手机号错误”接近
$phoneHash = hash_hmac('sha256', $phone, DB::key());
$storedHash = $order['phone_hash'] ?? hash_hmac('sha256', '__nonexistent__' . random_bytes(8), DB::key());
$phoneMatched = $order && hash_equals($order['phone_hash'], $phoneHash);

if (!$order || !$phoneMatched) {
    $n = $rl->bump($ipBucket);
    $rl->hit($orderBucket);
    $log('fail', 'mismatch');
    if ($n >= QUERY_LOCK_MAX_FAIL) {
        fail('locked', '尝试次数过多，为保护账户安全请 15 分钟后再试', 429, ['retry_after' => QUERY_LOCK_WINDOW]);
    }
    // 统一、笼统的错误信息：不告诉调用方是订单不存在还是手机号不对
    fail('not_found', '订单号与手机号不匹配，请确认后重新输入（连续错误 5 次将临时锁定）', 404);
}

// 成功：清除失败计数
$rl->reset($ipBucket, QUERY_LOCK_WINDOW);
$rl->reset($orderBucket, QUERY_LOCK_WINDOW);

$items = $pdo->prepare('SELECT name, model, qty, unit_price FROM order_items WHERE order_id = ? ORDER BY id');
$items->execute([$order['id']]);
$equipment = array_map(static function (array $it): array {
    return [
        'name' => $it['name'],
        'model' => $it['model'],
        'qty' => (int)$it['qty'],
        'daily_rent' => fen_to_yuan((int)$it['unit_price']),
    ];
}, $items->fetchAll());

// 面向客户的最小必要信息（不返回客户姓名、手机号、异常内部备注、扣款明细等）
$data = [
    'order_no' => $order['order_no'],
    'deposit_amount' => fen_to_yuan((int)$order['deposit_amount']),
    'deposit_status' => $order['deposit_status'],
    'deposit_status_text' => DEPOSIT_STATUS_MAP[$order['deposit_status']] ?? $order['deposit_status'],
    'rental_status' => $order['rental_status'],
    'rental_status_text' => RENTAL_STATUS_MAP[$order['rental_status']] ?? $order['rental_status'],
    'equipment' => $equipment,
    'rental_period' => [
        'start' => $order['rental_start'],
        'end' => $order['return_time'] ?: $order['rental_end'],
    ],
    'return_time' => $order['return_time'],
    'refund_time' => $order['refund_time'],
    'refund_note' => $order['deposit_status'] === 'refunded'
        ? '押金已按原支付路径退回，通常 1-3 个工作日到账，具体以银行/支付平台为准。'
        : ($order['deposit_status'] === 'refunding'
            ? '设备验收无误后，押金将于 3 个工作日内原路退回。'
            : '设备归还并验收无误后，押金将于 3 个工作日内原路退回。'),
    'notice' => $order['customer_note'] !== '' ? $order['customer_note'] : null,
];

$log('success', 'ok');
ok($data, '查询成功');
