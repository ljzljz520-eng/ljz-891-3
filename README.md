# 摄影器材租赁押金查询系统

面向摄影器材租赁门店的押金自助查询 + 后台管理系统。客户凭**订单号 + 预留手机号 + 算术验证码**查询押金金额、归还状态、设备清单与退款时间；工作人员在后台录入租赁记录、标记异常、导出查询日志。

## 功能一览

### 客户端（`/`）
- 输入订单号、手机号并完成算术验证码后查询
- 展示：押金金额、押金状态、归还状态、租期、设备清单、实际归还时间、退款时间及退款说明
- 只返回必要信息：不显示客户姓名、完整手机号、内部异常备注

### 管理后台（`/admin/`）
- 账密 + 验证码登录，失败 5 次锁定 15 分钟，会话 12 小时滑动过期
- 租赁记录：录入（自动生成随机订单号）、搜索/筛选、编辑、设备清单维护
- 一键标记/解除异常（异常必须填写内部说明，客户不可见），可联动调整押金状态
- 查询日志：多条件筛选、分页、CSV 导出（UTF-8 BOM，Excel 直接打开）
- 所有登录、查询、增改、导出动作均落审计日志

## 防“猜订单号看全部信息”的设计（重点）

1. **双因子查询**：必须同时提供订单号与预留手机号，任一不匹配返回**完全相同**的笼统提示与 404，不区分“订单不存在/手机号错误”。
2. **订单号不可猜**：服务端生成 `RP + 10 位`（去除易混淆字符的 30 字符表）随机串，非自增、非日期编码。
3. **手机号哈希比对**：库内存 HMAC-SHA256（密钥存放 `data/key.php`，可环境变量 `APP_KEY` 覆盖），用 `hash_equals` 恒定时间比较。
4. **时序攻击防护**：订单不存在时也执行一次等价哈希比较，避免通过响应时间判断订单是否存在。
5. **双维度限流**：同一 IP、同一订单各自计数，15 分钟内失败 5 次即锁 15 分钟；换 IP 爆破同一订单同样被拦。
6. **算术验证码**：服务端生成、5 分钟有效、**一次性消费**（成败均作废）、绑定获取时 IP；验证码接口本身限流。
7. **最小化响应**：客户接口不返回手机号、姓名、异常备注等内部字段；日志只记手机号后四位。
8. **后台独立鉴权**：随机不透明会话令牌（Cookie 仅 HttpOnly + SameSite=Strict，HTTPS 下自动 Secure），服务端仅存令牌哈希；写操作强制校验与会话绑定的 CSRF 令牌。
9. **其他**：安全响应头（CSP / X-Frame-Options / nosniff / Referrer-Policy）、路径穿越拦截、PDDO 预处理语句防注入、金额按“分”整数存储、生产模式不回显错误详情。

## 目录结构

```
config.php              全局配置（可用环境变量覆盖）
router.php             内置服务器路由（拦截路径穿越）
lib/
  bootstrap.php         启动引导（错误处理/安全头/初始化）
  helpers.php           通用函数（校验、金额、脱敏、时间等）
  DB.php                SQLite 连接、建表、首次种子
  RateLimiter.php       固定窗口限流器
  Captcha.php           算术验证码
  AdminAuth.php         后台会话与 CSRF
  admin_api.php         后台守卫与订单表单校验
public/
  index.html            客户查询页
  admin/index.html      后台管理页
  assets/               样式与前端脚本
  api/captcha.php       客户验证码
  api/query.php         客户押金查询（核心安全接口）
  api/admin/            登录/登出/会话/订单/异常/日志
scripts/
  serve.sh              启动内置服务器（开发）
  admin.php             命令行：创建/重置管理员、列用户、轮换密钥
tests/
  api_test.sh           端到端接口测试（36 项断言）
  db_helper.php         测试用 DB 辅助脚本
data/                   SQLite 数据库与密钥（不入库、不放文档根）
```

## 快速开始（本机已内置 aarch64 静态 PHP 8.5，位于 `.tools/php`）

```bash
./scripts/serve.sh
# 客户页 http://127.0.0.1:8080/
# 后台   http://127.0.0.1:8080/admin/
```

演示账号与示例订单（**仅限演示，生产请立即改密**）：

| 用途 | 值 |
|---|---|
| 后台账号 / 密码 | `admin` / `Rent@2026` |
| 示例订单 1（租赁中） | `RP7K2M9QX4NT` + 手机号 `13812345678` |
| 示例订单 2（已退款） | `RP4W8X3F6VZK` + 手机号 `13998765432` |

环境变量：`ADMIN_USER`、`ADMIN_PASS`（首次初始化管理员）、`APP_KEY`（HMAC 密钥）、`APP_DEBUG`、`SEED_DEMO`（是否写入示例数据）。

### 管理员工具

```bash
php scripts/admin.php pass:set <用户名> <至少8位新密码>  # 创建或重置管理员（同时令所有会话失效）
php scripts/admin.php user:list
php scripts/admin.php rekey                              # 轮换 HMAC 密钥（自动重算手机号哈希）
```

## 运行测试

```bash
./scripts/serve.sh            # 先启动服务
./tests/api_test.sh           # 36 项端到端断言
```

## 生产部署要点

- 用 Nginx/Apache + PHP-FPM，**文档根指向 `public/`**，确保 `data/`、`config.php`、`lib/` 无法通过 URL 访问（见下）。
- 设置环境变量 `ADMIN_PASS` 与强随机 `APP_KEY`（例如 `openssl rand -hex 32`），关闭 `APP_DEBUG` 与 `SEED_DEMO`。
- 全站 HTTPS（Cookie 会自动带 `Secure`）。
- `data/` 目录权限建议 `0750`、`data/key.php` 与 SQLite 文件 `0640`，属主为 PHP-FPM 运行用户。
- 高并发部署建议把 SQLite 换为 MySQL/PostgreSQL（PDO 已隔离，调整 `DB.php` 即可；限流表建议引入 Redis）。
- 反向代理后如需取真实 IP，应在代理层强制覆写 `X-Forwarded-For` 为可信对端地址；本系统默认只信 `REMOTE_ADDR`，避免伪造头绕过限流。

### Nginx 示例

```nginx
server {
    listen 443 ssl;
    server_name deposit.example.com;
    root /var/www/rental/public;
    index index.html;

    # 只允许 public 内的资源与 API
    location / { try_files $uri $uri/ /index.html; }
    location ^~ /api/ {
        try_files $uri =404;
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
      }
    # data 与上级目录天然不在文档根内；再加一道保险
    location ~ /\.\. { deny all; return 404; }
}
```

## API 摘要

| 方法 | 路径 | 鉴权 | 说明 |
|---|---|---|---|
| GET | `/api/captcha.php` | 无（限流） | 获取算术验证码 |
| POST | `/api/query.php` | 订单号+手机号+验证码 | 客户押金查询 |
| POST | `/api/admin/login.php` | 账密+验证码 | 后台登录 |
| POST | `/api/admin/logout.php` | 会话+CSRF | 退出 |
| GET | `/api/admin/me.php` | 会话 | 当前会话 |
| GET | `/api/admin/orders.php` | 会话 | 列表/筛选/详情 |
| POST | `/api/admin/orders.php` | 会话+CSRF | 录入租赁记录 |
| PUT | `/api/admin/orders.php?order_no=` | 会话+CSRF | 编辑订单 |
| POST | `/api/admin/abnormal.php?order_no=` | 会话+CSRF | 标记/解除异常 |
| GET | `/api/admin/logs.php` | 会话 | 日志列表 |
| GET | `/api/admin/logs.php?format=csv` | 会话 | 导出 CSV |
