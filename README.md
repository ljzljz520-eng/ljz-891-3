# 摄影器材租赁 · 押金查询系统

客户凭 **订单号 + 下单手机号** 查询押金金额、归还状态、设备清单和退款时间；
后台可录入租赁记录、标记异常、登记退款，并导出客户查询日志与查看操作审计。

- 后端：PHP 8.0+（PDO / SQLite，无框架）
- 前端：原生 HTML + CSS + JS（无构建步骤）
- 金额：数据库统一存「分」(INTEGER)，接口输出两位小数字符串

---

## 一、目录结构

```
├── public/                ← 唯一 Web 根目录（DocumentRoot 指向这里）
│   ├── index.html         客户查询页
│   ├── admin/index.html   后台管理页
│   ├── assets/            CSS / JS
│   └── api/
│       ├── deposit.php            客户查询接口（公开）
│       └── admin/*.php            后台接口（全部 Bearer Token 鉴权）
├── src/                   PHP 类（Cfg/DB/Req/Out/V/Sec/Throttle/AdminAuth/Audit/Api）
├── config/config.php      配置
├── config/.app.key        首次运行自动生成的密钥（600，不入 git）
├── storage/db/app.sqlite  SQLite 数据库（首次访问/setup 自动建表）
├── schema.sqlite.sql      建表 SQL
├── bin/setup.php          CLI：初始化 / 创建管理员
└── deploy/nginx.conf.example
```

> `config/`、`storage/`、`src/` 都在 Web 根目录之外，URL 无法直接访问。

---

## 二、快速开始

```bash
# 1. 初始化（建表 + 生成密钥）
php bin/setup.php init

# 2. 创建后台账号（密码至少 10 位）
php bin/setup.php admin shopowner 'S3cure!Passw0rd' '门店管理员'

# 3. 起服务（开发）
php -S 127.0.0.1:8765 -t public
```

- 客户页：<http://127.0.0.1:8765/>
- 后台页：<http://127.0.0.1:8765/admin/>

生产用 Nginx + PHP-FPM，参考 `deploy/nginx.conf.example`；**root 必须指向 `public/`**。

---

## 三、接口说明

### 客户侧（公开，但有严格限流）

| 方法 | 路径 | 入参 |
|---|---|---|
| POST | `/api/deposit.php` | `{ "order_no": "...", "phone": "13812345678" }` |

成功返回押金、归还状态、设备清单（不含序列号）、退款状态/金额/到账时间。
订单号或手机号任一不匹配，统一返回 `404 未查询到订单`，**不区分是哪一项错误**。

### 后台侧（除 login 外都需要 `Authorization: Bearer <token>`）

| 方法 | 路径 | 说明 |
|---|---|---|
| POST | `/api/admin/login.php` | 登录，返回 token |
| POST | `/api/admin/logout.php` | 吊销当前 token |
| GET  | `/api/admin/me.php` | 当前登录账号 |
| GET  | `/api/admin/rentals.php` | 列表（分页 + q/status/refund_status/phone 筛选） |
| GET  | `/api/admin/rental.php?id=` | 详情 |
| POST | `/api/admin/rental-create.php` | 录入租赁（自动生成非顺序订单号） |
| PUT  | `/api/admin/rental-update.php` | 编辑基础信息/设备/押金 |
| POST | `/api/admin/return.php` | 标记归还（写归还时间） |
| POST | `/api/admin/exception.php` | 标记异常（内部备注 + 客户提示分离） |
| POST | `/api/admin/refund.php` | 登记退款（退款中/已退款/部分扣除，金额不可超押金） |
| GET  | `/api/admin/logs.php` | 查询日志（结果/订单号/时间段筛选） |
| GET  | `/api/admin/logs-export.php` | 导出 CSV（鉴权后） |
| GET  | `/api/admin/audits.php` | 后台操作审计 |

业务约束：
- 未归还（或未确认异常）不能登记退款；
- 退款金额必须在 0 ~ 押金之间；
- 异常订单归还后保留「异常」状态，内部异常备注不会出现在客户接口；
- 全额退款的正常订单自动进入「已关闭」。

---

## 四、「不能靠猜订单号看到信息」是怎么实现的

1. **双因子查询**：订单号 + 下单手机号哈希同时匹配（`WHERE order_no=? AND phone_hash=?`），
   单有订单号查不到任何东西。
2. **订单号不可枚举**：`RC + 日期(6) + 随机(8)`，随机空间约 1.1 万亿，且不连续（不自增）。
3. **手机号不裸存、不进日志**：
   - 库里只存 HMAC-SHA256 哈希（校验用）+ AES-256-GCM 密文（后台展示用），无明文列；
   - 查询日志只存脱敏号 `138****5678`，IP 只存 HMAC。
4. **错误信息无差别**：订单不存在 / 手机号不匹配返回同一句话，防探测。
5. **多维限流**（固定窗口，可在 `config/config.php` 调整）：
   - 同 IP 每分钟最多 30 次查询；
   - 同 IP 查询失败 10 分钟最多 8 次（`404/400` 都计入）；
   - 同 IP 对**同一订单号** 10 分钟最多 6 次尝试，超出返回 `429 + Retry-After`；
   - 后台登录同 IP 30 分钟最多 10 次；写操作每分钟 60 次。
6. **后台鉴权**：bcrypt 密码（登录不存在账号也跑一次 hash_verify，防时序探测），
   64 位随机 Bearer Token，服务端可吊销，12 小时滑动过期。
7. **其它**：全部 SQL 用 PDO 预处理参数绑定；安全响应头 `nosniff / DENY / no-referrer`；
   只接受 POST 查询（GET 405），后台页 `noindex`；无 CORS、无 cookie，降低跨站面。

---

## 五、运维提示

- **务必备份并保护** `config/.app.key`：丢了以后历史手机号密文无法解密、哈希也会失效；
  泄露则手机号哈希可被离线碰撞。换密钥等于需要重新写入手机号。
- 备份：直接备份 `storage/db/app.sqlite`（WAL 模式下建议用 `sqlite3 app.sqlite ".backup ..."`）。
- 生产把 `config/config.php` 里 `env` 设为 `production`（默认），错误细节不会返回给客户端。
- 日志清理：`query_logs` / `audit_logs` / `throttle` 长期增长，建议定时归档；
  可用 `DELETE FROM throttle WHERE reset_at < strftime('%s','now')` 清理过期桶。
- 如需 MySQL：把 schema 改为 InnoDB 等价结构、`DB::pdo()` 换 MySQL DSN，
  限流 UPSERT 改用 MySQL 的 `ON DUPLICATE KEY UPDATE`（其余业务代码不用动）。
