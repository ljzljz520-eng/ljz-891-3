<?php
/**
 * 基于 SQLite 的固定窗口限流器。
 * 设计要点：所有限流判断都在服务端，键中包含 IP 与业务标识，客户端无法伪造 XFF 绕过。
 */
class RateLimiter
{
    public function __construct(private PDO $pdo) {}

    private function gc(): void
    {
        // 10% 概率顺手清理过期计数，避免表膨胀
        if (random_int(1, 10) === 1) {
            $this->pdo->prepare('DELETE FROM rate_hits WHERE hit_time < ?')->execute([time() - 3600]);
        }
    }

    /** 窗口内已命中次数（自动清除过期） */
    public function count(string $bucket, int $window): int
    {
        $this->gc();
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM rate_hits WHERE bucket = ? AND hit_time > ?');
        $stmt->execute([$bucket, time() - $window]);
        return (int)$stmt->fetchColumn();
    }

    /** 记一次命中 */
    public function hit(string $bucket): void
    {
        $this->pdo->prepare('INSERT INTO rate_hits (bucket, hit_time) VALUES (?, ?)')->execute([$bucket, time()]);
    }

    /** 命中 +1 并返回当前次数 */
    public function bump(string $bucket): int
    {
        $this->hit($bucket);
        return $this->count($bucket, 86400 * 30);
    }

    /** 清除窗口内计数（成功后放行后续正常查询） */
    public function reset(string $bucket, int $window): void
    {
        $this->pdo->prepare('DELETE FROM rate_hits WHERE bucket = ? AND hit_time > ?')
            ->execute([$bucket, time() - $window]);
    }

    /** 距离窗口中最早一次命中过去后剩余的秒数（用于 Retry-After 提示） */
    public function retryAfter(string $bucket, int $window): int
    {
        $stmt = $this->pdo->prepare('SELECT MIN(hit_time) FROM rate_hits WHERE bucket = ? AND hit_time > ?');
        $stmt->execute([$bucket, time() - $window]);
        $oldest = (int)$stmt->fetchColumn();
        return max(1, $window - (time() - $oldest));
    }
}
