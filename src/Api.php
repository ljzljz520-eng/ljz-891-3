<?php
declare(strict_types=1);

/**
 * 业务控制器：客户查询 + 后台管理。
 * 入口文件负责引导，这里只处理业务。
 */
final class Api
{
    private const STATUS_LABELS = [
        'active'   => '租赁中',
        'returned' => '已归还',
        'abnormal' => '异常',
        'closed'   => '已关闭',
    ];
    private const REFUND_LABELS = [
        'unpaid'     => '待退款',
        'processing' => '退款中',
        'refunded'   => '已退款',
        'deducted'   => '部分扣除',
    ];

    /* ===================== 客户侧 ===================== */

    /** POST /api/deposit.php  { order_no, phone } */
    public static function customerQuery(): void
    {
        if (Req::method() !== 'POST') {
            Out::fail('仅支持 POST 请求', 405);
        }
        $ip = Req::ip();

        // 1) 同 IP 总量限流
        $t = Cfg::get('throttle.ip');
        $g = Throttle::hit('q:ip:' . $ip, $t['max'], $t['window']);
        if (!$g['allowed']) {
            Out::fail('查询过于频繁，请 ' . $g['retry_after'] . ' 秒后再试', 429, $g['retry_after']);
        }

        $body = Req::json();
        $orderNo = V::normalizeOrderNo($body['order_no'] ?? null);
        $phone = V::normalizePhone($body['phone'] ?? null);

        // 2) 参数非法 -> 记失败桶
        if ($orderNo === null || $phone === null) {
            self::recordQuery($body['order_no'] ?? '', $phone, 'invalid', 400, null);
            $g = self::failByIp($ip);
            Out::fail($g['banned']
                ? '失败次数过多，请 ' . $g['retry_after'] . ' 秒后再试'
                : '请输入正确的订单号和 11 位手机号',
                $g['banned'] ? 429 : 400, $g['banned'] ? $g['retry_after'] : null);
        }

        // 3) 同 IP 撞同一订单号：只看历史失败数（成功查询不计数，避免正常客户被挡）
        $t = Cfg::get('throttle.order');
        $oc = Throttle::peek('q:ord:' . $ip . ':' . $orderNo, $t['max'], $t['window']);
        if ($oc['blocked']) {
            Out::fail('该订单查询尝试过多，请 ' . $oc['retry_after'] . ' 秒后再试', 429, $oc['retry_after']);
        }

        // 4) 订单号 + 手机号双重校验
        $rental = DB::one(
            'SELECT * FROM rentals WHERE order_no = :o AND phone_hash = :h LIMIT 1',
            ['o' => $orderNo, 'h' => Sec::phoneHash($phone)]
        );

        if ($rental === null) {
            self::recordQuery($orderNo, $phone, 'not_found', 404, null);
            Throttle::bump('q:ord:' . $ip . ':' . $orderNo, $t['window']); // 仅失败计数
            $g = self::failByIp($ip);
            // 统一、模糊的报错，不暴露是订单号还是手机号错误
            Out::fail($g['banned']
                ? '查询失败次数过多，请 ' . $g['retry_after'] . ' 秒后再试'
                : '未查询到订单，请核对订单号与下单手机号',
                $g['banned'] ? 429 : 404, $g['banned'] ? $g['retry_after'] : null);
        }

        self::recordQuery($orderNo, $phone, 'success', 200, (int)$rental['id']);
        Out::ok(self::customerView($rental));
    }

    private static function failByIp(string $ip): array
    {
        $t = Cfg::get('throttle.ip_fail');
        $g = Throttle::hit('q:fail:ip:' . $ip, $t['max'], $t['window']);
        return ['banned' => !$g['allowed'], 'retry_after' => $g['retry_after']];
    }

    /** 日志里只存脱敏手机号，不落地完整明文 */
    private static function recordQuery(
        string $orderInput,
        ?string $phoneNorm,
        string $result,
        int $httpStatus,
        ?int $rentalId
    ): void {
        DB::run(
            'INSERT INTO query_logs (order_input, phone_input, ip_hash, user_agent, result, http_status, rental_id)
             VALUES (:o, :p, :ip, :ua, :r, :s, :rid)',
            [
                'o'   => mb_substr($orderInput, 0, 40),
                'p'   => $phoneNorm === null ? '' : V::maskPhone($phoneNorm),
                'ip'  => Sec::hashIp(Req::ip()),
                'ua'  => Req::ua(),
                'r'   => $result,
                's'   => $httpStatus,
                'rid' => $rentalId,
            ]
        );
    }

    /** 客户视图：不含手机号、不含内部异常备注与设备序列号 */
    private static function customerView(array $r): array
    {
        $items = json_decode($r['items_json'], true);
        $items = is_array($items) ? $items : [];
        $list = array_map(static fn(array $i): array => [
            'name'     => (string)($i['name'] ?? ''),
            'model'    => (string)($i['model'] ?? ''),
            'qty'      => (int)($i['qty'] ?? 1),
            'unit'     => (string)($i['unit'] ?? '件'),
            'deposit'  => V::yuan(isset($i['deposit_cents']) ? (int)$i['deposit_cents'] : null),
        ], $items);

        $returnedAt = $r['returned_at'] !== null ? $r['returned_at'] : null;
        $returnStatus = match ($r['status']) {
            'returned', 'closed' => 'returned',
            'abnormal'           => $returnedAt ? 'returned' : 'abnormal',
            default              => 'renting',
        };

        return [
            'order_no'        => $r['order_no'],
            'deposit'         => V::yuan((int)$r['deposit_cents']),
            'status'          => $r['status'],
            'status_label'    => self::STATUS_LABELS[$r['status']] ?? $r['status'],
            'return_status'   => $returnStatus, // renting / returned / abnormal
            'items'           => $list,
            'rented_at'       => $r['rented_at'],
            'due_at'          => $r['due_at'],
            'returned_at'     => $returnedAt,
            'refund_status'   => $r['refund_status'],
            'refund_status_label' => self::REFUND_LABELS[$r['refund_status']] ?? $r['refund_status'],
            'refund_amount'   => V::yuan($r['refund_cents'] !== null ? (int)$r['refund_cents'] : null),
            'refunded_at'     => $r['refunded_at'],
            'public_note'     => $r['public_note'],
            // 异常时给客户一句不泄露内部细节的提示
            'exception_notice'=> $r['status'] === 'abnormal'
                ? '该订单存在异常记录，请联系门店核实处理'
                : '',
        ];
    }

    /* ===================== 后台：鉴权 ===================== */

    /** POST /api/admin/login.php */
    public static function adminLogin(): void
    {
        if (Req::method() !== 'POST') {
            Out::fail('仅支持 POST 请求', 405);
        }
        $ip = Req::ip();
        $t = Cfg::get('admin_login_throttle');
        $g = Throttle::hit('a:login:' . $ip, $t['max'], $t['window']);
        if (!$g['allowed']) {
            Out::fail('登录尝试过多，请 ' . $g['retry_after'] . ' 秒后再试', 429, $g['retry_after']);
        }

        $body = Req::json();
        $username = V::text($body['username'] ?? null, 64, 3);
        $password = is_string($body['password'] ?? null) ? $body['password'] : null;
        if ($username === null || $password === null || strlen($password) < 6 || strlen($password) > 200) {
            Out::fail('账号或密码不正确', 401);
        }

        $admin = AdminAuth::verify($username, $password);
        if ($admin === null) {
            Audit::log(null, 'login_failed', $username, 'IP: ' . $ip);
            Out::fail('账号或密码不正确', 401);
        }

        $token = AdminAuth::issue($admin['id']);
        Audit::log($admin, 'login', $admin['username']);
        Out::ok(['token' => $token, 'admin' => [
            'id' => $admin['id'], 'username' => $admin['username'], 'display_name' => $admin['display_name'],
        ]]);
    }

    public static function adminLogout(array $admin): void
    {
        AdminAuth::revoke();
        Audit::log($admin, 'logout');
        Out::ok(['message' => '已退出']);
    }

    /** 后台统一守卫 */
    public static function guard(string $bucket = ''): array
    {
        $admin = AdminAuth::current();
        if ($admin === null) {
            Out::fail('未登录或登录已过期', 401);
        }
        if ($bucket !== '') {
            $t = Cfg::get('throttle.admin_ip');
            $g = Throttle::hit('a:ip:' . Req::ip() . ':' . $bucket, $t['max'], $t['window']);
            if (!$g['allowed']) {
                Out::fail('操作过于频繁，请 ' . $g['retry_after'] . ' 秒后再试', 429, $g['retry_after']);
            }
        }
        return $admin;
    }

    /* ===================== 后台：租赁记录 ===================== */

    /** GET /api/admin/rentals.php */
    public static function adminRentalList(array $admin): void
    {
        $page = max(1, V::int($_GET['page'] ?? 1, 1, 100000) ?? 1);
        $size = min(100, max(10, V::int($_GET['page_size'] ?? 20, 10, 100) ?? 20));
        $where = [];
        $params = [];

        if (($q = trim((string)($_GET['q'] ?? ''))) !== '') {
            $like = '%' . V::like($q) . '%';
            $where[] = '(order_no LIKE :q OR customer_name LIKE :q)';
            $params['q'] = $like;
        }
        if (in_array($_GET['status'] ?? '', ['active', 'returned', 'abnormal', 'closed'], true)) {
            $where[] = 'status = :status';
            $params['status'] = $_GET['status'];
        }
        if (in_array($_GET['refund_status'] ?? '', ['unpaid', 'processing', 'refunded', 'deducted'], true)) {
            $where[] = 'refund_status = :rs';
            $params['rs'] = $_GET['refund_status'];
        }
        if (($phone = V::normalizePhone($_GET['phone'] ?? '')) !== null) {
            $where[] = 'phone_hash = :ph';
            $params['ph'] = Sec::phoneHash($phone);
        }
        $sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $total = (int)DB::scalar("SELECT COUNT(*) FROM rentals $sqlWhere", $params);
        $rows = DB::all(
            "SELECT * FROM rentals $sqlWhere ORDER BY id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $size, 'off' => ($page - 1) * $size]
        );
        Out::ok([
            'list'      => array_map([self::class, 'adminView'], $rows),
            'total'     => $total,
            'page'      => $page,
            'page_size' => $size,
        ]);
    }

    /** GET /api/admin/rental.php?id= */
    public static function adminRentalGet(array $admin): void
    {
        $id = V::int($_GET['id'] ?? null, 1, PHP_INT_MAX);
        if ($id === null) {
            Out::fail('参数错误', 400);
        }
        $r = DB::one('SELECT * FROM rentals WHERE id = :id', ['id' => $id]);
        if ($r === null) {
            Out::fail('记录不存在', 404);
        }
        Out::ok(self::adminView($r));
    }

    /** POST /api/admin/rental-create.php */
    public static function adminRentalCreate(array $admin): void
    {
        $data = self::parseRentalPayload(Req::json(), false);
        if (is_string($data)) {
            Out::fail($data, 400);
        }

        // 生成不冲突的非顺序订单号
        for ($i = 0; $i < 5; $i++) {
            $orderNo = Sec::newOrderNo();
            if (DB::one('SELECT 1 FROM rentals WHERE order_no = :o', ['o' => $orderNo]) === null) {
                break;
            }
        }
        $id = DB::insert(
            'INSERT INTO rentals
              (order_no, customer_name, phone_hash, phone_enc, deposit_cents,
               status, items_json, rented_at, due_at, public_note)
             VALUES
              (:o, :n, :ph, :pe, :dep, :st, :ij, :ra, :du, :pn)',
            [
                'o' => $orderNo, 'n' => $data['customer_name'],
                'ph' => Sec::phoneHash($data['phone']), 'pe' => Sec::encryptPhone($data['phone']),
                'dep' => $data['deposit_cents'], 'st' => 'active',
                'ij' => json_encode($data['items'], JSON_UNESCAPED_UNICODE),
                'ra' => $data['rented_at'], 'du' => $data['due_at'], 'pn' => $data['public_note'],
            ]
        );
        Audit::log($admin, 'rental_create', $orderNo, '押金:' . V::yuan($data['deposit_cents']));
        Out::ok(self::adminView(DB::one('SELECT * FROM rentals WHERE id = :id', ['id' => $id])), 201);
    }

    /** PUT /api/admin/rental-update.php */
    public static function adminRentalUpdate(array $admin): void
    {
        $id = V::int(Req::json()['id'] ?? null, 1, PHP_INT_MAX);
        if ($id === null) {
            Out::fail('缺少有效的记录 id', 400);
        }
        $r = DB::one('SELECT * FROM rentals WHERE id = :id', ['id' => $id]);
        if ($r === null) {
            Out::fail('记录不存在', 404);
        }
        $data = self::parseRentalPayload(Req::json(), true, $r);
        if (is_string($data)) {
            Out::fail($data, 400);
        }
        DB::run(
            'UPDATE rentals SET customer_name = :n, phone_hash = :ph,
                phone_enc = :pe, deposit_cents = :dep, items_json = :ij, rented_at = :ra,
                due_at = :du, public_note = :pn, updated_at = datetime("now")
             WHERE id = :id',
            [
                'n' => $data['customer_name'],
                'ph' => Sec::phoneHash($data['phone']), 'pe' => Sec::encryptPhone($data['phone']),
                'dep' => $data['deposit_cents'],
                'ij' => json_encode($data['items'], JSON_UNESCAPED_UNICODE),
                'ra' => $data['rented_at'], 'du' => $data['due_at'], 'pn' => $data['public_note'],
                'id' => $id,
            ]
        );
        Audit::log($admin, 'rental_update', $r['order_no'], '押金:' . V::yuan($data['deposit_cents']));
        Out::ok(self::adminView(DB::one('SELECT * FROM rentals WHERE id = :id', ['id' => $id])));
    }

    /** POST /api/admin/return.php 标记归还 */
    public static function adminMarkReturned(array $admin): void
    {
        $b = Req::json();
        $r = self::needRental($b['id'] ?? null);
        if (in_array($r['status'], ['returned', 'closed'], true)) {
            Out::fail('该订单已归还，无需重复操作', 409);
        }
        $returnedAt = V::dateTime($b['returned_at'] ?? null, true) ?? date('Y-m-d H:i:s');
        $note = V::text($b['public_note'] ?? null, 200, 0, true) ?? '';
        DB::run(
            'UPDATE rentals SET status = :st, returned_at = :at, public_note = :pn, updated_at = datetime("now") WHERE id = :id',
            // 异常订单可在保留异常状态的同时登记归还时间；正常单标记为已归还
            ['st' => $r['status'] === 'abnormal' ? 'abnormal' : 'returned',
             'at' => $returnedAt, 'pn' => $note !== '' ? $note : $r['public_note'], 'id' => $r['id']]
        );
        Audit::log($admin, 'mark_returned', $r['order_no'], '归还时间:' . $returnedAt);
        Out::ok(self::adminView(self::reload((int)$r['id'])));
    }

    /** POST /api/admin/exception.php 标记异常 */
    public static function adminMarkException(array $admin): void
    {
        $b = Req::json();
        $r = self::needRental($b['id'] ?? null);
        $note = V::text($b['exception_note'] ?? null, 500, 2);
        if ($note === null) {
            Out::fail('请填写至少 2 个字的异常说明（内部留存）', 400);
        }
        $public = V::text($b['public_note'] ?? null, 200, 0, true) ?? '';
        DB::run(
            'UPDATE rentals SET status = "abnormal", exception_note = :n, public_note = :pn, updated_at = datetime("now") WHERE id = :id',
            ['n' => $note, 'pn' => $public !== '' ? $public : $r['public_note'], 'id' => $r['id']]
        );
        Audit::log($admin, 'mark_exception', $r['order_no'], $note);
        Out::ok(self::adminView(self::reload((int)$r['id'])));
    }

    /** POST /api/admin/refund.php 登记退款 */
    public static function adminRefund(array $admin): void
    {
        $b = Req::json();
        $r = self::needRental($b['id'] ?? null);
        if (!in_array($r['status'], ['returned', 'abnormal', 'closed'], true)) {
            Out::fail('设备尚未归还，不能登记退款', 409);
        }
        $status = $b['refund_status'] ?? '';
        if (!in_array($status, ['processing', 'refunded', 'deducted'], true)) {
            Out::fail('退款状态不合法', 400);
        }
        $deposit = (int)$r['deposit_cents'];
        $amount = null;
        if ($status === 'refunded') {
            $amount = V::toCents($b['refund_amount'] ?? null);
            if ($amount === null || $amount < 0 || $amount > $deposit) {
                Out::fail('退款金额需在 0 与押金 ' . V::yuan($deposit) . ' 元之间', 400);
            }
        }
        $refundedAt = null;
        if ($status === 'refunded') {
            $refundedAt = V::dateTime($b['refunded_at'] ?? null, true) ?? date('Y-m-d H:i:s');
            // 全额退款且非异常 -> closed；扣除/异常保留原状态
            $newStatus = ($amount === $deposit && $r['status'] === 'returned') ? 'closed' : $r['status'];
        } else {
            $newStatus = $r['status'];
        }
        DB::run(
            'UPDATE rentals SET refund_status = :rs, refund_cents = :amt, refunded_at = :at,
                status = :st, updated_at = datetime("now") WHERE id = :id',
            ['rs' => $status, 'amt' => $amount, 'at' => $refundedAt, 'st' => $newStatus, 'id' => $r['id']]
        );
        Audit::log($admin, 'refund_' . $status, $r['order_no'],
            '金额:' . V::yuan($amount) . ' 时间:' . ($refundedAt ?? '-'));
        Out::ok(self::adminView(self::reload((int)$r['id'])));
    }

    /* ===================== 后台：日志 ===================== */

    /** GET /api/admin/logs.php */
    public static function adminLogs(array $admin): void
    {
        [$where, $params] = self::logFilter();
        $page = max(1, V::int($_GET['page'] ?? 1, 1, 1000000) ?? 1);
        $size = min(200, max(10, V::int($_GET['page_size'] ?? 30, 10, 200) ?? 30));
        $total = (int)DB::scalar("SELECT COUNT(*) FROM query_logs $where", $params);
        $rows = DB::all(
            "SELECT * FROM query_logs $where ORDER BY id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $size, 'off' => ($page - 1) * $size]
        );
        Out::ok(['list' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $size]);
    }

    /** GET /api/admin/logs-export.csv */
    public static function adminLogsExport(array $admin): void
    {
        [$where, $params] = self::logFilter();
        Audit::log($admin, 'export_logs', 'query_logs', $where ?: '全部');
        $rows = DB::cursor("SELECT * FROM query_logs $where ORDER BY id DESC LIMIT 50000", $params);
        Csv::send('query-logs-' . date('YmdHis') . '.csv',
            ['ID', '时间', '订单号输入', '手机号(脱敏)', 'IP哈希', 'UA', '结果', 'HTTP状态', '订单ID'],
            (function () use ($rows) {
                foreach ($rows as $r) {
                    yield [
                        $r['id'], $r['created_at'], $r['order_input'], $r['phone_input'],
                        $r['ip_hash'], $r['user_agent'],
                        ['success' => '成功', 'not_found' => '未找到', 'invalid' => '参数错误'][$r['result']] ?? $r['result'],
                        $r['http_status'], $r['rental_id'],
                    ];
                }
            })()
        );
    }

    /** GET /api/admin/audits.php */
    public static function adminAudits(array $admin): void
    {
        $page = max(1, V::int($_GET['page'] ?? 1, 1, 1000000) ?? 1);
        $size = min(200, max(10, V::int($_GET['page_size'] ?? 30, 10, 200) ?? 30));
        $where = '';
        $params = [];
        if (($action = trim((string)($_GET['action'] ?? ''))) !== '') {
            $where = 'WHERE action LIKE :a';
            $params['a'] = '%' . V::like($action) . '%';
        }
        $total = (int)DB::scalar("SELECT COUNT(*) FROM audit_logs $where", $params);
        $rows = DB::all(
            "SELECT * FROM audit_logs $where ORDER BY id DESC LIMIT :lim OFFSET :off",
            $params + ['lim' => $size, 'off' => ($page - 1) * $size]
        );
        Out::ok(['list' => $rows, 'total' => $total, 'page' => $page, 'page_size' => $size]);
    }

    public static function adminMe(array $admin): void
    {
        Out::ok(['id' => $admin['id'], 'username' => $admin['username'], 'display_name' => $admin['display_name']]);
    }

    /* ===================== 内部辅助 ===================== */

    private static function reload(int $id): array
    {
        return DB::one('SELECT * FROM rentals WHERE id = :id', ['id' => $id])
            ?? Out::fail('记录不存在', 404);
    }

    private static function needRental(mixed $id): array
    {
        $n = V::int($id, 1, PHP_INT_MAX);
        if ($n === null) {
            Out::fail('缺少有效的记录 id', 400);
        }
        $r = DB::one('SELECT * FROM rentals WHERE id = :id', ['id' => $n]);
        if ($r === null) {
            Out::fail('记录不存在', 404);
        }
        return $r;
    }

    /**
     * 解析录入/编辑表单。
     * @param array $existing 编辑时的旧记录
     * @return array|string 成功返回数组，失败返回错误信息
     */
    private static function parseRentalPayload(array $b, bool $partial, array $existing = []): array|string
    {
        $name = V::text($b['customer_name'] ?? ($partial ? $existing['customer_name'] : ''), 50, 0, true);
        if ($name === null) {
            return '客户姓名不能超过 50 字';
        }
        $phone = V::normalizePhone($b['phone'] ?? ($partial ? (Sec::decryptPhone($existing['phone_enc']) ?? '') : null));
        if ($phone === null) {
            return '请输入正确的 11 位手机号';
        }

        $items = self::parseItems($b['items'] ?? []);
        if (is_string($items)) {
            return $items;
        }

        $deposit = V::toCents($b['deposit'] ?? ($partial ? ((int)$existing['deposit_cents'] / 100) : null));
        if ($deposit === null || $deposit < 0 || $deposit > 1_000_000_000) {
            return '押金金额需在 0 ~ 10,000,000 元之间';
        }

        $rentedAt = V::dateTime($b['rented_at'] ?? ($partial ? $existing['rented_at'] : null));
        if ($rentedAt === null) {
            return '请选择正确的起租时间';
        }
        $dueAt = V::dateTime($b['due_at'] ?? ($partial ? $existing['due_at'] : null), true);
        if ($dueAt !== null && $dueAt < $rentedAt) {
            return '应还时间不能早于起租时间';
        }
        $publicNote = V::text($b['public_note'] ?? ($partial ? $existing['public_note'] : ''), 200, 0, true) ?? '';

        return [
            'customer_name' => $name,
            'phone'         => $phone,
            'items'         => $items,
            'deposit_cents' => $deposit,
            'rented_at'     => $rentedAt,
            'due_at'        => $dueAt,
            'public_note'   => $publicNote,
        ];
    }

    /**
     * 解析设备清单
     * @return array|string
     */
    private static function parseItems(mixed $raw): array|string
    {
        if (!is_array($raw) || count($raw) === 0) {
            return '请至少添加一件设备';
        }
        if (count($raw) > 50) {
            return '单次最多录入 50 件设备';
        }
        $out = [];
        foreach ($raw as $idx => $item) {
            if (!is_array($item)) {
                return '第 ' . ($idx + 1) . ' 件设备格式错误';
            }
            $nm = V::text($item['name'] ?? null, 60, 1);
            if ($nm === null) {
                return '第 ' . ($idx + 1) . ' 件设备缺少名称';
            }
            $model = V::text($item['model'] ?? '', 60, 0, true) ?? '';
            $sn = V::text($item['sn'] ?? '', 80, 0, true) ?? '';
            $qty = V::int($item['qty'] ?? 1, 1, 999);
            if ($qty === null) {
                return '第 ' . ($idx + 1) . ' 件设备数量需在 1~999';
            }
            $unit = V::text($item['unit'] ?? '件', 8, 0, true) ?? '件';
            $unitDeposit = V::toCents($item['unit_deposit'] ?? '0');
            if ($unitDeposit === null || $unitDeposit < 0 || $unitDeposit > 1_000_000_000) {
                return '第 ' . ($idx + 1) . ' 件设备押金金额不合法';
            }
            $out[] = [
                'name'          => $nm,
                'model'         => $model,
                'sn'            => $sn,
                'qty'           => $qty,
                'unit'          => $unit === '' ? '件' : $unit,
                'deposit_cents' => $unitDeposit,
            ];
        }
        return $out;
    }

    /** 后台视图：含完整手机号（解密）、序列号与内部备注 */
    private static function adminView(array $r): array
    {
        $items = json_decode($r['items_json'], true);
        $items = is_array($items) ? $items : [];
        $list = array_map(static fn(array $i): array => [
            'name'         => (string)($i['name'] ?? ''),
            'model'        => (string)($i['model'] ?? ''),
            'sn'           => (string)($i['sn'] ?? ''),
            'qty'          => (int)($i['qty'] ?? 1),
            'unit'         => (string)($i['unit'] ?? '件'),
            'unit_deposit' => V::yuan((int)($i['deposit_cents'] ?? 0)),
        ], $items);

        return [
            'id'              => (int)$r['id'],
            'order_no'        => $r['order_no'],
            'customer_name'   => $r['customer_name'],
            'phone'           => Sec::decryptPhone($r['phone_enc']) ?? '',
            'deposit'         => V::yuan((int)$r['deposit_cents']),
            'deposit_cents'   => (int)$r['deposit_cents'],
            'status'          => $r['status'],
            'status_label'    => self::STATUS_LABELS[$r['status']] ?? $r['status'],
            'refund_status'   => $r['refund_status'],
            'refund_status_label' => self::REFUND_LABELS[$r['refund_status']] ?? $r['refund_status'],
            'refund_amount'   => V::yuan($r['refund_cents'] !== null ? (int)$r['refund_cents'] : null),
            'items'           => $list,
            'rented_at'       => $r['rented_at'],
            'due_at'          => $r['due_at'],
            'returned_at'     => $r['returned_at'],
            'refunded_at'     => $r['refunded_at'],
            'exception_note'  => $r['exception_note'],
            'public_note'     => $r['public_note'],
            'created_at'      => $r['created_at'],
            'updated_at'      => $r['updated_at'],
        ];
    }

    /** 日志筛选条件（列表/导出共用） */
    private static function logFilter(): array
    {
        $where = [];
        $params = [];
        if (in_array($_GET['result'] ?? '', ['success', 'not_found', 'invalid'], true)) {
            $where[] = 'result = :result';
            $params['result'] = $_GET['result'];
        }
        if (($order = trim((string)($_GET['order_no'] ?? ''))) !== '') {
            $where[] = 'order_input LIKE :o';
            $params['o'] = '%' . V::like($order) . '%';
        }
        if (($from = V::dateTime($_GET['from'] ?? null, true)) !== null) {
            $where[] = 'created_at >= :from';
            $params['from'] = $from;
        }
        if (($to = V::dateTime($_GET['to'] ?? null, true)) !== null) {
            $where[] = 'created_at <= :to';
            $params['to'] = $to;
        }
        return [$where ? ('WHERE ' . implode(' AND ', $where)) : '', $params];
    }
}
