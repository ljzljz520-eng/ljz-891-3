<?php
/**
 * SQLite 数据库：连接、建表、首次初始化（管理员 + 示例数据）。
 */
class DB
{
    private static ?PDO $pdo = null;
    private static ?string $key = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $file = DATA_DIR . '/app.sqlite';
            $pdo = new PDO('sqlite:' . $file, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            self::$pdo = $pdo;
            self::migrate($pdo); // 幂等建表，便于升级
            // 依据“管理员表是否为空”判断是否需要初始化，避免空文件/竞态导致漏种子
            if ((int)$pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn() === 0) {
                self::seed($pdo);
            }
        }
        return self::$pdo;
    }

    /** HMAC 密钥：环境变量优先，其次 data/key.php（0640），首次自动生成 */
    public static function key(): string
    {
        if (self::$key !== null) {
            return self::$key;
        }
        $env = defined('APP_KEY') ? APP_KEY : null;
        if (!empty($env)) {
            return self::$key = $env;
        }
        $file = DATA_DIR . '/key.php';
        if (file_exists($file)) {
            $stored = include $file;
            if (is_string($stored) && strlen($stored) >= 32) {
                return self::$key = $stored;
            }
        }
        $key = bin2hex(random_bytes(32));
        if (is_dir(DATA_DIR) && @file_put_contents($file, "<?php\nreturn " . var_export($key, true) . ";\n", LOCK_EX) !== false) {
            @chmod($file, 0640);
        }
        return self::$key = $key;
    }

    public static function init(): void
    {
        self::pdo();
    }

    private static function migrate(PDO $pdo): void
    {
        $statements = [
            "CREATE TABLE IF NOT EXISTS admins (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username VARCHAR(64) NOT NULL UNIQUE,
                password_hash VARCHAR(255) NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            )",
            "CREATE TABLE IF NOT EXISTS customers (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(64) NOT NULL,
                phone VARCHAR(20) NOT NULL UNIQUE,
                phone_hash CHAR(64) NOT NULL UNIQUE,
                created_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            )",
            "CREATE TABLE IF NOT EXISTS orders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_no VARCHAR(32) NOT NULL UNIQUE,
                customer_id INTEGER NOT NULL REFERENCES customers(id),
                rental_status TEXT NOT NULL DEFAULT 'renting',
                deposit_amount INTEGER NOT NULL,
                deposit_status TEXT NOT NULL DEFAULT 'held',
                deposit_deduction INTEGER NOT NULL DEFAULT 0,
                rental_start TEXT NOT NULL,
                rental_end TEXT,
                return_time TEXT,
                refund_time TEXT,
                is_abnormal INTEGER NOT NULL DEFAULT 0,
                abnormal_note TEXT NOT NULL DEFAULT '',
                customer_note TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now','localtime'))
            )",
            "CREATE INDEX IF NOT EXISTS idx_orders_customer ON orders(customer_id)",
            "CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(deposit_status, rental_status)",
            "CREATE TABLE IF NOT EXISTS order_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                name VARCHAR(128) NOT NULL,
                model VARCHAR(128) NOT NULL DEFAULT '',
                qty INTEGER NOT NULL DEFAULT 1,
                unit_price INTEGER NOT NULL DEFAULT 0,
                serial_no VARCHAR(128) NOT NULL DEFAULT ''
            )",
            "CREATE INDEX IF NOT EXISTS idx_items_order ON order_items(order_id)",
            "CREATE TABLE IF NOT EXISTS query_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at TEXT NOT NULL DEFAULT (datetime('now','localtime')),
                ip VARCHAR(45) NOT NULL,
                order_no VARCHAR(32) NOT NULL DEFAULT '',
                phone_last4 VARCHAR(4) NOT NULL DEFAULT '',
                result TEXT NOT NULL,
                code TEXT NOT NULL DEFAULT '',
                user_agent VARCHAR(255) NOT NULL DEFAULT '',
                admin_id INTEGER
            )",
            "CREATE INDEX IF NOT EXISTS idx_logs_created ON query_logs(created_at)",
            "CREATE INDEX IF NOT EXISTS idx_logs_result ON query_logs(result)",
            "CREATE TABLE IF NOT EXISTS rate_hits (
                bucket VARCHAR(190) NOT NULL,
                hit_time INTEGER NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_rate_bucket_time ON rate_hits(bucket, hit_time)",
            "CREATE TABLE IF NOT EXISTS admin_sessions (
                token_hash CHAR(64) PRIMARY KEY,
                admin_id INTEGER NOT NULL REFERENCES admins(id) ON DELETE CASCADE,
                csrf_token CHAR(64) NOT NULL,
                expires_at INTEGER NOT NULL,
                created_at INTEGER NOT NULL,
                last_seen INTEGER NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_sessions_admin ON admin_sessions(admin_id)",
            "CREATE TABLE IF NOT EXISTS captchas (
                id CHAR(64) PRIMARY KEY,
                answer VARCHAR(32) NOT NULL,
                expires_at INTEGER NOT NULL,
                used INTEGER NOT NULL DEFAULT 0,
                ip VARCHAR(45) NOT NULL,
                created_at INTEGER NOT NULL
            )",
            "CREATE INDEX IF NOT EXISTS idx_captchas_exp ON captchas(expires_at)",
        ];
        foreach ($statements as $sql) {
            $pdo->exec($sql);
        }
    }

    private static function seed(PDO $pdo): void
    {
        // 初始管理员
        $stmt = $pdo->prepare('INSERT OR IGNORE INTO admins (username, password_hash) VALUES (?, ?)');
        $stmt->execute([ADMIN_USERNAME, password_hash(ADMIN_PASSWORD, PASSWORD_DEFAULT)]);

        if (!SEED_DEMO) {
            return;
        }

        $phoneHash = fn(string $phone) => hash_hmac('sha256', $phone, self::key());

        // 两个示例客户
        $customers = [
            ['陈默', '13812345678'],
            ['林小夏', '13998765432'],
        ];
        $cu = $pdo->prepare('INSERT INTO customers (name, phone, phone_hash) VALUES (?, ?, ?)');
        foreach ($customers as [$name, $phone]) {
            $cu->execute([$name, $phone, $phoneHash($phone)]);
        }
        $cid1 = (int)$pdo->lastInsertId() - 1;
        $cid2 = $cid1 + 1;

        $orders = [
            [
                'RP7K2M9QX4NT', $cid1, 'renting', 800000, 'held', 0,
                '2026-09-25 10:00:00', '2026-10-05 18:00:00', null, null,
                0, '', '租期 10 天，请爱护器材',
            ],
            [
                'RP4W8X3F6VZK', $cid2, 'returned', 350000, 'refunded', 0,
                '2026-09-10 09:30:00', '2026-09-15 20:00:00',
                '2026-09-15 19:42:00', '2026-09-17 11:20:00',
                0, '', '已完成验收，押金原路退回',
            ],
        ];
        $ou = $pdo->prepare('INSERT INTO orders
            (order_no, customer_id, rental_status, deposit_amount, deposit_status, deposit_deduction,
             rental_start, rental_end, return_time, refund_time, is_abnormal, abnormal_note, customer_note)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        foreach ($orders as $o) {
            $ou->execute($o);
        }

        $items = [
            // 订单 1：租赁中
            [1, '索尼全画幅微单', 'A7M4 机身', 1, 38000, 'SN-A7M4-220711'],
            [1, 'G 大师变焦镜头', 'FE 24-70mm F2.8 GM II', 1, 15000, 'SN-2470GM-8801'],
            [1, '专业碳纤维三脚架', 'Gitzo GT3543LS', 1, 3000, ''],
            [1, '高速 SD 存储卡', 'SanDisk 128GB V90', 2, 800, ''],
            // 订单 2：已归还已退款
            [2, '佳能专业单反', 'EOS R5 机身', 1, 42000, 'SN-R5-660213'],
            [2, '定焦人像镜头', 'RF 85mm F1.2 L', 1, 12000, ''],
            [2, '机顶闪光灯', 'Godox V1', 1, 1500, ''],
        ];
        $iu = $pdo->prepare('INSERT INTO order_items (order_id, name, model, qty, unit_price, serial_no)
            VALUES (?,?,?,?,?,?)');
        foreach ($items as $it) {
            $iu->execute($it);
        }
    }
}
