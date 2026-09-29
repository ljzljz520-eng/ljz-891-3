<?php
/**
 * 标记/取消异常：POST ?order_no=xxx
 * body: { is_abnormal: 1|0, note: string, deposit_status?: 可选联动调整 }
 */
require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/../../../lib/admin_api.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    fail('method_not_allowed', '请求方法不允许', 405);
}
$session = require_admin(true);

$orderNo = strtoupper(input($_GET, 'order_no'));
if (!is_valid_order_no($orderNo)) fail('invalid', '订单号格式不正确', 400);

$pdo = DB::pdo();
$order = load_order_detail($pdo, $orderNo);
if (!$order) fail('not_found', '订单不存在', 404);

$in = request_input();
$mark = (int)input($in, 'is_abnormal', '0');
if (!in_array($mark, [0, 1], true)) fail('invalid', '异常标记取值非法', 400);

$note = trim(input($in, 'note', $order['abnormal_note'] ?? ''));
if ($mark === 1 && $note === '') fail('invalid', '标记异常时必须填写异常说明', 400);
if (mb_strlen($note) > 500) fail('invalid', '异常说明过长（最多 500 字）', 400);

$depositStatus = input($in, 'deposit_status');
if ($depositStatus !== '' && !in_array($depositStatus, array_keys(DEPOSIT_STATUS_MAP), true)) {
    fail('invalid', '押金状态取值非法', 400);
}

$pdo->beginTransaction();
try {
    if ($depositStatus !== '') {
        $refundTime = $order['refund_time'];
        if ($depositStatus === 'refunded' && empty($refundTime)) $refundTime = now();
        $pdo->prepare('UPDATE orders SET is_abnormal = ?, abnormal_note = ?, deposit_status = ?,
                refund_time = ?, updated_at = ? WHERE id = ?')
            ->execute([$mark, $note, $depositStatus, $refundTime, now(), $order['id']]);
    } else {
        $pdo->prepare('UPDATE orders SET is_abnormal = ?, abnormal_note = ?, updated_at = ? WHERE id = ?')
            ->execute([$mark, $note, now(), $order['id']]);
    }

    $pdo->prepare('INSERT INTO query_logs (ip, order_no, result, code, user_agent, admin_id)
        VALUES (?,?,?,?,?,?)')
        ->execute([
            client_ip(), $orderNo,
            $mark ? 'admin_mark_abnormal' : 'admin_clear_abnormal', 'ok',
            mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $session['admin_id'],
        ]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    throw $e;
}

ok(['order_no' => $orderNo, 'is_abnormal' => $mark], $mark ? '已标记为异常订单' : '已解除异常标记');
