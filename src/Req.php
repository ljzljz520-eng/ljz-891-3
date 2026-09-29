<?php
declare(strict_types=1);

/** 请求工具 */
final class Req
{
    /** 取客户端 IP（无反向代理时 REMOTE_ADDR 最可信） */
    public static function ip(): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        // 如部署在可信代理后，可按需在此扩展 X-Forwarded-For 解析
        return trim($ip);
    }

    public static function ua(): string
    {
        return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public static function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    /** 读取 JSON 请求体 */
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === '' || $raw === false) {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }

    /** 兼容 Authorization: Bearer 与 X-Auth-Token 两种方式 */
    public static function bearer(): string
    {
        $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
        if ($header === '' && function_exists('apache_request_headers')) {
            foreach (apache_request_headers() as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $header = trim((string)$v);
                    break;
                }
            }
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return $m[1];
        }
        return trim((string)($_SERVER['HTTP_X_AUTH_TOKEN'] ?? ''));
    }
}
