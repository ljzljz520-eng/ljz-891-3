-- 押金查询系统 SQLite 结构。金额单位统一为「分」（INTEGER），避免浮点误差。

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS admins (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    username      TEXT NOT NULL UNIQUE,
    password_hash TEXT NOT NULL,
    display_name  TEXT NOT NULL DEFAULT '',
    created_at    TEXT NOT NULL DEFAULT (datetime('now'))
);

CREATE TABLE IF NOT EXISTS rentals (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    order_no           TEXT NOT NULL UNIQUE,          -- 非顺序订单号，不可枚举
    customer_name      TEXT NOT NULL DEFAULT '',
    phone_hash         TEXT NOT NULL,                 -- HMAC-SHA256 哈希，用于校验身份
    phone_enc          TEXT NOT NULL,                 -- AES-256-GCM 密文，供后台展示
    deposit_cents      INTEGER NOT NULL,              -- 押金（分）
    status             TEXT NOT NULL DEFAULT 'active'
                       CHECK (status IN ('active','returned','abnormal','closed')),
    items_json         TEXT NOT NULL DEFAULT '[]',    -- 设备清单
    rented_at          TEXT NOT NULL,                 -- 起租时间
    due_at             TEXT,                          -- 应还时间
    returned_at        TEXT,                          -- 实际归还时间
    exception_note     TEXT NOT NULL DEFAULT '',      -- 异常说明（仅后台）
    public_note        TEXT NOT NULL DEFAULT '',      -- 对客户可见的备注
    refund_status      TEXT NOT NULL DEFAULT 'unpaid'
                       CHECK (refund_status IN ('unpaid','processing','refunded','deducted')),
    refund_cents       INTEGER,                       -- 实际退款金额（分）
    refunded_at        TEXT,
    created_at         TEXT NOT NULL DEFAULT (datetime('now')),
    updated_at         TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_rentals_phone_hash ON rentals(phone_hash);
CREATE INDEX IF NOT EXISTS idx_rentals_status ON rentals(status);
CREATE INDEX IF NOT EXISTS idx_rentals_created ON rentals(created_at);

CREATE TABLE IF NOT EXISTS query_logs (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    order_input  TEXT NOT NULL DEFAULT '',
    phone_input  TEXT NOT NULL DEFAULT '',  -- 脱敏后的输入，不留存完整手机号
    ip_hash      TEXT NOT NULL DEFAULT '',
    user_agent   TEXT NOT NULL DEFAULT '',
    result       TEXT NOT NULL,             -- success / not_found / invalid
    http_status  INTEGER NOT NULL,
    rental_id    INTEGER,
    created_at   TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (rental_id) REFERENCES rentals(id)
);
CREATE INDEX IF NOT EXISTS idx_logs_created ON query_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_logs_result ON query_logs(result);
CREATE INDEX IF NOT EXISTS idx_logs_order ON query_logs(order_input);

CREATE TABLE IF NOT EXISTS admin_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id   INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    ip         TEXT NOT NULL DEFAULT '',
    user_agent TEXT NOT NULL DEFAULT '',
    last_used  TEXT NOT NULL DEFAULT (datetime('now')),
    expires_at TEXT NOT NULL,
    revoked    INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (admin_id) REFERENCES admins(id)
);
CREATE INDEX IF NOT EXISTS idx_tokens_admin ON admin_tokens(admin_id);

CREATE TABLE IF NOT EXISTS audit_logs (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    admin_id   INTEGER,
    username   TEXT NOT NULL DEFAULT '',
    action     TEXT NOT NULL,
    target     TEXT NOT NULL DEFAULT '',
    detail     TEXT NOT NULL DEFAULT '',
    ip         TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL DEFAULT (datetime('now'))
);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_logs(created_at);

-- 限流计数（SQLite UPSERT 需要 3.24+，PHP 自带 libsqlite 满足）
CREATE TABLE IF NOT EXISTS throttle (
    bucket     TEXT NOT NULL,
    hits       INTEGER NOT NULL DEFAULT 0,
    reset_at   INTEGER NOT NULL,
    PRIMARY KEY (bucket)
);
