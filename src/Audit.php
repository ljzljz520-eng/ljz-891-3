<?php
declare(strict_types=1);

/** 后台操作审计 */
final class Audit
{
    public static function log(?array $admin, string $action, string $target = '', string $detail = ''): void
    {
        DB::run(
            'INSERT INTO audit_logs (admin_id, username, action, target, detail, ip)
             VALUES (:aid, :u, :a, :t, :d, :ip)',
            [
                'aid' => $admin['id'] ?? null,
                'u'   => $admin['username'] ?? '',
                'a'   => $action,
                't'   => mb_substr($target, 0, 64),
                'd'   => mb_substr($detail, 0, 1000),
                'ip'  => Req::ip(),
            ]
        );
    }
}
