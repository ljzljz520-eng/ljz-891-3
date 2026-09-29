<?php
declare(strict_types=1);

/**
 * 全局配置。生产环境可把敏感项改为从环境变量读取。
 */
return [
    'app_name'   => '光影租赁 · 押金查询系统',
    'env'        => getenv('APP_ENV') ?: 'production',

    // 密钥文件（用于手机号 HMAC 与可逆加密），首次访问自动生成
    'key_file'   => dirname(__DIR__) . '/config/.app.key',

    'db' => [
        // 仅支持 SQLite（PDO）。MySQL 适配见 README
        'path' => dirname(__DIR__) . '/storage/db/app.sqlite',
    ],

    // 后台登录态（秒）：12 小时滑动过期
    'admin_session_ttl' => 12 * 3600,

    // 后台登录失败：同 IP 30 分钟内最多 10 次
    'admin_login_throttle' => ['max' => 10, 'window' => 1800],

    // 客户查询限流
    'throttle' => [
        // 同 IP：每分钟最多 30 次查询请求（含成功失败）
        'ip'       => ['max' => 30, 'window' => 60],
        // 同 IP 失败（订单/手机号不匹配或校验失败）：10 分钟最多 8 次
        'ip_fail'  => ['max' => 8,  'window' => 600],
        // 同一订单号被同 IP 连续撞：10 分钟最多 6 次
        'order'    => ['max' => 6,  'window' => 600],
        // 后台写操作：同 IP 每分钟 60 次
        'admin_ip' => ['max' => 60, 'window' => 60],
    ],
];
