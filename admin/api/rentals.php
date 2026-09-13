<?php
declare(strict_types=1);

/**
 * 租赁记录管理
 *   GET  /admin/api/rentals.php?page=&status=&keyword=   列表
 *   GET  /admin/api/rentals.php?id=                       详情
 *   POST /admin/api/rentals.php                           新建
 *   POST /admin/api/rentals.php?_method=PUT               更新（归还/退款/状态）
 * 所有写操作需 CSRF。
 */
require __DIR__ . '/../../lib/bootstrap.php';

$admin = require_admin();
$pdo = db();
$method = strtoupper($_SERVER['REQUEST_METHOD']);

if ($method === 'POST' && strtoupper((string)($_GET['_method'] ?? body_json()['_method'] ?? '')) === 'PUT') {
    $method = 'PUT';
}

/* ---------------- 列表 ---------------- */
if ($method === 'GET' && empty($_GET['id'])) {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $size = 20;
    $where = [];
    $args = [];

    $status = trim((string)($_GET['status'] ?? ''));
    if (in_array($status, ['held', 'refunding', 'refunded', 'deducted', 'abnormal'], true)) {
        $where[] = 'deposit_status = ?';
        $args[] = $status;
    }
    if (!empty($_GET['abnormal'])) {
        $where[] = 'abnormal_flag = 1';
    }
    $kw = trim((string)($_GET['keyword'] ?? ''));
    if ($kw !== '') {
        // 后台可按订单号或客户姓名搜索；手机号因哈希不能模糊搜，精确搜需先 hash
        $where[] = '(order_no LIKE ? OR customer_name LIKE ?)';
        $args[] = "%$kw%";
        $args[] = "%$kw%";
    }
    $wsql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $total = $pdo->prepare("SELECT COUNT(*) FROM rentals $wsql");
    $total->execute($args);
    $total = (int)$total->fetchColumn();

    $st = $pdo->prepare(
        "SELECT id, order_no, customer_name, deposit_amount, deposit_status,
                rent_start, rent_due, returned_at, refund_time, abnormal_flag,
                abnormal_reason, created_at
         FROM rentals $wsql ORDER BY id DESC LIMIT $size OFFSET " . (($page - 1) * $size)
    );
    $st->execute($args);

    json_out(['ok' => true, 'rows' => $st->fetchAll(), 'total' => $total, 'page' => $page]);
}

/* ---------------- 详情 ---------------- */
if ($method === 'GET') {
    $id = (int)$_GET['id'];
    $st = $pdo->prepare('SELECT * FROM rentals WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        json_out(['ok' => false, 'error' => '记录不存在'], 404);
    }
    // 手机号仅后台详情页解密展示（带审计）
    $r['phone'] = phone_decrypt($r['phone_enc']);
    unset($r['phone_enc'], $r['phone_hash']);
    $it = $pdo->prepare('SELECT * FROM rental_items WHERE rental_id = ? ORDER BY id');
    $it->execute([$id]);
    $r['items'] = $it->fetchAll();

    log_query($pdo, $r['order_no'], 'ok', 'admin_view', '查看订单详情', $admin['id']);
    json_out(['ok' => true, 'row' => $r]);
}

/* ---------------- 新建 ---------------- */
if ($method === 'POST') {
    require_csrf();
    $in = body_json();

    $name  = trim((string)($in['customer_name'] ?? ''));
    $phone = normalize_phone((string)($in['phone'] ?? ''));
    $amount = round((float)($in['deposit_amount'] ?? 0), 2);
    $start = trim((string)($in['rent_start'] ?? ''));
    $due   = trim((string)($in['rent_due'] ?? ''));
    $status = (string)($in['deposit_status'] ?? 'held');
    $remark = trim((string)($in['remark'] ?? ''));
    $items = $in['items'] ?? [];

    $err = [];
    if ($name === '' || mb_strlen($name) > 64) $err[] = '客户姓名无效';
    if (!valid_phone($phone)) $err[] = '手机号无效';
    if ($amount <= 0) $err[] = '押金金额必须大于 0';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due)) {
        $err[] = '租赁起止日期格式应为 YYYY-MM-DD';
    }
    if ($start > $due) $err[] = '归还截止日不能早于起租日';
    if (!in_array($status, ['held', 'refunding', 'refunded', 'deducted', 'abnormal'], true)) {
        $err[] = '押金状态非法';
    }
    if (!is_array($items) || count($items) === 0) $err[] = '至少录入一件设备';
    $cleanItems = [];
    foreach ($items as $it) {
        $iname = trim((string)($it['name'] ?? ''));
        if ($iname === '') { $err[] = '设备名称不能为空'; break; }
        $cleanItems[] = [
            'name'  => mb_substr($iname, 0, 128),
            'model' => mb_substr(trim((string)($it['model'] ?? '')), 0, 128),
            'sn'    => mb_substr(trim((string)($it['sn'] ?? '')), 0, 64),
            'qty'   => max(1, min(99, (int)($it['qty'] ?? 1))),
            'unit_price' => round((float)($it['unit_price'] ?? 0), 2),
        ];
    }
    if ($err) {
        json_out(['ok' => false, 'error' => implode('；', $err)], 422);
    }

    $orderNo = generate_order_no($pdo);

    $pdo->beginTransaction();
    $pdo->prepare(
        'INSERT INTO rentals
            (order_no, customer_name, phone_enc, phone_hash, deposit_amount, deposit_status,
             rent_start, rent_due, abnormal_flag, abnormal_reason, remark, created_at, updated_at)
         VALUES (?,?,?,?,?,?,?,?,0,?,?, ' . nowExpr() . ',' . nowExpr() . ')'
    )->execute([
        $orderNo, $name, phone_encrypt($phone), phone_hash($phone),
        $amount, $status, $start, $due,
        mb_substr(trim((string)($in['abnormal_reason'] ?? '')), 0, 255),
        mb_substr($remark, 0, 255),
    ]);
    $rid = (int)$pdo->lastInsertId();

    $insItem = $pdo->prepare(
        'INSERT INTO rental_items (rental_id, name, model, sn, qty, unit_price) VALUES (?,?,?,?,?,?)'
    );
    foreach ($cleanItems as $it) {
        $insItem->execute([$rid, $it['name'], $it['model'], $it['sn'], $it['qty'], $it['unit_price']]);
    }
    $pdo->commit();

    log_query($pdo, $orderNo, 'ok', 'create', '新建租赁记录', $admin['id']);
    json_out(['ok' => true, 'order_no' => $orderNo, 'id' => $rid]);
}

/* ---------------- 更新（归还/退款/状态） ---------------- */
if ($method === 'PUT') {
    require_csrf();
    $in = body_json();
    $id = (int)($in['id'] ?? 0);
    $st = $pdo->prepare('SELECT * FROM rentals WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        json_out(['ok' => false, 'error' => '记录不存在'], 404);
    }

    $fields = [];
    $args = [];

    if (isset($in['deposit_status'])) {
        $s = (string)$in['deposit_status'];
        if (!in_array($s, ['held', 'refunding', 'refunded', 'deducted', 'abnormal'], true)) {
            json_out(['ok' => false, 'error' => '押金状态非法'], 422);
        }
        $fields[] = 'deposit_status = ?';
        $args[] = $s;
    }

    // 归还：传 returned_at（ISO），传 null/空表示撤销
    if (array_key_exists('returned_at', $in)) {
        $v = trim((string)$in['returned_at']);
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v)) {
            json_out(['ok' => false, 'error' => '归还时间格式非法'], 422);
        }
        $fields[] = 'returned_at = ?';
        $args[] = $v !== '' ? $v : null;
    }

    if (array_key_exists('refund_time', $in)) {
        $v = trim((string)$in['refund_time']);
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}(:\d{2})?)?$/', $v)) {
            json_out(['ok' => false, 'error' => '退款时间格式非法'], 422);
        }
        $fields[] = 'refund_time = ?';
        $args[] = $v !== '' ? $v : null;
    }

    foreach (['remark', 'abnormal_reason'] as $k) {
        if (array_key_exists($k, $in)) {
            $fields[] = "$k = ?";
            $args[] = mb_substr(trim((string)$in[$k]), 0, 255);
        }
    }

    if (!$fields) {
        json_out(['ok' => false, 'error' => '没有要更新的字段'], 422);
    }
    $fields[] = 'updated_at = ' . nowExpr();
    $args[] = $id;

    $pdo->prepare('UPDATE rentals SET ' . implode(',', $fields) . ' WHERE id = ?')->execute($args);
    log_query($pdo, $r['order_no'], 'ok', 'update',
        '更新: ' . implode(',', preg_replace('/ = .*/', '', $fields)), $admin['id']);
    json_out(['ok' => true]);
}

json_out(['ok' => false, 'error' => '不支持的方法'], 405);
