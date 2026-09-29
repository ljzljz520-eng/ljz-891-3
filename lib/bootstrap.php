<?php
/**
 * 应用启动引导：错误处理、时区、安全响应头、自动初始化数据库。
 */
require_once dirname(__DIR__) . '/config.php';

date_default_timezone_set(TIMEZONE);

mb_internal_encoding('UTF-8');

if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    @ini_set('error_log', DATA_DIR . '/php-error.log');
}

if (!is_dir(DATA_DIR)) {
    @mkdir(DATA_DIR, 0750, true);
}

set_exception_handler(function (Throwable $e) {
    error_log('[uncaught] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'code' => 'server_error',
            'message' => APP_DEBUG ? $e->getMessage() : '服务器内部错误，请稍后再试',
        ], JSON_UNESCAPED_UNICODE);
    }
});

if (PHP_SAPI !== 'cli') {
    // 基础安全响应头（CSP 按页面实际需要放行静态资源）
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    header('Content-Security-Policy: "default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; base-uri \'self\'; form-action \'self\'"');
}

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/DB.php';
require_once __DIR__ . '/RateLimiter.php';
require_once __DIR__ . '/Captcha.php';
require_once __DIR__ . '/AdminAuth.php';

// 首次访问自动初始化数据库与示例数据
DB::init();
