-- 摄影器材租赁押金查询系统 - MySQL 8.0+
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(64)  NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rentals (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no         VARCHAR(32)  NOT NULL UNIQUE COMMENT '订单号(高熵随机)',
  customer_name    VARCHAR(64)  NOT NULL,
  phone_enc        TEXT         NOT NULL COMMENT '手机号 AES-256-GCM 密文',
  phone_hash       CHAR(64)     NOT NULL COMMENT '手机号 HMAC-SHA256 十六进制',
  deposit_amount   DECIMAL(10,2) NOT NULL,
  deposit_status   ENUM('held','refunding','refunded','deducted','abnormal') NOT NULL DEFAULT 'held'
                     COMMENT 'held=占用中 refunding=退款中 refunded=已退 deducted=已扣除 abnormal=异常',
  rent_start       DATE NOT NULL,
  rent_due         DATE NOT NULL,
  returned_at      DATETIME NULL COMMENT '实际归还时间',
  refund_time      DATETIME NULL COMMENT '退款到账时间',
  abnormal_flag    TINYINT(1) NOT NULL DEFAULT 0,
  abnormal_reason  VARCHAR(255) NOT NULL DEFAULT '',
  remark           VARCHAR(255) NOT NULL DEFAULT '',
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_phone_hash (phone_hash),
  KEY idx_status (deposit_status),
  KEY idx_abnormal (abnormal_flag)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS rental_items (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rental_id  BIGINT UNSIGNED NOT NULL,
  name       VARCHAR(128) NOT NULL COMMENT '设备名称',
  model      VARCHAR(128) NOT NULL DEFAULT '' COMMENT '型号',
  sn         VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '机身/序列号',
  qty        INT UNSIGNED NOT NULL DEFAULT 1,
  unit_price DECIMAL(10,2) NOT NULL DEFAULT 0 COMMENT '押金单价(参考)',
  KEY idx_rental (rental_id),
  CONSTRAINT fk_items_rental FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS query_logs (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no    VARCHAR(32) NOT NULL DEFAULT '',
  result      ENUM('ok','bad_order','bad_phone','bad_captcha','rate_locked','error') NOT NULL,
  ip          VARCHAR(45)  NOT NULL,
  ua          VARCHAR(255) NOT NULL DEFAULT '',
  admin_id    INT UNSIGNED NULL COMMENT '管理员操作时的操作者',
  action      VARCHAR(64)  NOT NULL DEFAULT 'customer_query' COMMENT 'query/create/update/abnormal/export/login...',
  detail      VARCHAR(255) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_no),
  KEY idx_result (result),
  KEY idx_time (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45) NOT NULL,
  username    VARCHAR(64) NOT NULL DEFAULT '',
  success     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ip (ip),
  KEY idx_user (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 初始管理员由 install.php 用 password_hash() 真实生成（见 lib/config.php 的 bootstrap_admin）。

CREATE TABLE IF NOT EXISTS rate_limits (
  bucket     VARCHAR(100) NOT NULL,
  expires_at DATETIME NOT NULL,
  KEY idx_bucket (bucket),
  KEY idx_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
