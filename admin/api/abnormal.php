<?php
declare(strict_types=1);

/**
 * 标记/取消异常  POST /admin/api/abnormal.php
 * {"id":123,"abnormal":true,"reason":"镜头划痕未赔偿"}
 */
require __DIR__ . '/../../lib/bootstrap.php';

$admin = require_admin();
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(['ok' => false, 'error' => '仅支持 POST'], 405);
}
require_csrf();

$in = body_json();
$id = (int)($in['id'] ?? 0);
$abnormal = !empty($in['abnormal']);
$reason = mb_substr(trim((string)($in['reason'] ?? '')), 0, 255);

if ($id <= 0 || ($abnormal && $reason === '')) {
    json_out(['ok' => false, 'error' => '参数不完整：标记异常必须填写原因'], 422);
}

$pdo = db();
$st = $pdo->prepare('SELECT order_no FROM rentals WHERE id = ?');
$st->execute([$id]);
$orderNo = $st->fetchColumn();
if ($orderNo === false) {
    json_out(['ok' => false, 'error' => '记录不存在'], 404);
}

// 标记异常时押金状态联动为 abnormal；解除时保持（由管理员在详情页改回具体状态）
if ($abnormal) {
    $sql = 'UPDATE rentals SET abnormal_flag = 1, abnormal_reason = ?,
                deposit_status = \'abnormal\', updated_at = ' . nowExpr() . ' WHERE id = ?';
    $params = [$reason, $id];
} else {
    $sql = 'UPDATE rentals SET abnormal_flag = 0, abnormal_reason = ?,
                updated_at = ' . nowExpr() . ' WHERE id = ?';
    $params = [$reason, $id];
}
$pdo->prepare($sql)->execute($params);

log_query($pdo, (string)$orderNo, 'ok',
    $abnormal ? 'mark_abnormal' : 'clear_abnormal',
    ($abnormal ? '标记异常: ' : '解除异常: ') . $reason, $admin['id']);

json_out(['ok' => true]);
