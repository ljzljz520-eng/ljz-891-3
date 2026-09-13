<?php
declare(strict_types=1);
/**
 * 开发服务器路由器: php -S host:port router.php
 * 同时也是“纵深防御”示例：即使 web 服务器误配，敏感目录也不会被下载。
 * 生产请用 Nginx/Apache 规则（见 sql/nginx.conf.example）。
 */
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');

// 敏感路径一律 404
if (preg_match('#^/(lib|data|sql)/#', $uri)
    || str_contains($uri, '/.')
    || in_array(basename($uri), ['router.php', 'install.php'], true)) {
    http_response_code(404);
    exit('Not Found');
}

// 真实存在的静态文件直接返回
$file = __DIR__ . $uri;
if ($uri === '/') {
    require __DIR__ . '/index.html';
    return true;
}
if (is_file($file)) {
    return false;
}

// /admin/ -> admin/index.html
if ($uri === '/admin' || $uri === '/admin/') {
    require __DIR__ . '/admin/index.html';
    return true;
}

// 其余交给 PHP 脚本（内置服务器按文件扩展名解析 .php）
if (is_file($file . '.php')) {
    require $file . '.php';
    return true;
}
if (is_file($file)) {
    return false;
}
http_response_code(404);
exit('Not Found');
