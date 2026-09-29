<?php
declare(strict_types=1);

/** 后台账号与令牌（Bearer Token，服务端可吊销，滑动过期） */
final class AdminAuth
{
    private static string $dummyHash = '';

    private static function dummy(): string
    {
        if (self::$dummyHash === '') {
            self::$dummyHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        }
        return self::$dummyHash;
    }

    /**
     * 校验账号密码；账号不存在时也执行一次 password_verify 防时序探测。
     * @return array{id:int,username:string,display_name:string}|null
     */
    public static function verify(string $username, string $password): ?array
    {
        $admin = DB::one('SELECT * FROM admins WHERE username = :u', ['u' => $username]);
        if ($admin === null) {
            password_verify($password, self::dummy());
            return null;
        }
        if (!password_verify($password, $admin['password_hash'])) {
            return null;
        }
        // 旧哈希自动升级
        if (password_needs_rehash($admin['password_hash'], PASSWORD_DEFAULT)) {
            DB::run('UPDATE admins SET password_hash = :h WHERE id = :id',
                ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $admin['id']]);
        }
        return [
            'id'           => (int)$admin['id'],
            'username'     => $admin['username'],
            'display_name' => $admin['display_name'],
        ];
    }

    public static function issue(int $adminId): string
    {
        $token = Sec::token64();
        $ttl = (int)Cfg::get('admin_session_ttl', 43200);
        DB::run(
            'INSERT INTO admin_tokens (admin_id, token_hash, ip, user_agent, expires_at)
             VALUES (:a, :t, :ip, :ua, datetime(:exp, "unixepoch"))',
            [
                'a'   => $adminId,
                't'   => Sec::sha256($token),
                'ip'  => Req::ip(),
                'ua'  => Req::ua(),
                'exp' => time() + $ttl,
            ]
        );
        return $token;
    }

    /**
     * 校验当前请求令牌，顺带滑动续期。
     * @return array{id:int,username:string,display_name:string}|null
     */
    public static function current(): ?array
    {
        $token = Req::bearer();
        if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        $row = DB::one(
            'SELECT t.id AS token_id, t.expires_at, a.id AS admin_id, a.username, a.display_name
             FROM admin_tokens t JOIN admins a ON a.id = t.admin_id
             WHERE t.token_hash = :h AND t.revoked = 0',
            ['h' => Sec::sha256($token)]
        );
        if ($row === null) {
            return null;
        }
        if (strtotime($row['expires_at']) < time()) {
            return null;
        }
        $ttl = (int)Cfg::get('admin_session_ttl', 43200);
        DB::run('UPDATE admin_tokens SET last_used = datetime("now"), expires_at = datetime(:exp, "unixepoch") WHERE id = :id',
            ['exp' => time() + $ttl, 'id' => $row['token_id']]);
        return [
            'id'           => (int)$row['admin_id'],
            'username'     => $row['username'],
            'display_name' => $row['display_name'],
        ];
    }

    public static function revoke(): void
    {
        $token = Req::bearer();
        if ($token !== '') {
            DB::run('UPDATE admin_tokens SET revoked = 1 WHERE token_hash = :h',
                ['h' => Sec::sha256($token)]);
        }
    }
}
