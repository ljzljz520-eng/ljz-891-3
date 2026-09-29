<?php
/**
 * 查询日志：
 *  GET ?format=csv   导出 CSV（带身份校验，浏览器直接下载）
 *  GET               分页列表
 */
require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/../../../lib/admin_api.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    fail('method_not_allowed', '请求方法不允许', 405);
}
$session = require_admin(false);
$pdo = DB::pdo();

$where = [];
$params = [];

$result = input($_GET, 'result');
$allowedResults = ['success', 'fail', 'locked', 'login_success', 'login_fail', 'logout',
    'admin_create', 'admin_update', 'admin_mark_abnormal', 'admin_clear_abnormal'];
if (in_array($result, $allowedResults, true)) {
    $where[] = 'l.result = ?';
    $params[] = $result;
}
$ip = input($_GET, 'ip');
if ($ip !== '' && preg_match('/^[0-9a-fA-F\.:]{3,45}$/', $ip)) {
    $where[] = 'l.ip LIKE ?';
    $params[] = '%' . $ip . '%';
}
$q = input($_GET, 'q');
if ($q !== '') {
    $where[] = 'l.order_no LIKE ?';
    $params[] = '%' . strtoupper($q) . '%';
}
$start = input($_GET, 'start');
if ($start !== '' && parse_datetime($start)) $start = parse_datetime($start);
if (!empty($start)) { $where[] = 'l.created_at >= ?'; $params[] = $start; }
$end = input($_GET, 'end');
if (!empty($end)) {
    $endDt = parse_datetime($end);
    if (!$endDt) fail('invalid', '结束时间格式不正确', 400);
    $where[] = 'l.created_at <= ?';
    $params[] = $endDt;
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// CSV 导出：最多 50000 条，防止内存打爆；导出动作本身也记日志
if (input($_GET, 'format') === 'csv') {
    $stmt = $pdo->prepare("SELECT l.created_at, l.ip, l.order_no, l.phone_last4, l.result, l.code,
            l.user_agent, a.username AS admin_username
        FROM query_logs l LEFT JOIN admins a ON a.id = l.admin_id
        $whereSql ORDER BY l.id DESC LIMIT 50000");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $pdo->prepare('INSERT INTO query_logs (ip, result, code, user_agent, admin_id) VALUES (?,?,?,?,?)')
        ->execute([client_ip(), 'admin_export_logs', 'ok', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $session['admin_id']]);

    $resultText = [
        'success' => '查询成功', 'fail' => '查询失败', 'locked' => '触发锁定',
        'login_success' => '后台登录成功', 'login_fail' => '后台登录失败', 'logout' => '退出登录',
        'admin_create' => '录入订单', 'admin_update' => '更新订单',
        'admin_mark_abnormal' => '标记异常', 'admin_clear_abnormal' => '解除异常',
        'admin_export_logs' => '导出日志',
    ];

    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="query-logs-' . date('YmdHis') . '.csv"');
    // BOM 让 Excel 正确识别 UTF-8
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['时间', 'IP 地址', '订单号', '手机号后四位', '结果', '原因码', '操作管理员', 'User-Agent'], ',', '"', chr(92));
    foreach ($rows as $r) {
        fputcsv($out, [
            $r['created_at'], $r['ip'], $r['order_no'], $r['phone_last4'],
            $resultText[$r['result']] ?? $r['result'], $r['code'],
            $r['admin_username'] ?? '-', $r['user_agent'],
        ], ',', '"', chr(92));
    }
    fclose($out);
    exit;
}

$page = max(1, (int)input($_GET, 'page', '1'));
$pageSize = min(200, max(10, (int)input($_GET, 'page_size', '30')));
$offset = ($page - 1) * $pageSize;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM query_logs l $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();

$listStmt = $pdo->prepare("SELECT l.*, a.username AS admin_username
    FROM query_logs l LEFT JOIN admins a ON a.id = l.admin_id
    $whereSql ORDER BY l.id DESC LIMIT $pageSize OFFSET $offset");
$listStmt->execute($params);

ok(['list' => $listStmt->fetchAll(), 'total' => $total, 'page' => $page, 'page_size' => $pageSize]);
