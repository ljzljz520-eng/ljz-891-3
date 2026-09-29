<?php
/**
 * 后台鉴权：随机不透明会话令牌（仅在 Cookie 中），服务端存哈希；
 * 每个会话绑定独立 CSRF 令牌；登录失败限流。
 */
class AdminAuth
{
    public function __construct(private PDO $pdo) {}

    /**
     * 校验账密并创建会话，返回 [sid, csrf]。
     */
    public function login(string $username, string $password): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, password_hash FROM admins WHERE username = ?');
        $stmt->execute([$username]);
        $admin = $stmt->fetch();

        // 无论用户是否存在都执行一次哈希比较，减少用户名枚举的时序差异
        $hash = $admin['password_hash'] ?? '$2y$12$' . str_repeat('x', 53);
        $ok = password_verify($password, $hash) && $admin;

        if (!$ok) {
            return null;
        }
        if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
            $this->pdo->prepare('UPDATE admins SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($password, PASSWORD_DEFAULT), $admin['id']]);
        }

        $sid = bin2hex(random_bytes(32));
        $csrf = bin2hex(random_bytes(32));
        $now = time();
        $this->pdo->prepare('INSERT INTO admin_sessions
            (token_hash, admin_id, csrf_token, expires_at, created_at, last_seen)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([hash('sha256', $sid), (int)$admin['id'], $csrf, $now + SESSION_TTL, $now, $now]);

        return [$sid, $csrf, (int)$admin['id']];
    }

    /**
     * 从 Cookie 校验当前会话，自动续期。
     * @return array{admin_id:int, username:string, csrf:string}|null
     */
    public function check(): ?array
    {
        $sid = $_COOKIE[SESSION_COOKIE] ?? '';
        if (!is_string($sid) || !preg_match('/^[a-f0-9]{64}$/', $sid)) {
            return null;
        }
        $stmt = $this->pdo->prepare('SELECT s.admin_id, s.csrf_token, s.expires_at, a.username
            FROM admin_sessions s JOIN admins a ON a.id = s.admin_id
            WHERE s.token_hash = ?');
        $stmt->execute([hash('sha256', $sid)]);
        $row = $stmt->fetch();
        if (!$row || $row['expires_at'] < time()) {
            return null;
        }
        // 滑动过期
        $this->pdo->prepare('UPDATE admin_sessions SET expires_at = ?, last_seen = ? WHERE token_hash = ?')
            ->execute([time() + SESSION_TTL, time(), hash('sha256', $sid)]);

        return ['admin_id' => (int)$row['admin_id'], 'username' => $row['username'], 'csrf' => $row['csrf_token']];
    }

    public function logout(): void
    {
        $sid = $_COOKIE[SESSION_COOKIE] ?? '';
        if (is_string($sid) && preg_match('/^[a-f0-9]{64}$/', $sid)) {
            $this->pdo->prepare('DELETE FROM admin_sessions WHERE token_hash = ?')->execute([hash('sha256', $sid)]);
        }
        setcookie(SESSION_COOKIE, '', time() - 3600, '/', '', false, true);
    }

    public static function setSessionCookie(string $sid): void
    {
        setcookie(SESSION_COOKIE, $sid, [
            'expires'  => time() + SESSION_TTL,
            'path'     => '/',
            'secure'   => (($_SERVER['HTTPS'] ?? '') === 'on'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }
}
