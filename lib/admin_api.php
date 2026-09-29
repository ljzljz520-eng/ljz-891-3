<?php
/**
 * 后台 API 公共守卫。
 */

/** 必须已登录；写操作必须携带与会话绑定的 CSRF 令牌。 */
function require_admin(bool $write = false): array
{
    $session = (new AdminAuth(DB::pdo()))->check();
    if (!$session) {
        fail('unauthorized', '未登录或会话已过期，请重新登录', 401);
    }
    if ($write) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token) || !hash_equals($session['csrf'], $token)) {
            fail('csrf_invalid', '安全校验失败，请刷新页面后重试', 403);
        }
    }
    return $session;
}

/**
 * 校验并收集订单表单数据。
 * @return array{valid:bool, error:string, data:array}
 */
function collect_order_payload(array $in, PDO $pdo, bool $partial = false): array
{
    $has = static fn(string $k): bool => array_key_exists($k, $in);

    $require = static function (string $key, string $label) use ($in, $has, $partial): ?string {
        if (!$has($key)) {
            return $partial ? null : "缺少{$label}";
        }
        $v = trim((string)$in[$key]);
        if ($v === '') return "{$label}不能为空";
        return null;
    };

    $data = [];

    // 客户信息
    foreach (['name' => '客户姓名', 'phone' => '手机号'] as $key => $label) {
        if ($has($key)) {
            $v = trim((string)$in[$key]);
            if ($v === '') return ['valid' => false, 'error' => "{$label}不能为空", 'data' => []];
            if ($key === 'phone') {
                $v = normalize_phone($v);
                if (!is_valid_phone($v)) return ['valid' => false, 'error' => '手机号格式不正确', 'data' => []];
            } elseif (mb_strlen($v) > 64) {
                return ['valid' => false, 'error' => '客户姓名过长', 'data' => []];
            }
            $data[$key] = $v;
        } elseif (!$partial) {
            return ['valid' => false, 'error' => "缺少{$label}", 'data' => []];
        }
    }

    // 押金金额
    if ($has('deposit_amount')) {
        $fen = yuan_to_fen(trim((string)$in['deposit_amount']));
        if ($fen === null || $fen <= 0) return ['valid' => false, 'error' => '押金金额格式不正确（如 8000.00）', 'data' => []];
        $data['deposit_amount'] = $fen;
    } elseif (!$partial) {
        return ['valid' => false, 'error' => '缺少押金金额', 'data' => []];
    }

    // 状态枚举
    $enums = [
        'rental_status'  => ['租赁状态', array_keys(RENTAL_STATUS_MAP)],
        'deposit_status' => ['押金状态', array_keys(DEPOSIT_STATUS_MAP)],
    ];
    foreach ($enums as $key => [$label, $allowed]) {
        if ($has($key)) {
            $v = trim((string)$in[$key]);
            if (!in_array($v, $allowed, true)) {
                return ['valid' => false, 'error' => "{$label}取值非法", 'data' => []];
            }
            $data[$key] = $v;
        } elseif (!$partial) {
            return ['valid' => false, 'error' => "缺少{$label}", 'data' => []];
        }
    }

    // 日期字段
    $dates = ['rental_start' => '起租时间', 'rental_end' => '应还时间', 'return_time' => '实际归还时间', 'refund_time' => '退款时间'];
    foreach ($dates as $key => $label) {
        if ($has($key)) {
            $v = parse_datetime(trim((string)$in[$key]));
            if (trim((string)$in[$key]) !== '' && $v === null) {
                return ['valid' => false, 'error' => "{$label}格式不正确（YYYY-MM-DD HH:MM）", 'data' => []];
            }
            $data[$key] = $v;
        } elseif (!$partial) {
            if ($key === 'rental_start') return ['valid' => false, 'error' => '缺少起租时间', 'data' => []];
            $data[$key] = null;
        }
    }

    // 文本
    foreach (['abnormal_note' => '异常说明', 'customer_note' => '客户备注'] as $key => $label) {
        if ($has($key)) {
            $v = trim((string)$in[$key]);
            if (mb_strlen($v) > 500) return ['valid' => false, 'error' => "{$label}过长（最多 500 字）", 'data' => []];
            $data[$key] = $v;
        } elseif (!$partial) {
            $data[$key] = '';
        }
    }

    if ($has('is_abnormal')) {
        $data['is_abnormal'] = in_array((int)$in['is_abnormal'], [0, 1], true) ? (int)$in['is_abnormal'] : 0;
    }

    // 设备清单（创建时必填至少一件；格式为数组）
    if ($has('items')) {
        $items = $in['items'];
        if (!is_array($items) || count($items) === 0) {
            return ['valid' => false, 'error' => '请至少录入一件设备', 'data' => []];
        }
        if (count($items) > 50) {
            return ['valid' => false, 'error' => '单次录入设备不能超过 50 件', 'data' => []];
        }
        $clean = [];
        foreach ($items as $idx => $it) {
            if (!is_array($it)) return ['valid' => false, 'error' => '设备清单格式不正确', 'data' => []];
            $name = trim((string)($it['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 128) {
                return ['valid' => false, 'error' => '第 ' . ($idx + 1) . ' 件设备名称无效', 'data' => []];
            }
            $model = mb_substr(trim((string)($it['model'] ?? '')), 0, 128);
            $serial = mb_substr(trim((string)($it['serial_no'] ?? '')), 0, 128);
            $qty = (int)($it['qty'] ?? 1);
            if ($qty < 1 || $qty > 999) return ['valid' => false, 'error' => '第 ' . ($idx + 1) . ' 件设备数量无效', 'data' => []];
            $priceFen = yuan_to_fen(trim((string)($it['unit_price'] ?? '0')));
            if ($priceFen === null || $priceFen < 0) return ['valid' => false, 'error' => '第 ' . ($idx + 1) . ' 件设备日租金格式不正确', 'data' => []];
            $clean[] = ['name' => $name, 'model' => $model, 'qty' => $qty, 'unit_price' => $priceFen, 'serial_no' => $serial];
        }
        $data['items'] = $clean;
    } elseif (!$partial) {
        return ['valid' => false, 'error' => '请至少录入一件设备', 'data' => []];
    }

    return ['valid' => true, 'error' => '', 'data' => $data];
}

/** 查找客户（手机号），不存在则创建，返回 id */
function upsert_customer(PDO $pdo, string $name, string $phone): int
{
    $hash = hash_hmac('sha256', $phone, DB::key());
    $stmt = $pdo->prepare('SELECT id FROM customers WHERE phone_hash = ?');
    $stmt->execute([$hash]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $pdo->prepare('UPDATE customers SET name = ?, phone = ? WHERE id = ?')
            ->execute([$name, $phone, $id]);
        return (int)$id;
    }
    $pdo->prepare('INSERT INTO customers (name, phone, phone_hash) VALUES (?, ?, ?)')
        ->execute([$name, $phone, $hash]);
    return (int)$pdo->lastInsertId();
}

/** 组装给后台看的订单（含明文手机号与异常备注） */
function load_order_detail(PDO $pdo, string $orderNo): ?array
{
    $stmt = $pdo->prepare('SELECT o.*, c.name AS customer_name, c.phone AS customer_phone
        FROM orders o JOIN customers c ON c.id = o.customer_id WHERE o.order_no = ?');
    $stmt->execute([$orderNo]);
    $order = $stmt->fetch();
    if (!$order) return null;

    $items = $pdo->prepare('SELECT name, model, qty, unit_price, serial_no FROM order_items WHERE order_id = ? ORDER BY id');
    $items->execute([$order['id']]);
    $order['items'] = $items->fetchAll();
    return $order;
}
