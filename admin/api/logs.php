<?php
declare(strict_types=1);

/**
 * 查询日志
 *   GET /admin/api/logs.php?page=&result=&action=&order_no=&from=&to=   分页 JSON
 *   GET /admin/api/logs.php?export=csv&...                               导出 CSV（流式）
 */
require __DIR__ . '/../../lib/bootstrap.php';

$admin = require_admin();
$pdo = db();

$where = [];
$args = [];
foreach (['result', 'action', 'order_no'] as $k) {
    $v = trim((string)($_GET[$k] ?? ''));
    if ($v !== '') {
        // 白名单校验 result/action，order_no 走参数绑定
        if ($k === 'result' && !in_array($v, ['ok', 'bad_order', 'bad_phone', 'bad_captcha', 'rate_locked', 'error'], true)) {
            json_out(['ok' => false, 'error' => 'result 参数非法'], 400);
        }
        $where[] = "$k = ?";
        $args[] = $v;
    }
}
foreach (['from', 'to'] as $k) {
    $v = trim((string)($_GET[$k] ?? ''));
    if ($v !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            json_out(['ok' => false, 'error' => '日期格式应为 YYYY-MM-DD'], 400);
        }
        $where[] = $k === 'from' ? 'l.created_at >= ?' : 'l.created_at <= ?';
        $args[] = $k === 'to' ? $v . ' 23:59:59' : $v . ' 00:00:00';
    }
}
$wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

/* ---------------- CSV 导出（流式，防止内存溢出） ---------------- */
if (!empty($_GET['export']) && $_GET['export'] === 'csv') {
    // 导出上限 10 万行
    $st = $pdo->prepare(
        "SELECT l.id, l.created_at, l.action, l.result, l.order_no, l.ip, l.ua, l.detail,
                a.username AS admin_name
         FROM query_logs l LEFT JOIN admins a ON a.id = l.admin_id
         $wsql ORDER BY l.id DESC LIMIT 100000"
    );
    $st->execute($args);

    $fname = 'query_logs_' . date('YmdHis') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-store');

    log_query($pdo, (string)($_GET['order_no'] ?? ''), 'ok', 'export_logs',
        '导出查询日志 ' . $wsql, $admin['id']);

    $out = fopen('php://output', 'w');
    // UTF-8 BOM，Excel 打开不乱码
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['ID', '时间', '动作', '结果', '订单号', 'IP', 'User-Agent', '详情', '操作人']);
    while ($row = $st->fetch()) {
        fputcsv($out, [
            $row['id'], $row['created_at'], $row['action'], $row['result'],
            $row['order_no'], $row['ip'], $row['ua'], $row['detail'],
            $row['admin_name'] ?? '（客户）',
        ]);
    }
    fclose($out);
    exit;
}

/* ---------------- 分页 JSON ---------------- */
$page = max(1, (int)($_GET['page'] ?? 1));
$size = 30;

$total = $pdo->prepare("SELECT COUNT(*) FROM query_logs l $wsql");
$total->execute($args);
$total = (int)$total->fetchColumn();

$st = $pdo->prepare(
    "SELECT l.*, a.username AS admin_name FROM query_logs l
     LEFT JOIN admins a ON a.id = l.admin_id
     $wsql ORDER BY l.id DESC LIMIT $size OFFSET " . (($page - 1) * $size)
);
$st->execute($args);

json_out(['ok' => true, 'rows' => $st->fetchAll(), 'total' => $total, 'page' => $page]);
