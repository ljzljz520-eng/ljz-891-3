# 摄影器材租赁押金查询系统

客户凭**订单号 + 下单手机号**查询押金金额、归还状态、设备清单、退款时间；
后台录入租赁记录、标记异常、登记归还/退款、导出查询日志。

## 一、它防什么

| 威胁 | 防护手段 |
|---|---|
| 猜到/遍历订单号看别人押金 | 订单号 50bit 随机熵（`RC+日期+10位Crockford`），**且必须再匹配手机号**（HMAC 盲索引比对），双因子 |
| 订单号枚举 | 错误统一回复“订单号或手机号不匹配”，不区分哪种错；订单号不可递增 |
| 暴力撞手机号 | 同一 IP、同一订单号双维度滑动窗口限流，超限锁定 30 分钟 |
| 后台被爆破 | 登录失败 5 次锁 15 分钟（IP + 账号双维度），全部登录写审计日志 |
| CSRF | 后台写操作强制 token 校验（`X-CSRF-Token`） |
| 手机号拖库 | 手机号 **AES-256-GCM 加密存储**，另存 HMAC-SHA256 盲索引供精确查询；库内无明文 |
| 越权数据接口 | 所有后台 API 服务端校验 session；客户接口只返回必要字段，序列号打码 |
| 异常原因外泄 | 客户侧只看到“订单异常”，具体原因仅后台可见 |
| 敏感文件下载 | `lib/ data/ sql/` 有 `.htaccess` + Nginx 规则 + router 三重拦截 |
| 错误信息泄漏 | 全局异常兜底，客户端只见通用提示，堆栈只进服务端日志 |

## 二、目录结构

```
├── index.html            客户查询页
├── assets/               客户页样式与脚本
├── api/
│   └── query.php         客户查询接口 POST
├── admin/
│   ├── index.html        后台 SPA（订单/异常/日志三个标签页）
│   └── api/
│       ├── login.php  logout.php  me.php  password.php
│       ├── rentals.php   租赁记录 列表/详情/新建/更新
│       ├── abnormal.php  标记/解除异常
│       └── logs.php      查询日志分页 + CSV 流式导出
├── lib/                  配置、DB、安全、限流（禁止 web 访问）
├── sql/schema.mysql.sql  MySQL 建表脚本
├── install.php           一键建库（部署后删除）
└── router.php            开发服务器路由/敏感路径兜底
```

## 三、本地运行（SQLite，零依赖）

```bash
php install.php                       # 建 data/app.sqlite 与管理员
php -S 0.0.0.0:8080 router.php        # 访问 http://localhost:8080
# 默认管理员 admin / admin@2026，登录后立刻在右上角改密码
```

## 四、生产部署（MySQL + Nginx + PHP-FPM）

1. 建库：
   ```sql
   CREATE DATABASE rental_deposit CHARACTER SET utf8mb4;
   CREATE USER 'rental'@'127.0.0.1' IDENTIFIED BY '强密码';
   GRANT ALL ON rental_deposit.* TO 'rental'@'127.0.0.1';
   ```
   `mysql rental_deposit < sql/schema.mysql.sql`
2. 设环境变量（或改 `lib/config.php`）：
   ```bash
   export APP_DB_DRIVER=mysql
   export DB_HOST=127.0.0.1 DB_NAME=rental_deposit DB_USER=rental DB_PASS='强密码'
   export APP_KEY=$(php -r 'echo bin2hex(random_bytes(32));')   # 必须改！
   export ADMIN_PASS='另一个强初始密码'                           # 首次安装用
   php install.php
   ```
3. Nginx 参考 `sql/nginx.conf.example`：务必全站 HTTPS、拦截 `lib/ data/ sql/ install.php`。
4. 删除 `install.php`（或 deny）。`data/` 目录放到 webroot 外更稳妥。
5. 后台首次登录立即改密码（10 位以上、含字母数字）。

> 更换 `APP_KEY` 后旧密文无法解密，请先备份密钥。

## 五、接口速览

客户：`POST /api/query.php` `{order_no, phone}` → 押金/状态/归还/退款/设备清单

后台（Cookie session + CSRF）：
- `POST admin/api/login.php` `{username,password}` → `{csrf}`
- `GET  admin/api/rentals.php?page&status&keyword&abnormal=1`
- `GET  admin/api/rentals.php?id=`（手机号解密可见，写审计）
- `POST admin/api/rentals.php` 新建（订单号服务端生成）
- `POST admin/api/rentals.php` `{_method:"PUT", id, deposit_status, returned_at, refund_time}` 更新
- `POST admin/api/abnormal.php` `{id, abnormal, reason}`
- `GET  admin/api/logs.php?page&result&action&order_no&from&to`
- `GET  admin/api/logs.php?export=csv&...`（导出上限 10 万行，带操作审计）

## 六、押金状态机

`held` 占用中 → `refunding` 退款中 → `refunded` 已退还
                                    ↘ `deducted` 已扣除
任何环节 → `abnormal` 异常（标记异常时联动），处理完解除并改回实际状态。
