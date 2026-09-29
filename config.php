<?php
/**
 * 全局配置。
 * 生产环境优先通过环境变量覆盖密钥/初始密码；也可在部署时直接修改本文件。
 */

// 调试模式：生产请设为 0（错误详情不返回给客户端，仅写日志）
define('APP_DEBUG', getenv('APP_DEBUG') !== false ? (bool)getenv('APP_DEBUG') : false);

// 数据文件目录（SQLite、密钥、日志均放此处，Web 部署时该目录应在文档根之外）
define('DATA_DIR', dirname(__FILE__) . '/data');

// 初始管理员账号（仅当 admins 表为空时用于初始化）
define('ADMIN_USERNAME', getenv('ADMIN_USER') ?: 'admin');
define('ADMIN_PASSWORD', getenv('ADMIN_PASS') ?: 'Rent@2026');
define('SEED_DEMO', getenv('SEED_DEMO') !== false ? (bool)getenv('SEED_DEMO') : true);

// 首次初始化时若无 APP_KEY 环境变量，会自动生成并写入 data/key.php
define('APP_KEY', getenv('APP_KEY') ?: null);

// ---- 客户查询接口：防爆破限流 ----
define('QUERY_LOCK_MAX_FAIL', 5);          // 同一 IP 或同一订单 15 分钟内最多失败 5 次
define('QUERY_LOCK_WINDOW', 900);          // 失败计数窗口 / 锁定时长（秒）
define('CAPTCHA_LIMIT_MAX', 30);           // 同一 IP 每分钟最多获取 30 次验证码
define('CAPTCHA_TTL', 300);                // 验证码有效期 5 分钟

// ---- 后台登录限流 ----
define('LOGIN_MAX_FAIL', 5);
define('LOGIN_WINDOW', 900);

// ---- 管理员会话 ----
define('SESSION_TTL', 12 * 3600);          // 会话 12 小时，滑动过期
define('SESSION_COOKIE', 'rp_sid');

define('TIMEZONE', 'Asia/Shanghai');
