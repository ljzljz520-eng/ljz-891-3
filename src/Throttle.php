<?php
declare(strict_types=1);

/**
 * 基于 SQLite 的固定窗口限流。
 * bucket 命名带用途前缀，避免串桶。
 */
final class Throttle
{
    /**
     * 检查并计数。
     * @return array{allowed:bool,retry_after:int,remaining:int}
     */
    public static function hit(string $bucket, int $max, int $window): array
    {
        $now = time();
        $reset = $now + $window;
        // 过期窗口直接重建（UPSERT）
        DB::run(
            'INSERT INTO throttle (bucket, hits, reset_at)
             VALUES (:b, 1, :r)
             ON CONFLICT(bucket) DO UPDATE SET
                hits = CASE WHEN throttle.reset_at <= :now THEN 1 ELSE throttle.hits + 1 END,
                reset_at = CASE WHEN throttle.reset_at <= :now THEN :r ELSE throttle.reset_at END',
            ['b' => $bucket, 'r' => $reset, 'now' => $now]
        );
        $row = DB::one('SELECT hits, reset_at FROM throttle WHERE bucket = :b', ['b' => $bucket]);
        $hits = (int)($row['hits'] ?? 1);
        $resetAt = (int)($row['reset_at'] ?? $reset);
        $allowed = $hits <= $max;
        return [
            'allowed'     => $allowed,
            'retry_after' => $allowed ? 0 : max(1, $resetAt - $now),
            'remaining'   => max(0, $max - $hits),
        ];
    }

    /** 只计数、不拦截（用于失败桶，拦截由检查处统一处理） */
    public static function count(string $bucket, int $max, int $window): array
    {
        return self::hit($bucket, $max, $window);
    }

    /** 只检查当前计数，不递增 */
    public static function peek(string $bucket, int $max, int $window): array
    {
        $now = time();
        $row = DB::one('SELECT hits, reset_at FROM throttle WHERE bucket = :b', ['b' => $bucket]);
        $hits = (int)($row['hits'] ?? 0);
        $resetAt = (int)($row['reset_at'] ?? ($now + $window));
        if ($resetAt <= $now) {
            $hits = 0;
        }
        $blocked = $hits >= $max;
        return [
            'blocked'     => $blocked,
            'hits'        => $hits,
            'retry_after' => $blocked ? max(1, $resetAt - $now) : 0,
        ];
    }

    /** 仅失败时 +1；成功查询永不计数（用于同订单撞库桶） */
    public static function bump(string $bucket, int $window): void
    {
        $now = time();
        DB::run(
            'INSERT INTO throttle (bucket, hits, reset_at)
             VALUES (:b, 1, :r)
             ON CONFLICT(bucket) DO UPDATE SET
                hits = CASE WHEN throttle.reset_at <= :now THEN 1 ELSE throttle.hits + 1 END,
                reset_at = CASE WHEN throttle.reset_at <= :now THEN :r ELSE throttle.reset_at END',
            ['b' => $bucket, 'r' => $now + $window, 'now' => $now]
        );
    }

    public static function clearExpired(): void
    {
        DB::run('DELETE FROM throttle WHERE reset_at < :now', ['now' => time()]);
    }
}
