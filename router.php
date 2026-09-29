<?php
/**
 * 开发用路由器（仅 PHP 内置服务器使用）：
 * 1. 拦截路径穿越，任何指向文档根之外的请求一律 404；
 * 2. 其余交给内置服务器按 public/ 处理。
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$root = realpath(__DIR__ . '/public');
$path = realpath($root . $uri);

if ($uri !== '/' && ($path === false || !str_starts_with($path, $root))) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '404 Not Found';
    return true;
}
return false; // 交给内置服务器继续处理
