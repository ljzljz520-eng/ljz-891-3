<?php
/**
 * 通用辅助函数。
 */

function json_response($payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $code, string $message, int $status = 400, array $extra = []): never
{
    json_response(array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra), $status);
}

function ok(array $data = [], string $message = ''): never
{
    json_response(['ok' => true, 'message' => $message, 'data' => $data], 200);
}

/** 读取 JSON / 表单请求体 */
function request_input(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $ctype = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($ctype, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = json_decode($raw, true);
        $cache = is_array($decoded) ? $decoded : [];
    } else {
        $cache = $_POST;
    }
    return $cache;
}

function input(array $src, string $key, $default = ''): string
{
    $v = $src[$key] ?? $default;
    return is_string($v) ? trim($v) : (is_scalar($v) ? (string)$v : $default);
}

/** 取真实客户端 IP（不读取可伪造的 X-Forwarded-For，避免绕过限流） */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function normalize_phone(string $phone): string
{
    // 去掉空格、连字符等；支持 11 位大陆手机号，或带 +86 前缀
    $p = preg_replace('/[\s\-()]/u', '', $phone);
    if (preg_match('/^\+?86?(\d{11})$/', $p, $m)) {
        return $m[1];
    }
    return $p;
}

function is_valid_phone(string $phone): bool
{
    return (bool)preg_match('/^1[3-9]\d{9}$/', $phone);
}

function is_valid_order_no(string $no): bool
{
    return (bool)preg_match('/^[A-Z0-9]{6,32}$/', $no);
}

function mask_phone(string $phone): string
{
    $len = mb_strlen($phone);
    if ($len >= 7) {
        return mb_substr($phone, 0, 3) . str_repeat('*', $len - 7) . mb_substr($phone, -4);
    }
    return $len > 2 ? mb_substr($phone, 0, 1) . str_repeat('*', max(0, $len - 2)) . mb_substr($phone, -1) : '**';
}

function mask_name(string $name): string
{
    $len = mb_strlen($name);
    if ($len <= 1) return $name;
    if ($len == 2) return mb_substr($name, 0, 1) . '*';
    return mb_substr($name, 0, 1) . str_repeat('*', $len - 2) . mb_substr($name, -1);
}

/** 分 -> 元（输出为字符串，保留两位小数） */
function fen_to_yuan(int $fen): string
{
    return number_format($fen / 100, 2, '.', '');
}

/** 元 -> 分（严格校验，失败返回 null） */
function yuan_to_fen(string $yuan): ?int
{
    if (!preg_match('/^\d{1,8}(\.\d{1,2})?$/', $yuan)) {
        return null;
    }
    return (int)round((float)$yuan * 100);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

/** 生成不可顺序猜测的订单号：RP + 10 位自定义字母表随机串 */
function generate_order_no(PDO $pdo): string
{
    $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // 去掉易混淆字符 I/L/O/0/1
    for ($i = 0; $i < 10; $i++) {
        $no = 'RP';
        for ($j = 0; $j < 10; $j++) {
            $no .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $stmt = $pdo->prepare('SELECT 1 FROM orders WHERE order_no = ?');
        $stmt->execute([$no]);
        if (!$stmt->fetchColumn()) {
            return $no;
        }
    }
    throw new RuntimeException('订单号生成失败，请重试');
}

const RENTAL_STATUS_MAP = [
    'renting'  => '租赁中',
    'returned' => '已归还',
    'overdue'  => '逾期未还',
];

const DEPOSIT_STATUS_MAP = [
    'held'      => '押金冻结中',
    'deducted'  => '押金已部分扣除',
    'refunding' => '退款处理中',
    'refunded'  => '押金已退还',
];

/** 解析日期/日期时间；非法返回 null */
function parse_datetime(string $s): ?string
{
    $s = trim($s);
    if ($s === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        $s .= ' 00:00:00';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/', $s)) {
        return null;
    }
    $s = str_replace('T', ' ', $s);
    if (strlen($s) === 16) $s .= ':00';
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $s);
    $errors = DateTime::getLastErrors();
    if (!$dt || !empty($errors['warning_count']) || !empty($errors['error_count'])) {
        return null;
    }
    return $s;
}

function e(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
