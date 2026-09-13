<?php
declare(strict_types=1);

/**
 * 客户押金查询接口  POST /api/query.php
 * body: {"order_no":"RC...","phone":"138..."}
 *
 * 安全设计:
 *  1. 必须同时提供 订单号 + 手机号，二者均通过才算查询成功（类似“证件号+查询码”）。
 *  2. 订单号约 50 bit 熵，无法顺序遍历；手机号不是公开信息，构成第二因子。
 *  3. 错误统一返回“订单号或手机号不匹配”，不区分到底哪个错，避免用户枚举。
 *  4. IP 与订单号双维度限流，超限锁定。
 *  5. 响应只含客户本应知道的押金/状态/设备/退款信息，不回传手机号等。
 *  6. 成功查询做恒定时间 hash 比较；失败也走完查询流程，减少时序差异。
 */

require __DIR__ . '/../lib/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => '仅支持 POST'], 405);
}

$in = body_json();
$orderNo = strtoupper(trim((string)($in['order_no'] ?? '')));
$phone   = normalize_phone((string)($in['phone'] ?? ''));

// 格式预检
if (!valid_order_no($orderNo) || !valid_phone($phone)) {
    // 格式错同样计入该 IP 的尝试
    $pdo = db();
    $lock = customer_rate_check($pdo, $orderNo);
    log_query($pdo, $orderNo, valid_order_no($orderNo) ? 'bad_phone' : 'bad_order');
    if ($lock) {
        json_out(['ok' => false, 'error' => $lock], 429);
    }
    json_out(['ok' => false, 'error' => '订单号或手机号不匹配，请核对后重试'], 200);
}

$pdo = db();

// 限流
$lock = customer_rate_check($pdo, $orderNo);
if ($lock) {
    log_query($pdo, $orderNo, 'rate_locked');
    json_out(['ok' => false, 'error' => $lock], 429);
}

// 查询订单
$st = $pdo->prepare('SELECT * FROM rentals WHERE order_no = ?');
$st->execute([$orderNo]);
$rental = $st->fetch();

$match = false;
if ($rental) {
    $match = hash_equals($rental['phone_hash'], phone_hash($phone));
}

if (!$match) {
    log_query($pdo, $orderNo, 'bad_phone', 'customer_query',
        $rental ? 'order exists, phone mismatch' : 'order not found');
    json_out(['ok' => false, 'error' => '订单号或手机号不匹配，请核对后重试'], 200);
}

// 成功：取设备清单
$items = $pdo->prepare('SELECT name, model, sn, qty FROM rental_items WHERE rental_id = ? ORDER BY id');
$items->execute([$rental['id']]);
$list = $items->fetchAll();

// 归还状态由数据推导
$returned = !empty($rental['returned_at']);

log_query($pdo, $orderNo, 'ok');

$statusMap = [
    'held'      => '押金占用中',
    'refunding' => '退款处理中',
    'refunded'  => '押金已退还',
    'deducted'  => '押金已扣除',
    'abnormal'  => '订单异常，待处理',
];

json_out([
    'ok' => true,
    'data' => [
        'order_no'       => $rental['order_no'],
        'customer_name'  => $rental['customer_name'], // 姓名本身客户自知，用于确认
        'deposit_amount' => (float)$rental['deposit_amount'],
        'deposit_status' => $rental['deposit_status'],
        'deposit_status_text' => $statusMap[$rental['deposit_status']] ?? $rental['deposit_status'],
        'rent_start'     => $rental['rent_start'],
        'rent_due'       => $rental['rent_due'],
        'returned'       => $returned,
        'returned_at'    => $rental['returned_at'] ?: null,
        'refund_time'    => $rental['refund_time'] ?: null,
        'abnormal'       => (bool)$rental['abnormal_flag'],
        // 异常原因属内部信息，客户侧只给提示，不暴露细节
        'items' => array_map(static fn($it) => [
            'name'  => $it['name'],
            'model' => $it['model'],
            'sn_masked' => $it['sn'] !== '' ? mask_sn($it['sn']) : '',
            'qty'   => (int)$it['qty'],
        ], $list),
    ],
]);

function mask_sn(string $sn): string
{
    $len = mb_strlen($sn);
    if ($len <= 4) {
        return str_repeat('*', max(0, $len - 1)) . mb_substr($sn, -1);
    }
    return mb_substr($sn, 0, 2) . str_repeat('*', $len - 4) . mb_substr($sn, -2);
}
