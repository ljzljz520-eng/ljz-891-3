<?php
declare(strict_types=1);


/** 极简 PDO 封装 */
final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $path = Cfg::get('db.path');
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('无法创建数据库目录: ' . $dir);
        }
        $fresh = !file_exists($path);
        $pdo = new PDO('sqlite:' . $path, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 3000');
        self::$pdo = $pdo;
        if ($fresh) {
            self::migrate();
        } else {
            self::ensureSchema();
        }
        return $pdo;
    }

    /** 首次运行自动建表（CLI 与 Web 都安全） */
    public static function migrate(): void
    {
        $sql = file_get_contents(dirname(__DIR__) . '/schema.sqlite.sql');
        if ($sql === false) {
            throw new RuntimeException('找不到 schema.sqlite.sql');
        }
        self::pdo()->exec($sql);
    }

    private static bool $checked = false;

    /** 防止误删库后无表：每次进程只检查一次 */
    private static function ensureSchema(): void
    {
        if (self::$checked) {
            return;
        }
        self::$checked = true;
        $count = (int) self::pdo()
            ->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='rentals'")
            ->fetchColumn();
        if ($count === 0) {
            self::migrate();
        }
    }

    /** 显式按值类型绑定，避免 NULL 被当字符串 */
    public static function run(string $sql, array $params = []): PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        foreach ($params as $name => $value) {
            $key = is_int($name) ? $name + 1 : $name;
            $type = match (true) {
                is_int($value)  => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default         => PDO::PARAM_STR,
            };
            $stmt->bindValue($key, is_bool($value) ? ($value ? 1 : 0) : $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::run($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::run($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function scalar(string $sql, array $params = []): mixed
    {
        return self::run($sql, $params)->fetchColumn();
    }

    public static function insert(string $sql, array $params = []): int
    {
        self::run($sql, $params);
        return (int) self::pdo()->lastInsertId();
    }

    /** 逐行游标读取（导出用） */
    public static function cursor(string $sql, array $params = []): Generator
    {
        $stmt = self::run($sql, $params);
        while ($row = $stmt->fetch()) {
            yield $row;
        }
    }

    public static function begin(): void { self::pdo()->beginTransaction(); }
    public static function commit(): void { self::pdo()->commit(); }
    public static function rollback(): void
    {
        if (self::pdo()->inTransaction()) {
            self::pdo()->rollBack();
        }
    }
}
