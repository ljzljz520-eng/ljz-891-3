<?php
/**
 * 服务端算术验证码：生成、校验、一次性消费。
 * 即使接口被脚本批量调用，也需先通过验证码，提高批量枚举成本。
 */
class Captcha
{
    public function __construct(private PDO $pdo) {}

    /** @return array{id:string, question:string} */
    public function issue(string $ip): array
    {
        $a = random_int(1, 9);
        $b = random_int(1, 9);
        $ops = ['+', '-'];
        $op = $ops[array_rand($ops)];
        if ($op === '-' && $b > $a) [$a, $b] = [$b, $a];
        $answer = (string)($op === '+' ? $a + $b : $a - $b);

        $id = bin2hex(random_bytes(16));
        $this->pdo->prepare('INSERT INTO captchas (id, answer, expires_at, ip, created_at)
            VALUES (?, ?, ?, ?, ?)')
            ->execute([$id, $answer, time() + CAPTCHA_TTL, $ip, time()]);
        $this->gc();
        return ['id' => $id, 'question' => sprintf('%d %s %d = ?', $a, $op, $b)];
    }

    /**
     * 校验验证码。一次性使用：无论成功失败，本次 id 立即作废，防止重放与逐个尝试。
     */
    public function verify(string $id, string $answer, string $ip): bool
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || $answer === '') {
            return false;
        }
        $stmt = $this->pdo->prepare('SELECT answer, expires_at, used, ip FROM captchas WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row || (int)$row['used'] === 1 || $row['expires_at'] < time()) {
            return false;
        }
        // 验证码与获取时的 IP 绑定，降低泄露后被他人利用的可能
        if (!hash_equals($row['ip'], $ip)) {
            return false;
        }
        $this->pdo->prepare('UPDATE captchas SET used = 1 WHERE id = ?')->execute([$id]);
        return hash_equals($row['answer'], trim($answer));
    }

    private function gc(): void
    {
        if (random_int(1, 20) === 1) {
            $this->pdo->prepare('DELETE FROM captchas WHERE expires_at < ? OR used = 1')->execute([time() - 60]);
        }
    }
}
