<?php
declare(strict_types=1);

/**
 * 统一引导：加载配置、自动加载、错误处理、建库。
 * 所有接口入口第一行 require 本文件。
 */

date_default_timezone_set('Asia/Shanghai');

spl_autoload_register(static function (string $class): void {
    $file = __DIR__ . '/src/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

Cfg::load(require __DIR__ . '/config/config.php');

set_exception_handler(static function (Throwable $e): void {
    if (!headers_sent()) {
        Out::securityHeaders();
    }
    Out::exception($e);
});

DB::pdo(); // 首次访问自动建表
Sec::key(); // 首次访问自动生成密钥

if (PHP_SAPI !== 'cli') {
    Out::securityHeaders();
}
