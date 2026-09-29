<?php
/**
 * 后台订单接口：
 *  GET    ?order_no=xxx        订单详情
 *  GET    （带筛选/分页）       订单列表
 *  POST                         录入租赁记录
 *  PUT    ?order_no=xxx         编辑订单（含设备清单整体替换）
 */
require_once __DIR__ . '/../../../lib/bootstrap.php';
require_once __DIR__ . '/../../../lib/admin_api.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$pdo = DB::pdo();

if ($method === 'GET') {
    $session = require_admin(false);
    $orderNo = strtoupper(input($_GET, 'order_no'));

    if ($orderNo !== '') {
        if (!is_valid_order_no($orderNo)) fail('invalid', '订单号格式不正确', 400);
        $order = load_order_detail($pdo, $orderNo);
        if (!$order) fail('not_found', '订单不存在', 404);
        ok(['order' => $order]);
    }

    // 列表 + 筛选 + 分页
    $where = [];
    $params = [];
    $q = input($_GET, 'q');
    if ($q !== '') {
        $where[] = '(o.order_no LIKE ? OR c.name LIKE ? OR c.phone LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like);
    }
    if (in_array(input($_GET, 'rental_status'), array_keys(RENTAL_STATUS_MAP), true)) {
        $where[] = 'o.rental_status = ?';
        $params[] = input($_GET, 'rental_status');
    }
    if (in_array(input($_GET, 'deposit_status'), array_keys(DEPOSIT_STATUS_MAP), true)) {
        $where[] = 'o.deposit_status = ?';
        $params[] = input($_GET, 'deposit_status');
    }
    if (input($_GET, 'abnormal') === '1') {
        $where[] = 'o.is_abnormal = 1';
    }
    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $page = max(1, (int)input($_GET, 'page', '1'));
    $pageSize = min(100, max(5, (int)input($_GET, 'page_size', '20')));
    $offset = ($page - 1) * $pageSize;

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o JOIN customers c ON c.id = o.customer_id $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $listStmt = $pdo->prepare("SELECT o.id, o.order_no, o.rental_status, o.deposit_status,
            o.deposit_amount, o.deposit_deduction, o.rental_start, o.rental_end,
            o.return_time, o.refund_time, o.is_abnormal, o.abnormal_note, o.created_at,
            c.name AS customer_name, c.phone AS customer_phone,
            (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id) AS item_count
        FROM orders o JOIN customers c ON c.id = o.customer_id
        $whereSql ORDER BY o.id DESC LIMIT $pageSize OFFSET $offset");
    $listStmt->execute($params);
    $list = $listStmt->fetchAll();

    ok(['list' => $list, 'total' => $total, 'page' => $page, 'page_size' => $pageSize]);
}

if ($method === 'POST') {
    $session = require_admin(true);
    $in = request_input();
    $check = collect_order_payload($in, $pdo, false);
    if (!$check['valid']) fail('invalid', $check['error'], 400);
    $d = $check['data'];

    $pdo->beginTransaction();
    try {
        $customerId = upsert_customer($pdo, $d['name'], $d['phone']);
        $orderNo = generate_order_no($pdo);
        $pdo->prepare('INSERT INTO orders
            (order_no, customer_id, rental_status, deposit_amount, deposit_status, deposit_deduction,
             rental_start, rental_end, return_time, refund_time, is_abnormal, abnormal_note, customer_note)
            VALUES (?,?,?,?,?,0,?,?,?,?,?,?,?)')
            ->execute([
                $orderNo, $customerId,
                $d['rental_status'], $d['deposit_amount'], $d['deposit_status'],
                $d['rental_start'], $d['rental_end'] ?? null, $d['return_time'] ?? null, $d['refund_time'] ?? null,
                (int)($d['is_abnormal'] ?? 0), $d['abnormal_note'] ?? '', $d['customer_note'] ?? '',
            ]);
        $orderId = (int)$pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, name, model, qty, unit_price, serial_no)
            VALUES (?,?,?,?,?,?)');
        foreach ($d['items'] as $it) {
            $itemStmt->execute([$orderId, $it['name'], $it['model'], $it['qty'], $it['unit_price'], $it['serial_no']]);
        }

        $pdo->prepare('INSERT INTO query_logs (ip, order_no, result, code, user_agent, admin_id)
            VALUES (?,?,?,?,?,?)')
            ->execute([client_ip(), $orderNo, 'admin_create', 'ok', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $session['admin_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ok(['order_no' => $orderNo], '租赁记录已录入');
}

if ($method === 'PUT') {
    $session = require_admin(true);
    $orderNo = strtoupper(input($_GET, 'order_no'));
    if (!is_valid_order_no($orderNo)) fail('invalid', '订单号格式不正确', 400);

    $order = load_order_detail($pdo, $orderNo);
    if (!$order) fail('not_found', '订单不存在', 404);

    $in = request_input();
    $check = collect_order_payload($in, $pdo, true);
    if (!$check['valid']) fail('invalid', $check['error'], 400);
    $d = $check['data'];

    $pdo->beginTransaction();
    try {
        // 客户信息：给了手机号/姓名就更新（或换绑客户）
        $customerId = (int)$order['customer_id'];
        if (isset($d['phone']) || isset($d['name'])) {
            $name = $d['name'] ?? $order['customer_name'];
            $phone = $d['phone'] ?? $order['customer_phone'];
            $customerId = upsert_customer($pdo, $name, $phone);
        }

        // 押金状态联动时间：标记“已退还”且未填退款时间，自动补当前时间
        if (($d['deposit_status'] ?? $order['deposit_status']) === 'refunded'
            && array_key_exists('refund_time', $d) && $d['refund_time'] === null
            && empty($order['refund_time'])) {
            $d['refund_time'] = now();
        }
        // 标记“已归还”且未填归还时间，自动补当前时间
        if (($d['rental_status'] ?? $order['rental_status']) === 'returned'
            && array_key_exists('return_time', $d) && $d['return_time'] === null
            && empty($order['return_time'])) {
            $d['return_time'] = now();
        }

        $fields = [
            'customer_id' => $customerId,
            'rental_status' => $d['rental_status'] ?? null,
            'deposit_amount' => $d['deposit_amount'] ?? null,
            'deposit_status' => $d['deposit_status'] ?? null,
            'rental_start' => $d['rental_start'] ?? null,
            'rental_end' => array_key_exists('rental_end', $d) ? $d['rental_end'] : null,
            'return_time' => array_key_exists('return_time', $d) ? $d['return_time'] : null,
            'refund_time' => array_key_exists('refund_time', $d) ? $d['refund_time'] : null,
            'is_abnormal' => array_key_exists('is_abnormal', $d) ? $d['is_abnormal'] : null,
            'abnormal_note' => array_key_exists('abnormal_note', $d) ? $d['abnormal_note'] : null,
            'customer_note' => array_key_exists('customer_note', $d) ? $d['customer_note'] : null,
        ];
        $set = [];
        $params = [];
        foreach ($fields as $col => $val) {
            if ($val !== null) {
                $set[] = "$col = ?";
                $params[] = $val;
            }
        }
        // 注意：null 值字段需要显式清空（rental_end 等），单独处理
        foreach (['rental_end', 'return_time', 'refund_time', 'abnormal_note', 'customer_note', 'is_abnormal'] as $nullable) {
            if (array_key_exists($nullable, $d) && $d[$nullable] === null) {
                // 已被跳过的字段
                if (!in_array("$nullable = ?", $set, true)) {
                    $set[] = "$nullable = ?";
                    $params[] = $nullable === 'is_abnormal' ? 0 : null;
                }
            }
        }

        $set[] = "updated_at = ?";
        $params[] = now();
        $params[] = $order['id'];
        $pdo->prepare('UPDATE orders SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($params);

        // 设备清单：提交了 items 就整体替换
        if (isset($d['items'])) {
            $pdo->prepare('DELETE FROM order_items WHERE order_id = ?')->execute([$order['id']]);
            $itemStmt = $pdo->prepare('INSERT INTO order_items (order_id, name, model, qty, unit_price, serial_no)
                VALUES (?,?,?,?,?,?)');
            foreach ($d['items'] as $it) {
                $itemStmt->execute([$order['id'], $it['name'], $it['model'], $it['qty'], $it['unit_price'], $it['serial_no']]);
            }
        }

        $pdo->prepare('INSERT INTO query_logs (ip, order_no, result, code, user_agent, admin_id)
            VALUES (?,?,?,?,?,?)')
            ->execute([client_ip(), $orderNo, 'admin_update', 'ok', mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255), $session['admin_id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    ok(['order_no' => $orderNo], '订单已更新');
}

fail('method_not_allowed', '请求方法不允许', 405);
