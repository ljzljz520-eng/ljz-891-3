<?php
declare(strict_types=1);

/** JSON / CSV 输出与安全响应头 */
final class Out
{
    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('Content-Security-Policy: "none"');
        header('Cache-Control: no-store');
    }

    public static function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function ok(mixed $data = null, int $status = 200): never
    {
        self::json(['ok' => true, 'data' => $data], $status);
    }

    public static function fail(string $message, int $status = 400, ?int $retryAfter = null): never
    {
        if ($retryAfter !== null) {
            header('Retry-After: ' . max(1, $retryAfter));
        }
        self::json(['ok' => false, 'error' => $message], $status);
    }

    public static function exception(Throwable $e): never
    {
        if (Cfg::get('env') === 'development') {
            self::fail('服务器错误: ' . $e->getMessage(), 500);
        }
        error_log('[押金系统] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        self::fail('服务器内部错误，请稍后再试', 500);
    }
}
